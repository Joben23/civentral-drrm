"""Internal shared-key authentication without exposing secrets."""

from __future__ import annotations

from hmac import compare_digest

from fastapi import Header

from .config import Settings
from .errors import ApiError


class InternalAuthenticator:
    """Authenticate server-to-server calls with an environment-provided key."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    async def __call__(
        self,
        provided_key: str | None = Header(
            default=None,
            alias="X-CIVENTRAL-AI-Key",
            include_in_schema=False,
        ),
    ) -> None:
        if not self._settings.require_internal_auth:
            return

        configured = self._settings.internal_key
        if configured is None:
            raise ApiError(
                503,
                "INTERNAL_AUTH_NOT_CONFIGURED",
                "Internal service authentication is not configured.",
            )

        if provided_key is None or not compare_digest(
            provided_key.encode("utf-8"),
            configured.get_secret_value().encode("utf-8"),
        ):
            raise ApiError(
                401,
                "UNAUTHORIZED",
                "Valid internal service authentication is required.",
            )
