"""Environment-only configuration for the private inference service."""

from __future__ import annotations

from ipaddress import ip_address
from pathlib import Path
from typing import Literal

from pydantic import Field, SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


SERVICE_ROOT = Path(__file__).resolve().parents[1]
FLOOD_RISK_ROOT = SERVICE_ROOT.parent
REPOSITORY_ROOT = FLOOD_RISK_ROOT.parents[1]


class Settings(BaseSettings):
    """Runtime settings loaded from process environment or the local .env file."""

    model_config = SettingsConfigDict(
        env_prefix="CIVENTRAL_AI_",
        env_file=SERVICE_ROOT / ".env",
        env_file_encoding="utf-8",
        env_ignore_empty=True,
        extra="ignore",
        case_sensitive=False,
    )

    host: str = "127.0.0.1"
    port: int = Field(default=8098, ge=1, le=65535)
    allow_public_bind: bool = False

    require_internal_auth: bool = True
    internal_key: SecretStr | None = None

    artifact_root: Path = FLOOD_RISK_ROOT / "artifacts"
    model_path: Path | None = None
    model_manifest_path: Path | None = None
    risk_policy_path: Path | None = None
    feature_schema_path: Path = (
        FLOOD_RISK_ROOT / "schemas" / "flood-feature-schema-v1.json"
    )
    barangay_reference_path: Path = (
        REPOSITORY_ROOT
        / "data"
        / "import"
        / "caloocan-barangays-current-unaffected.geojson"
    )

    max_request_bytes: int = Field(default=16_384, ge=1_024, le=1_048_576)
    enable_docs: bool = False
    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"] = "INFO"

    @field_validator("host")
    @classmethod
    def validate_host(cls, value: str) -> str:
        host = value.strip()
        if not host:
            raise ValueError("host cannot be empty")
        return host

    @field_validator("internal_key")
    @classmethod
    def validate_internal_key(cls, value: SecretStr | None) -> SecretStr | None:
        if value is not None and len(value.get_secret_value()) < 32:
            raise ValueError("internal key must contain at least 32 characters")
        return value

    @model_validator(mode="after")
    def enforce_safe_bind_default(self) -> "Settings":
        if self.allow_public_bind:
            return self

        if self.host.lower() == "localhost":
            return self

        try:
            address = ip_address(self.host)
        except ValueError as exc:
            raise ValueError(
                "host must be localhost or a loopback address unless "
                "CIVENTRAL_AI_ALLOW_PUBLIC_BIND is explicitly enabled"
            ) from exc

        if not address.is_loopback:
            raise ValueError(
                "non-loopback binding requires CIVENTRAL_AI_ALLOW_PUBLIC_BIND=true"
            )
        return self

    @property
    def internal_auth_configured(self) -> bool:
        return self.internal_key is not None
