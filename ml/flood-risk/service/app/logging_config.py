"""Sanitized structured request logging."""

from __future__ import annotations

import json
import logging
import re
import time
import uuid
from datetime import datetime, timezone

from fastapi import Request
from starlette.middleware.base import BaseHTTPMiddleware, RequestResponseEndpoint
from starlette.responses import JSONResponse, Response


REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9._:-]{1,128}$")


def configure_logging(level: str) -> None:
    logging.basicConfig(level=getattr(logging, level), format="%(message)s")


class SanitizedRequestLoggingMiddleware(BaseHTTPMiddleware):
    """Log identifiers and outcomes, never headers, secrets, or request bodies."""

    def __init__(self, app: object, *, max_request_bytes: int) -> None:
        super().__init__(app)  # type: ignore[arg-type]
        self._max_request_bytes = max_request_bytes
        self._logger = logging.getLogger("civentral.ai.requests")

    async def dispatch(
        self, request: Request, call_next: RequestResponseEndpoint
    ) -> Response:
        request_id = request.headers.get("X-Request-ID", "")
        if not REQUEST_ID_PATTERN.fullmatch(request_id):
            request_id = uuid.uuid4().hex
        request.state.request_id = request_id

        content_length = request.headers.get("content-length")
        if content_length is not None:
            try:
                too_large = int(content_length) > self._max_request_bytes
            except ValueError:
                too_large = True
            if too_large:
                response = JSONResponse(
                    status_code=413,
                    content={
                        "success": False,
                        "code": "REQUEST_TOO_LARGE",
                        "message": "Request body exceeds the internal service limit.",
                    },
                )
                response.headers["X-Request-ID"] = request_id
                self._write_log(request, response.status_code, request_id, 0.0)
                return response

        started = time.perf_counter()
        error_class: str | None = None
        try:
            response = await call_next(request)
        except Exception as exc:
            error_class = type(exc).__name__
            raise
        finally:
            if error_class is not None:
                elapsed = (time.perf_counter() - started) * 1000
                self._write_log(request, 500, request_id, elapsed, error_class)

        response.headers["X-Request-ID"] = request_id
        elapsed = (time.perf_counter() - started) * 1000
        self._write_log(request, response.status_code, request_id, elapsed)
        return response

    def _write_log(
        self,
        request: Request,
        status_code: int,
        request_id: str,
        latency_ms: float,
        error_class: str | None = None,
    ) -> None:
        record = {
            "event": "internal_api_request",
            "request_timestamp": datetime.now(timezone.utc)
            .isoformat()
            .replace("+00:00", "Z"),
            "request_id": request_id,
            "endpoint": request.url.path,
            "method": request.method,
            "status_code": status_code,
            "latency_ms": round(latency_ms, 3),
        }
        monitored_request_id = getattr(request.state, "request_id", None)
        if isinstance(monitored_request_id, str) and REQUEST_ID_PATTERN.fullmatch(
            monitored_request_id
        ):
            record["request_id"] = monitored_request_id
        model_version = getattr(request.state, "ai_model_version", None)
        if isinstance(model_version, str):
            record["model_version"] = model_version
        failure_code = getattr(request.state, "failure_code", None)
        if isinstance(failure_code, str):
            record["failure_code"] = failure_code
        record["success"] = status_code < 400
        self._logger.info(json.dumps(record, separators=(",", ":")))
