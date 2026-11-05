# syntax=docker/dockerfile:1.7
#
# OCR Flow worker — the container the app-store path ships.
#
# One image, two supported profiles:
#   INSTALL_GOOGLE=1 (default): tesseract + Cloud Vision. What the app store tag
#                               uses; both engines selectable at runtime.
#   INSTALL_GOOGLE=0          : local engines only, ~58 MB smaller, no cloud SDK.
#
# Everything the worker shells out to is baked in, and so are the two ONNX
# models (13 MB together) and the DejaVu font — the font is not cosmetic: it
# draws the invisible text layer, so a Latin-only fallback silently produces
# perfect-looking, unsearchable Russian scans.
#
# Nothing secret is baked. The worker secret arrives at runtime
# (`NC_OCR_WORKER_SECRET`, or a file in `$CREDENTIALS_DIRECTORY` once the app
# hands files over through the API instead of WebDAV).

FROM docker.io/library/python:3.14-slim

ARG DEBIAN_FRONTEND=noninteractive
ARG INSTALL_GOOGLE=1

# tesseract-ocr-osd is not optional: it is the orientation fallback when the
# ONNX orientation model is unavailable. poppler-utils provides pdftoppm,
# which renders every page the worker sends to an engine.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      tesseract-ocr \
      tesseract-ocr-eng \
      tesseract-ocr-rus \
      tesseract-ocr-osd \
      poppler-utils \
      qpdf \
      ghostscript \
      unpaper \
      fonts-dejavu-core \
      locales \
      curl \
 && rm -rf /var/lib/apt/lists/*

# Cyrillic filenames and OCR text both need a UTF-8 locale present; without it
# glibc falls back to ASCII and non-ASCII names break in surprising places.
RUN sed -i 's/^# *\(en_US\.UTF-8\)/\1/; s/^# *\(ru_RU\.UTF-8\)/\1/' /etc/locale.gen \
 && locale-gen
ENV LANG=en_US.UTF-8 \
    LC_ALL=en_US.UTF-8

# --- baked assets -----------------------------------------------------------
# Hashes pinned: a model that silently changes is a quality regression, not a
# build error, unless we make it one.
RUN set -eux; \
    mkdir -p /opt/ocrflow; \
    curl -fsSL -o /opt/ocrflow/mobilenet_v3_small_fp32.onnx \
      'https://huggingface.co/vlad-m-dev/mobilenet_v3_small_onnx_photo_doc/resolve/main/mobilenet_v3_small.onnx'; \
    echo '529a975577f98a6ebc706febf98904786874f0abb05d38ce69c40c140d8e175f  /opt/ocrflow/mobilenet_v3_small_fp32.onnx' | sha256sum -c -; \
    curl -fsSL -o /opt/ocrflow/pp_lcnet_x1_0_doc_ori.onnx \
      'https://huggingface.co/PaddleOcrNet/PaddleOcrNet-models/resolve/main/PP-LCNet_x1_0_doc_ori.onnx'; \
    echo 'd85b3185075afca1a83157f73eac2e52b598d72e9d47dd19cc4a2f3605e23e3f  /opt/ocrflow/pp_lcnet_x1_0_doc_ori.onnx' | sha256sum -c -; \
    cp /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf /opt/ocrflow/DejaVuSans.ttf; \
    test -s /opt/ocrflow/DejaVuSans.ttf

# --- worker -----------------------------------------------------------------
WORKDIR /app
COPY worker /app/worker

# `ocrmypdf` is NOT a worker dependency: the tesseract engine shells out to it,
# so it has to be installed here. Without it the local engine dies with
# "ocrmypdf: not found" only at OCR time — measured on the first image build.
RUN set -eux; \
    pip install --no-cache-dir /app/worker ocrmypdf; \
    if [ "$INSTALL_GOOGLE" = "1" ]; then pip install --no-cache-dir '/app/worker[google]'; fi; \
    python -c 'import nc_ocr_flow.health, nc_ocr_flow.runner; print("worker import ok")'; \
    ocrmypdf --version

ENV TESSDATA_PREFIX=/usr/share/tesseract-ocr/5/tessdata \
    NC_OCR_FONT_PATH=/opt/ocrflow/DejaVuSans.ttf \
    NC_OCR_MOBILENET_MODEL=/opt/ocrflow/mobilenet_v3_small_fp32.onnx \
    NC_OCR_ORIENTATION_MODEL=/opt/ocrflow/pp_lcnet_x1_0_doc_ori.onnx \
    NC_OCR_HEALTH_PORT=8080 \
    NC_OCR_IDLE_SECONDS=5 \
    NC_OCR_STATE_DIR=/state \
    PYTHONUNBUFFERED=1

RUN useradd --uid 1000 --create-home --shell /usr/sbin/nologin ocrflow \
 && mkdir -p /state \
 && chown -R ocrflow:ocrflow /state

USER 1000
EXPOSE 8080

# AppAPI polls /heartbeat too; this keeps plain Docker honest at the same time.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/heartbeat', timeout=4)"

ENTRYPOINT ["python", "-m", "nc_ocr_flow.runner"]
