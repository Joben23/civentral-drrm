FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_NO_CACHE_DIR=1 \
    CIVENTRAL_AI_HOST=0.0.0.0 \
    CIVENTRAL_AI_PORT=8098 \
    CIVENTRAL_AI_ALLOW_PUBLIC_BIND=true \
    CIVENTRAL_AI_REQUIRE_INTERNAL_AUTH=true

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libgomp1 \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system civentral \
    && useradd --system --gid civentral --create-home civentral

WORKDIR /opt/civentral/ml/flood-risk/service

COPY ml/flood-risk/service/requirements.txt \
     ml/flood-risk/service/requirements-tensorflow.txt ./

RUN python -m pip install --upgrade pip \
    && python -m pip install -r requirements-tensorflow.txt

# Preserve the repository-relative paths used by app.config without copying
# development environments, credentials, tests, or ungoverned model files.
COPY --chown=civentral:civentral ml/flood-risk/service/app ./app
COPY --chown=civentral:civentral ml/flood-risk/service/schemas ./schemas
COPY --chown=civentral:civentral ml/flood-risk/schemas /opt/civentral/ml/flood-risk/schemas
COPY --chown=civentral:civentral ml/flood-risk/artifacts /opt/civentral/ml/flood-risk/artifacts
COPY --chown=civentral:civentral data/import/caloocan-barangays-current-unaffected.geojson \
     /opt/civentral/data/import/caloocan-barangays-current-unaffected.geojson

USER civentral

EXPOSE 8098

HEALTHCHECK --interval=30s --timeout=10s --start-period=90s --retries=5 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8098/health

CMD exec uvicorn app.main:app --host 0.0.0.0 --port 8098
