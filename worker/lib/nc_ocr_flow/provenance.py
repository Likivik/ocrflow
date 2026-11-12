"""Who wrote this PDF's text layer: ours, native, suspected foreign, or nobody.

Read-only provenance classification (plan §2 and §6 of the 2026-11-12
outcomes plan). The split "pre-ocrflow vs post-ocrflow" lives in the file
itself — the stamp token in PDF Keywords — not in a DB row, so it survives a
restore and a move between instances.

The token vocabulary (first field = who wrote the text, the rest is
provenance):

    ocrflow/<stamp-version>/<engine>/<engine-version>   ours
    native/<producer>/<producer-version>                typed text, best effort
    foreign/<tool>/<tool-version>                       someone else's OCR

Legacy: 1,489 files on the live instance carry the old ``nc-ocr-flow``
keyword. The reader accepts it for one release and treats it as ours with an
unknown version — which is below any bar by definition. The writer emits only
the new form.

Caveat, stated: a re-saved PDF with its metadata stripped is
indistinguishable from typed text. This is a best-effort classification, not
a proof; the codes it produces are named accordingly
(``skip_ocred_by_suspected_foreign``, never a claim of certainty).

A classification pass is read-only and free. It must never trigger OCR.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path

log = logging.getLogger(__name__)

#: The new stamp token prefix. Everything after the first field is provenance.
STAMP_TOKEN_PREFIX = "ocrflow/"

#: The keyword every stamp written before the token existed carries. Read for
#: one release as "ours, version unknown"; never written again.
LEGACY_STAMP_KEYWORD = "nc-ocr-flow"

#: Substrings (lowercase) in Creator/Producer that mean "an OCR product made
#: this text layer". Deliberately short: every entry must be a tool that only
#: ever appears on OCR output, not on typed text (Word, LaTeX, Skia are NOT
#: here). A false "foreign" costs an operator triage; a missed one just means
#: the file reads as native.
OCR_TOOL_MARKERS: tuple[str, ...] = (
    "ocrmypdf",
    "abbyy",  # ABBYY FineReader / PDF Transformer
    "finereader",
    "tesseract",
    "clearscan",  # Adobe's OCR
    "cvision",  # CVision PdfCompressor
    "expervision",
    "luratech",
    "iris",  # IRIS/Readiris
    "textbridge",
)

#: A page with fewer extractable characters than this is treated as having no
#: text layer: the objects may be a stamp or a watermark, not prose. Mirrors
#: the text-density guard the classifier used before this module existed.
MIN_TEXT_CHARS_PER_PAGE = 25

#: Outcome codes this module can produce (the app's closed vocabulary).
CODE_NEEDS_OCR = "needs_ocr"
CODE_SKIP_TEXT_NATIVE = "skip_text_native"
CODE_SKIP_OCRD_BY_OCRFLOW = "skip_ocred_by_ocrflow"
CODE_SKIP_SUSPECTED_FOREIGN = "skip_ocred_by_suspected_foreign"


@dataclass(frozen=True)
class StampToken:
    """A parsed ``ocrflow/<ver>/<engine>/<engine-version>`` token."""

    engine: str
    engine_version: str
    stamp_version: str
    #: True for the legacy ``nc-ocr-flow`` keyword: ours, version unknown.
    legacy: bool = False


def build_stamp_token(stamp_version: str, engine: str, engine_version: str) -> str:
    """The keyword the writer emits. One form, forward only."""
    return f"{STAMP_TOKEN_PREFIX}{stamp_version}/{engine}/{engine_version}"


def parse_stamp_token(keywords: str | list[str] | None) -> StampToken | None:
    """Parse the stamp token out of a PDF ``Keywords`` value.

    Accepts the comma-separated string PyMuPDF hands back, or a list. Returns
    ``None`` for absent or malformed tokens — never raises, never guesses:
    a malformed token is not "ours with a wrong version", it is no token.
    """
    if not keywords:
        return None
    if isinstance(keywords, list):
        terms = [str(k) for k in keywords]
    else:
        terms = [t.strip() for t in str(keywords).split(",")]
    terms = [t for t in terms if t]

    for term in terms:
        if not term.startswith(STAMP_TOKEN_PREFIX):
            continue
        rest = term[len(STAMP_TOKEN_PREFIX) :]
        parts = rest.split("/")
        if len(parts) != 3:
            continue
        stamp_version, engine, engine_version = (p.strip() for p in parts)
        if not stamp_version or not engine or not engine_version:
            continue
        return StampToken(
            engine=engine,
            engine_version=engine_version,
            stamp_version=stamp_version,
        )

    # Legacy: the keyword alone means "ours", and nothing more specific.
    if LEGACY_STAMP_KEYWORD in terms:
        return StampToken(engine="", engine_version="", stamp_version="", legacy=True)
    return None


def _version_key(version: str) -> tuple[int, ...]:
    """A version string as a numeric tuple, for comparison.

    ``3.15.0`` -> ``(3, 15, 0)``. Non-numeric fragments are dropped rather
    than guessed at; a missing part is a zero, so ``3.15`` == ``3.15.0``.
    """
    parts: list[int] = []
    for chunk in version.strip().split("."):
        digits = "".join(c for c in chunk if c.isdigit())
        parts.append(int(digits) if digits else 0)
    return tuple(parts)


def engine_version_at_or_above(version: str | None, bar: str) -> bool:
    """Numeric version comparison: is ``version`` at or above ``bar``?

    A missing or empty version is below the bar: "unknown" can never be
    proven good. Comparison is numeric per dot, never lexicographic —
    ``3.15.0`` > ``3.9.0`` because 15 > 9, not because of string order.
    """
    if not version or not version.strip():
        return False
    ours = _version_key(version)
    floor = _version_key(bar)
    width = max(len(ours), len(floor))
    ours += (0,) * (width - len(ours))
    floor += (0,) * (width - len(floor))
    return ours >= floor


@dataclass(frozen=True)
class LayerOrigin:
    """Who wrote the text layer of one PDF."""

    #: ``ours`` | ``native`` | ``suspected_foreign`` | ``none``
    origin: str
    #: For ``ours``: the engine from the stamp (empty when legacy/unknown).
    engine: str = ""
    #: For ``ours``: the engine version from the stamp (empty when unknown).
    engine_version: str = ""
    #: For ``ours``: the stamp version (empty when legacy).
    stamp_version: str = ""
    #: For ``native``/``suspected_foreign``: the best-effort provenance token.
    token: str = ""

    @property
    def below_bar(self) -> bool:
        """True when ours-but-unprovable: no version, or none at all."""
        return self.origin == "ours" and (not self.engine or not self.engine_version)


def _producer_token(producer: str, creator: str) -> str:
    """``native/<producer>/<version>`` or ``foreign/<tool>/<version>``, best effort.

    Producers lie or are generic; when unknown, the token ends ``/unknown``.
    The code decides; the token informs a human.
    """
    source = producer or creator or ""
    if not source:
        return "native/unknown/unknown"
    # Strip a trailing "version-ish" tail so Word 2016 -> producer Word, ver 2016.
    parts = source.replace("/", " ").split()
    if len(parts) >= 2:
        return f"native/{parts[0]}/{parts[1]}"
    return f"native/{source}/unknown"


def _text_is_invisible_under_image(page) -> bool:
    """Text drawn in render mode 3 (invisible) while the page carries an image.

    The classic foreign-OCR shape: the page is one raster image with a text
    layer hidden underneath for search. Best effort — a stripped or exotic
    writer can hide this; then the file reads as whatever the metadata says.
    """
    try:
        traces = page.get_texttrace()
    except Exception:
        return False
    # PyMuPDF span "type": 0 fill, 1 stroke, 2 fill+stroke, 3 invisible.
    has_invisible = any(bool(t.get("type") == 3) for t in traces)
    if not has_invisible:
        return False
    try:
        return bool(page.get_images(full=True))
    except Exception:
        return False


def ocr_layer_origin(path: str | Path) -> LayerOrigin:
    """Classify who wrote the text layer. Read-only; never triggers OCR.

    Rules (plan §6, first match wins):

    1. Stamp token present (new form, or the legacy keyword) → ``ours``,
       carrying ``(engine, engine_version, stamp_version)`` — empty for the
       legacy keyword, which means "ours, version unknown" and is therefore
       below any bar.
    2. No token, but an OCR trace: Creator/Producer names an OCR product, or
       invisible text is drawn under a page image → ``suspected_foreign``.
    3. A text layer with no trace → ``native`` (best-effort
       ``native/<producer>/<version>``).
    4. No text layer — a bare raster document, or a blank page → ``none``:
       this is work (``needs_ocr``), not a verdict.
    """
    import pymupdf

    try:
        doc = pymupdf.open(str(path))
    except Exception as exc:
        log.warning("provenance: cannot open %s (%s); treating as no layer", path, exc)
        return LayerOrigin(origin="none")

    try:
        meta = doc.metadata or {}
        keywords = meta.get("keywords") or ""
        producer = (meta.get("producer") or "").strip()
        creator = (meta.get("creator") or "").strip()

        token = parse_stamp_token(keywords)
        if token is not None:
            return LayerOrigin(
                origin="ours",
                engine=token.engine,
                engine_version=token.engine_version,
                stamp_version=token.stamp_version,
                token=f"{STAMP_TOKEN_PREFIX}{token.stamp_version}/{token.engine}/{token.engine_version}".rstrip(
                    "/"
                ),
            )

        marker_hit = next(
            (m for m in OCR_TOOL_MARKERS if m in producer.lower() or m in creator.lower()),
            None,
        )
        invisible_foreign = any(_text_is_invisible_under_image(page) for page in iter(doc))
        if marker_hit is not None or invisible_foreign:
            source = (producer or creator or "unknown").replace(" ", "-")[:80]
            return LayerOrigin(
                origin="suspected_foreign",
                token=f"foreign/{source}",
            )

        pages = max(1, doc.page_count)
        try:
            chars = sum(len(page.get_text().strip()) for page in iter(doc))
        except Exception:
            chars = 0
        if chars / pages >= MIN_TEXT_CHARS_PER_PAGE:
            return LayerOrigin(
                origin="native",
                token=_producer_token(producer, creator),
            )

        return LayerOrigin(origin="none")
    finally:
        doc.close()


def classify_text_layer(
    path: str | Path,
    accepted_engines: dict[str, str] | None,
) -> dict[str, object]:
    """Plan §6 as one decision: the code plus the provenance it rode in on.

    ``accepted_engines`` is the bar (engine -> minimum version). An engine
    absent from the map is never good enough; a missing version is below the
    bar. The returned ``code`` is the worker's verdict; ``below_bar`` marks a
    redo candidate the owner picks from — never an automatic re-OCR.
    """
    origin = ocr_layer_origin(path)
    verdict: dict[str, object] = {
        "origin": origin.origin,
        "engine": origin.engine or None,
        "engine_version": origin.engine_version or None,
        "stamp_version": origin.stamp_version or None,
        "token": origin.token or None,
        "below_bar": False,
    }

    if origin.origin == "ours":
        bar = (accepted_engines or {}).get(origin.engine) if origin.engine else None
        below = bar is None or not engine_version_at_or_above(origin.engine_version, bar)
        verdict["code"] = CODE_SKIP_OCRD_BY_OCRFLOW
        verdict["below_bar"] = below
        return verdict
    if origin.origin == "suspected_foreign":
        verdict["code"] = CODE_SKIP_SUSPECTED_FOREIGN
        return verdict
    if origin.origin == "native":
        verdict["code"] = CODE_SKIP_TEXT_NATIVE
        return verdict
    verdict["code"] = CODE_NEEDS_OCR
    return verdict
