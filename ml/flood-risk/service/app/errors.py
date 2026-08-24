"""Sanitized service error types."""

from __future__ import annotations


class ApiError(Exception):
    """An expected API failure safe to serialize to an internal caller."""

    def __init__(
        self,
        status_code: int,
        code: str,
        message: str,
        *,
        model_status: str | None = None,
    ) -> None:
        super().__init__(message)
        self.status_code = status_code
        self.code = code
        self.message = message
        self.model_status = model_status
