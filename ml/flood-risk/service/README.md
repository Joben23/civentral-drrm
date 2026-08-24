# CIVENTRAL private flood-risk inference service

This is the Phase 7D-A server-runtime foundation for future private
PHP-to-Python inference. It contains no model, prediction fixture, risk
thresholds, PHP integration, warning integration, or database persistence.

Current truthful behavior:

- `GET /health` returns 200 when the API process works.
- `GET /ready` returns 503 because no approved model/policy exists.
- authenticated `GET /v1/model/status` reports `MODEL_NOT_AVAILABLE`.
- authenticated `POST /v1/predictions/flood-risk` returns 503 and no
  probability, outcome, or risk level.

## Supported development runtime

Use **64-bit CPython 3.12** in a project virtual environment. TensorFlow 2.21.0
publishes native Windows CPU wheels for Python 3.10 through 3.13; Python 3.12 is
the selected common runtime for this service. Native Windows GPU TensorFlow is
not required. Production should use a private Linux container/VPS network.

At this audit, the workstation had only Microsoft Store command aliases named
`python` and `python3`; there was no installed interpreter, `py` launcher,
`pip`, or `uv`. Do not treat those aliases as a Python installation.

## Windows/XAMPP setup

Install official 64-bit Python 3.12 with the Python Launcher and `pip`, then
verify the selected interpreter explicitly:

```powershell
py -3.12 --version
py -3.12 -c "import struct; print(struct.calcsize('P') * 8)"
```

Create and activate a local virtual environment:

```powershell
cd D:\xampp\htdocs\civentral-drrm\ml\flood-risk\service
py -3.12 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -r requirements-dev.txt
Copy-Item .env.example .env
```

Replace the example internal key only in the ignored `.env`. One way to create
a local value is:

```powershell
[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
```

Start the service on the selected development port:

```powershell
uvicorn app.main:app --host 127.0.0.1 --port 8098
```

Do not expose port 8098 through Apache, browser JavaScript, or a public reverse
proxy. A production bind such as `0.0.0.0` requires the explicit
`CIVENTRAL_AI_ALLOW_PUBLIC_BIND=true` override and private network/firewall
controls.

## Dependencies

`requirements.txt` contains the light API runtime. TensorFlow is deliberately
separate so health diagnostics and contract tests do not force a large package
installation. When an approved artifact exists, install it in the same isolated
environment:

```powershell
python -m pip install -r requirements-tensorflow.txt
python -c "import tensorflow as tf; print(tf.__version__)"
```

The installed TensorFlow version must match the approved manifest. Never
install service dependencies into global/system Python.

## Configuration and security

Settings use `CIVENTRAL_AI_` environment variables. The service requires no
PHP session, Supabase, PAGASA, database, citizen, or employee credentials.
Protected endpoints use `X-CIVENTRAL-AI-Key` with constant-time comparison.
`/health` and `/ready` are unauthenticated private probes with sanitized state.

The API fails closed if authentication is required but no key is configured.
It adds no CORS middleware, rejects undeclared request fields, limits request
size, and logs only request ID, path, method, status, latency, and error class.
It never logs headers, keys, environment dumps, or request bodies.

## Artifact and policy lifecycle

The service never creates or discovers arbitrary models. Configured model,
manifest, preprocessing, and risk-policy paths must stay below the artifact
root. The loader validates:

1. manifest structure and approval metadata;
2. artifact name/location and SHA-256 checksum;
3. Phase 7B feature-schema version, order, and ten-value shape;
4. Python and TensorFlow runtime compatibility;
5. Keras input/output shape; and
6. operational approval state.

An unapproved artifact may be diagnosed as
`MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED`, but cannot run inference.
`MODEL_READY` requires explicit operational validation and approval.

TensorFlow produces a probability; a separate versioned and approved risk
policy produces CIVENTRAL categories and a binary display outcome. The current
policy state is `NOT_CONFIGURED`. No LOW/MODERATE/HIGH/CRITICAL or binary
threshold values are present.

No scaler is fitted at inference time. A future external fitted scaler must be
checksummed in the approved bundle, match the feature order and training
dataset hash, and is only applied as a transform. A model may instead contain
its trained preprocessing layers.

The schemas are in `schemas/`. They document the future artifact and policy
contracts without supplying any artifact, fitted statistics, or thresholds.

## Feature and API contracts

The runtime reads the Phase 7B `../schemas/flood-feature-schema-v1.json` file.
It validates a current Caloocan PSGC against the 187-boundary reference,
creates exactly one MGB one-hot category, regenerates month sine/cosine, and
emits the exact ten-value order. Missing rainfall is never imputed.

The future success response is defined by
`FutureFloodRiskPredictionResponse` in `app/schemas.py`. It can only be emitted
when both an approved loaded model and a compatible approved policy are ready.
The current endpoint cannot construct this response.

## Tests

```powershell
python -m pytest -q
```

Tests use request-only fixtures and absent-model state. They do not create a
trained TensorFlow artifact, fitted scaler, threshold policy, or mocked system
prediction.

## Future PHP integration

Phase 7E may implement a server-side client against these fail-closed
contracts. It must treat HTTP 503 as "prediction unavailable." No browser or
citizen app should call this service. The service has no early-warning write
capability; every later warning remains subject to DRRM officer review and
explicit authorized activation.
