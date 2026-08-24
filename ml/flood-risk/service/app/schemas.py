"""Strict request and response contracts for the internal API."""

from __future__ import annotations

from datetime import datetime, timedelta
from enum import Enum
from typing import Any, Literal

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, field_validator, model_validator


class ModelState(str, Enum):
    MODEL_NOT_AVAILABLE = "MODEL_NOT_AVAILABLE"
    MODEL_INVALID = "MODEL_INVALID"
    MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED = (
        "MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED"
    )
    MODEL_READY = "MODEL_READY"


class LocationInput(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    barangay_id: str = Field(pattern=r"^\d{10}$")


class FloodRiskFeatures(BaseModel):
    model_config = ConfigDict(extra="forbid", allow_inf_nan=False)

    forecast_rainfall_24h_mm: float = Field(ge=0)
    antecedent_rainfall_24h_mm: float = Field(ge=0)
    antecedent_rainfall_72h_mm: float = Field(ge=0)
    mgb_flood_susceptibility_code: Literal["LF", "MF", "HF", "VHF", "NONE"]
    month_sin: float = Field(ge=-1, le=1)
    month_cos: float = Field(ge=-1, le=1)

    @field_validator(
        "forecast_rainfall_24h_mm",
        "antecedent_rainfall_24h_mm",
        "antecedent_rainfall_72h_mm",
        "month_sin",
        "month_cos",
        mode="before",
    )
    @classmethod
    def require_json_number(cls, value: Any) -> Any:
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            raise ValueError("must be a JSON number")
        return value


class SourceContext(BaseModel):
    model_config = ConfigDict(extra="forbid")

    weather_issued_at: AwareDatetime
    feature_schema_version: Literal["1.0.0"]


class FloodRiskPredictionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    schema_version: Literal["1.0"]
    request_id: str = Field(min_length=1, max_length=128, pattern=r"^[A-Za-z0-9._:-]+$")
    prediction_type: Literal["FLOOD_WITHIN_24H"]
    valid_from: AwareDatetime
    valid_until: AwareDatetime
    location: LocationInput
    features: FloodRiskFeatures
    source_context: SourceContext

    @model_validator(mode="after")
    def validate_temporal_contract(self) -> "FloodRiskPredictionRequest":
        if self.valid_until - self.valid_from != timedelta(hours=24):
            raise ValueError("valid window must be exactly 24 hours")
        if self.source_context.weather_issued_at > self.valid_from:
            raise ValueError("weather_issued_at must not be after valid_from")
        return self


class ErrorResponse(BaseModel):
    success: Literal[False] = False
    code: str
    message: str
    model_status: ModelState | None = None


class HealthResponse(BaseModel):
    success: Literal[True] = True
    service_status: Literal["HEALTHY"] = "HEALTHY"
    service_version: str
    python_version: str
    tensorflow_installed: bool
    model_status: ModelState
    risk_policy_status: str
    checked_at: datetime


class ReadinessResponse(BaseModel):
    success: bool
    ready: bool
    code: str
    message: str
    model_status: ModelState
    risk_policy_status: str


class ModelStatusResponse(BaseModel):
    success: Literal[True] = True
    model_status: ModelState
    model_available: bool
    approved_for_inference: bool
    model_version: str | None
    model_declared_status: str | None
    feature_schema_version: str
    threshold_policy_version: str | None
    tensorflow_installed: bool
    tensorflow_version: str | None
    python_version: str
    message: str


class FutureFloodRiskPredictionResponse(BaseModel):
    """Documented success contract; emitted only by an approved ready runtime."""

    success: Literal[True] = True
    schema_version: Literal["1.0"] = "1.0"
    request_id: str
    prediction_type: Literal["FLOOD_WITHIN_24H"] = "FLOOD_WITHIN_24H"
    model_version: str
    model_status: Literal[ModelState.MODEL_READY]
    probability: float = Field(ge=0, le=1)
    predicted_outcome: Literal["FLOOD", "NO_FLOOD"]
    threshold_policy_version: str
    civentral_risk_level: Literal["LOW", "MODERATE", "HIGH", "CRITICAL"]
    predicted_at: datetime
    valid_from: datetime
    valid_until: datetime
    limitations: list[str]
