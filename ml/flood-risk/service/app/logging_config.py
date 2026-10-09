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
from starlette.types import ASGIApp, Message, Receive, Scope, Send


REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9._:-]{1,128}$")


class BoundedRequestBodyMiddleware:
    """Reject oversized bodies even when Content-Length is absent or false."""

    def __init__(self, app: ASGIApp, *, max_request_bytes: int) -> None:
        self._app = app
        self._max_request_bytes = max_request_bytes

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self._app(scope, receive, send)
            return

        content_length = self._content_length(scope)
        if content_length is None or content_length <= self._max_request_bytes:
            messages, too_large = await self._read_bounded(receive)
        else:
            messages, too_large = [], True

        if too_large:
            response = JSONResponse(
                status_code=413,
                content={
                    "success": False,
                    "code": "REQUEST_TOO_LARGE",
                    "message": "Request body exceeds the internal service limit.",
                },
            )
            await response(scope, receive, send)
            return

        message_index = 0

        async def replay_receive() -> Message:
            nonlocal message_index
            if message_index < len(messages):
                message = messages[message_index]
                message_index += 1
                return message
            return {"type": "http.request", "body": b"", "more_body": False}

        await self._app(scope, replay_receive, send)

    def _content_length(self, scope: Scope) -> int | None:
        for name, value in scope.get("headers", ()):
            if name.lower() != b"content-length":
                continue
            try:
                parsed = int(value.decode("ascii"))
            except (UnicodeDecodeError, ValueError):
                return self._max_request_bytes + 1
            return parsed if parsed >= 0 else self._max_request_bytes + 1
        return None

    async def _read_bounded(self, receive: Receive) -> tuple[list[Message], bool]:
        messages: list[Message] = []
        received = 0
        while True:
            message = await receive()
            messages.append(message)
            if message["type"] == "http.disconnect":
                return messages, False
            if message["type"] != "http.request":
                continue
            received += len(message.get("body", b""))
            if received > self._max_request_bytes:
                return [], True
            if not message.get("more_body", False):
                return messages, False


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
