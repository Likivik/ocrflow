"""Typed access to the worker's environment.

The worker used to parse its own configuration in three different ways, and
one of them was wrong in a way that shipped: ``NC_OCR_KEEP_SOURCE_IMAGE=off``
*kept* the source image, because that parser knew ``0``/``false``/``no``/``""``
as "off" and had never heard of ``off``.  A second call site
(``NC_OCR_STATE_STRICT``) accepted only ``1``/``true``/``yes``.  A third one
(``NC_OCR_CATCHUP``) used the inverse convention, where anything that was not
falsy was *on*.  Three conventions, one of them wrong, and no way to tell a
typo from an intent.

This module is the single place where the environment is parsed:

* the environment is produced by Nix, where ``toString true`` is ``"1"`` and
  ``toString false`` is ``""``;
* a boolean therefore accepts exactly ``"1"`` (true), ``"0"`` (false) and the
  empty string (false).  Anything else is an error: there is no tolerant
  producer, so a value outside that set is a typo, and a typo must never
  silently pick a branch;
* integers, floats and strings are parsed the same way — one type per
  variable, validated where the variable is read.

Every helper raises :class:`ConfigError` (a :class:`ValueError`) naming the
variable and the offending value, so the message says which knob is wrong
instead of leaving a bare stack trace in a log line.

The helpers are pure: they read ``os.environ`` and return a value.  They do
not mutate the environment and they have no import-time side effects, so they
can be imported from anywhere without changing behaviour.
"""

from __future__ import annotations

import os

__all__ = [
    "ConfigError",
    "env_flag",
    "env_float",
    "env_int",
    "env_str",
]


class ConfigError(ValueError):
    """An environment variable holds a value the worker cannot use.

    A :class:`ValueError` subclass so the existing ``except ValueError``
    handlers around integer parsing keep working, while callers that care
    about configuration can catch this one specifically.
    """


def env_flag(name: str, default: bool) -> bool:
    """Read a boolean environment variable.

    Accepts exactly what the Nix producer emits:

    * ``"1"`` — true
    * ``"0"`` — false
    * ``""`` — false (``toString false``)

    An *unset* variable yields ``default``.  An *empty* variable is an
    explicit false, not an absent one: ``toString false`` produces ``""``, so
    an empty value means the operator turned the switch off.  This is why
    ``env_flag("X", True)`` with ``X=""`` answers ``False``.

    Surrounding whitespace is ignored.  Anything else — ``"off"``,
    ``"true"``, ``"yes"``, ``"no"`` — raises :class:`ConfigError`: those are
    typos or hand-written values, and silently reading one of them as either
    branch is exactly how the source image was kept when it should have been
    trashed.
    """
    raw = os.environ.get(name)
    if raw is None:
        return default
    value = raw.strip()
    if value == "":
        return False
    if value == "1":
        return True
    if value == "0":
        return False
    raise ConfigError(f"{name}={raw!r} is not a valid boolean; use '1' (true) or '0'/empty (false)")


def env_int(name: str, default: int) -> int:
    """Read an integer environment variable.

    An unset or empty variable yields ``default``: Nix never emits an empty
    integer, so an empty one means "not configured".  A value that is not an
    integer raises :class:`ConfigError` naming the variable and the value.
    """
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        return int(raw.strip())
    except ValueError:
        raise ConfigError(f"{name}={raw!r} is not a valid integer") from None


def env_float(name: str, default: float) -> float:
    """Read a float environment variable.

    An unset or empty variable yields ``default``: Nix never emits an empty
    float, so an empty one means "not configured".  A value that is not a
    float raises :class:`ConfigError` naming the variable and the value.
    """
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        return float(raw.strip())
    except ValueError:
        raise ConfigError(f"{name}={raw!r} is not a valid number") from None


def env_str(name: str, default: str) -> str:
    """Read a string environment variable.

    An *unset* variable yields ``default``.  An *empty* variable yields the
    empty string, not the default: for a string, empty is a value the
    operator can mean ("no URL", "no bucket"), and rewriting it to the
    default would silently point the worker somewhere else.  The call sites
    that want "empty means unset" say so themselves (``env_str(x, "") or
    env_str(y, "")``), the same way they did before.

    The value is returned verbatim — no stripping, because a path or a secret
    with a trailing space is the operator's business, not ours to rewrite.
    """
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw
