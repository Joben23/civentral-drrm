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


class DataProvenanceItem(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    role: Literal[
        "WEATHER_FORECAST",
        "RAINFALL_OBSERVATION",
        "FLOOD_SUSCEPTIBILITY",
        "BARANGAY_REFERENCE",
    ]
    source_id: str = Field(
        min_length=1,
        max_length=128,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]*$",
    )
    source_version: str = Field(
        min_length=1,
        max_length=128,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]*$",
    )
    reference_time: AwareDatetime | None = None


class SourceContext(BaseModel):
    model_config = ConfigDict(extra="forbid")

    weather_issued_at: AwareDatetime
    feature_schema_version: Literal["1.0.0"]
    data_provenance: list[DataProvenanceItem] = Field(min_length=4, max_length=4)

    @model_validator(mode="after")
    def validate_provenance(self) -> "SourceContext":
        expected = {
            "WEATHER_FORECAST",
            "RAINFALL_OBSERVATION",
            "FLOOD_SUSCEPTIBILITY",
            "BARANGAY_REFERENCE",
        }
        roles = [item.role for item in self.data_provenance]
        if len(set(roles)) != len(roles) or set(roles) != expected:
            raise ValueError("one provenance item is required for each input source role")
        weather = next(
            item for item in self.data_provenance if item.role == "WEATHER_FORECAST"
        )
        if weather.reference_time != self.weather_issued_at:
            raise ValueError("weather provenance must match weather_issued_at")
        return self


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
        if any(
            item.reference_time is not None and item.reference_time > self.valid_from
            for item in self.source_context.data_provenance
        ):
            raise ValueError("input provenance must not postdate valid_from")
        return self


class ErrorResponse(BaseModel):
    success: Literal[False] = False
    code: str
    message: str
    model_status: ModelState | None = None


class HealthResponse(BaseModel):
    success: Literal[True] = True
    service_status: Literal["HEALTHY"] = "HEALTHY"
    service_alive: Literal[True] = True
    service_version: str
    python_version: str
    tensorflow_runtime_available: bool
    checked_at: datetime


class ReadinessResponse(BaseModel):
    success: bool
    ready: bool
    task_type: Literal["FLOOD_RISK_CLASSIFIER"] = "FLOOD_RISK_CLASSIFIER"
    code: str
    message: str
    tensorflow_runtime_available: bool
    tensorflow_runtime_ready: bool
    flood_risk_model_ready: bool
    threshold_policy_ready: bool
    model_inference_ready: bool
    trusted_inputs_ready: None = None
    input_readiness_scope: Literal["PER_REQUEST"] = "PER_REQUEST"
    prediction_ready: bool
    model_status: ModelState
    risk_policy_status: str


class ModelStatusResponse(BaseModel):
    success: Literal[True] = True
    task_type: Literal["FLOOD_RISK_CLASSIFIER"] = "FLOOD_RISK_CLASSIFIER"
    model_status: ModelState
    model_available: bool
    flood_risk_model_ready: bool
    threshold_policy_ready: bool
    model_inference_ready: bool
    trusted_inputs_ready: None = None
    input_readiness_scope: Literal["PER_REQUEST"] = "PER_REQUEST"
    approved_for_inference: bool
    model_version: str | None
    model_declared_status: str | None
    input_schema_version: str
    threshold_policy_version: str | None
    tensorflow_runtime_available: bool
    tensorflow_runtime_ready: bool
    tensorflow_version: str | None
    python_version: str
    message: str


class FutureFloodRiskPredictionResponse(BaseModel):
    """Documented success contract; emitted only by an approved ready runtime."""

    success: Literal[True] = True
    schema_version: Literal["1.0"] = "1.0"
    request_id: str
    task_type: Literal["FLOOD_RISK_CLASSIFIER"] = "FLOOD_RISK_CLASSIFIER"
    prediction_type: Literal["FLOOD_WITHIN_24H"] = "FLOOD_WITHIN_24H"
    model_version: str
    model_status: Literal[ModelState.MODEL_READY]
    input_schema_version: Literal["1.0.0"] = "1.0.0"
    barangay_id: str = Field(pattern=r"^\d{10}$")
    probability: float = Field(ge=0, le=1)
    predicted_outcome: Literal["FLOOD", "NO_FLOOD"]
    threshold_policy_version: str
    civentral_risk_level: Literal["LOW", "MODERATE", "HIGH", "CRITICAL"]
    predicted_at: datetime
    valid_from: datetime
    valid_until: datetime
    data_provenance: list[DataProvenanceItem]
    limitations: list[str]
    decision_support: Literal[True] = True


class RainfallHistoryObservation(BaseModel):
    model_config = ConfigDict(extra="forbid")

    timestamp_utc: Any
    city_mean_precipitation_mm: Any


class RainfallPredictionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    schema_version: Literal["1.0"]
    request_id: str = Field(
        min_length=1, max_length=128, pattern=r"^[A-Za-z0-9._:-]+$"
    )
    history: list[RainfallHistoryObservation]


class RainfallPredictionResponse(BaseModel):
    schema_version: Literal["1.0"] = "1.0"
    request_id: str
    model_problem: Literal["RAINFALL_REGRESSION"]
    task_type: Literal["RAINFALL_REGRESSION"] = "RAINFALL_REGRESSION"
    output_type: Literal["RAINFALL_AMOUNT_MM"] = "RAINFALL_AMOUNT_MM"
    forecast_origin_utc: str
    forecast_horizon_hours: Literal[3]
    target: Literal["NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM"]
    raw_prediction_mm: float = Field(ge=0, allow_inf_nan=False)
    final_prediction_mm: float = Field(ge=0, allow_inf_nan=False)
    output_policy: Literal["MODEL_NONNEGATIVE_SOFTPLUS"]
    model_version: Literal[
        "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
    ]
    model_status: Literal["VALIDATED_RESEARCH_CANDIDATE"]
    research_only: Literal[True] = True
    flood_risk_output: Literal[False] = False
    decision_support: Literal[True] = True
    operational: Literal[False]


class RainfallReadinessResponse(BaseModel):
    success: bool
    ready: bool
    code: str
    message: str
    model_problem: Literal["RAINFALL_REGRESSION"]
    task_type: Literal["RAINFALL_REGRESSION"] = "RAINFALL_REGRESSION"
    output_type: Literal["RAINFALL_AMOUNT_MM"] = "RAINFALL_AMOUNT_MM"
    rainfall_model_ready: bool
    model_version: str | None
    model_status: str | None
    authorization_status: str
    research_only: Literal[True] = True
    operational: Literal[False] = False
