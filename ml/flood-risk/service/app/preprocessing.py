"""Training-serving feature parity and approved inference-only transforms."""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from pathlib import Path
from threading import Lock
from typing import Any, Mapping

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from .schemas import FloodRiskPredictionRequest


EXPECTED_FEATURE_SCHEMA_VERSION = "1.0.0"
EXPECTED_FEATURE_ORDER = (
    "forecast_rainfall_24h_mm",
    "antecedent_rainfall_24h_mm",
    "antecedent_rainfall_72h_mm",
    "mgb_susceptibility_LF",
    "mgb_susceptibility_MF",
    "mgb_susceptibility_HF",
    "mgb_susceptibility_VHF",
    "mgb_susceptibility_NONE",
    "month_sin",
    "month_cos",
)
SUSCEPTIBILITY_CODES = ("LF", "MF", "HF", "VHF", "NONE")


class FeatureContractError(ValueError):
    """Raised when request features cannot satisfy the governed feature schema."""


@dataclass(frozen=True)
class FeatureVector:
    names: tuple[str, ...]
    values: tuple[float, ...]


class ApprovedStandardScaler(BaseModel):
    """A fitted preprocessing artifact; this service never derives these values."""

    model_config = ConfigDict(extra="forbid", allow_inf_nan=False)

    preprocessing_schema_version: str = Field(pattern=r"^1\.0$")
    method: str = Field(pattern=r"^STANDARD_SCALER$")
    feature_schema_version: str = Field(pattern=r"^1\.0\.0$")
    training_dataset_hash: str = Field(pattern=r"^[a-f0-9]{64}$")
    feature_order: tuple[str, ...]
    means: tuple[float, ...]
    scales: tuple[float, ...]

    @field_validator("feature_order")
    @classmethod
    def validate_feature_order(cls, value: tuple[str, ...]) -> tuple[str, ...]:
        if value != EXPECTED_FEATURE_ORDER:
            raise ValueError("preprocessing feature order is incompatible")
        return value

    @model_validator(mode="after")
    def validate_parameters(self) -> "ApprovedStandardScaler":
        expected = len(EXPECTED_FEATURE_ORDER)
        if len(self.means) != expected or len(self.scales) != expected:
            raise ValueError("preprocessing parameter count must equal input shape 10")
        if any(scale <= 0 for scale in self.scales):
            raise ValueError("preprocessing scales must be greater than zero")
        return self

    def transform(self, values: tuple[float, ...]) -> tuple[float, ...]:
        if len(values) != len(self.means):
            raise FeatureContractError("feature vector length does not match preprocessing")
        transformed = tuple(
            (value - mean) / scale
            for value, mean, scale in zip(values, self.means, self.scales, strict=True)
        )
        if not all(math.isfinite(value) for value in transformed):
            raise FeatureContractError("preprocessing produced a non-finite feature")
        return transformed


def load_approved_standard_scaler(
    path: Path,
    *,
    expected_training_dataset_hash: str,
) -> ApprovedStandardScaler:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        scaler = ApprovedStandardScaler.model_validate(payload)
    except (OSError, json.JSONDecodeError, ValueError) as exc:
        raise FeatureContractError("preprocessing artifact is invalid") from exc
    if scaler.training_dataset_hash != expected_training_dataset_hash:
        raise FeatureContractError(
            "preprocessing artifact does not match the training dataset"
        )
    return scaler


class FeaturePreprocessor:
    """Build the exact Phase 7B feature vector without fitting or imputing."""

    def __init__(self, feature_schema_path: Path, barangay_reference_path: Path) -> None:
        self._feature_schema_path = feature_schema_path
        self._barangay_reference_path = barangay_reference_path
        self._lock = Lock()
        self._feature_order: tuple[str, ...] | None = None
        self._barangays: frozenset[str] | None = None

    @property
    def feature_schema_version(self) -> str:
        self._initialize_contract()
        return EXPECTED_FEATURE_SCHEMA_VERSION

    @property
    def feature_order(self) -> tuple[str, ...]:
        self._initialize_contract()
        assert self._feature_order is not None
        return self._feature_order

    def _initialize_contract(self) -> None:
        if self._feature_order is not None and self._barangays is not None:
            return
        with self._lock:
            if self._feature_order is not None and self._barangays is not None:
                return
            try:
                schema = json.loads(self._feature_schema_path.read_text(encoding="utf-8"))
                reference = json.loads(
                    self._barangay_reference_path.read_text(encoding="utf-8")
                )
            except (OSError, json.JSONDecodeError) as exc:
                raise FeatureContractError(
                    "Phase 7B feature or barangay reference is unavailable"
                ) from exc

            order = self._validate_feature_schema(schema)
            barangays = self._validate_barangay_reference(reference)
            self._feature_order = order
            self._barangays = barangays

    @staticmethod
    def _validate_feature_schema(schema: Any) -> tuple[str, ...]:
        if not isinstance(schema, Mapping):
            raise FeatureContractError("Phase 7B feature schema is not an object")
        if schema.get("schema_version") != EXPECTED_FEATURE_SCHEMA_VERSION:
            raise FeatureContractError("Phase 7B feature schema version is incompatible")
        if schema.get("input_shape") != len(EXPECTED_FEATURE_ORDER):
            raise FeatureContractError("Phase 7B feature input shape is incompatible")
        entries = schema.get("features_in_order")
        if not isinstance(entries, list) or not all(isinstance(item, dict) for item in entries):
            raise FeatureContractError("Phase 7B ordered features are invalid")
        order = tuple(item.get("name") for item in entries)
        if order != EXPECTED_FEATURE_ORDER:
            raise FeatureContractError("Phase 7B feature order is incompatible")
        return order

    @staticmethod
    def _validate_barangay_reference(reference: Any) -> frozenset[str]:
        if not isinstance(reference, Mapping) or not isinstance(reference.get("features"), list):
            raise FeatureContractError("Caloocan barangay reference is invalid")
        barangays: set[str] = set()
        for feature in reference["features"]:
            if not isinstance(feature, Mapping):
                raise FeatureContractError("Caloocan barangay feature is invalid")
            properties = feature.get("properties")
            if not isinstance(properties, Mapping):
                raise FeatureContractError("Caloocan barangay properties are invalid")
            psgc = properties.get("current_psgc_10_digit")
            if not isinstance(psgc, str) or len(psgc) != 10 or not psgc.isdigit():
                raise FeatureContractError("Caloocan barangay PSGC metadata is invalid")
            if psgc in barangays:
                raise FeatureContractError("Caloocan barangay PSGC metadata is duplicated")
            barangays.add(psgc)
        if len(barangays) != 187:
            raise FeatureContractError("expected the validated 187-barangay reference")
        return frozenset(barangays)

    def prepare(self, request: FloodRiskPredictionRequest) -> FeatureVector:
        self._initialize_contract()
        assert self._feature_order is not None
        assert self._barangays is not None

        if request.location.barangay_id not in self._barangays:
            raise FeatureContractError(
                "barangay_id is not in the validated current Caloocan reference"
            )
        if (
            request.source_context.feature_schema_version
            != EXPECTED_FEATURE_SCHEMA_VERSION
        ):
            raise FeatureContractError("request feature schema version is incompatible")

        angle = 2.0 * math.pi * (request.valid_from.month - 1) / 12.0
        expected_sin = math.sin(angle)
        expected_cos = math.cos(angle)
        if not math.isclose(request.features.month_sin, expected_sin, abs_tol=1e-6):
            raise FeatureContractError("month_sin does not match valid_from")
        if not math.isclose(request.features.month_cos, expected_cos, abs_tol=1e-6):
            raise FeatureContractError("month_cos does not match valid_from")

        code = request.features.mgb_flood_susceptibility_code
        one_hot = {name: 1.0 if name == code else 0.0 for name in SUSCEPTIBILITY_CODES}
        if sum(one_hot.values()) != 1.0:
            raise FeatureContractError("exactly one MGB susceptibility category is required")

        feature_values = {
            "forecast_rainfall_24h_mm": request.features.forecast_rainfall_24h_mm,
            "antecedent_rainfall_24h_mm": request.features.antecedent_rainfall_24h_mm,
            "antecedent_rainfall_72h_mm": request.features.antecedent_rainfall_72h_mm,
            **{
                f"mgb_susceptibility_{name}": value
                for name, value in one_hot.items()
            },
            "month_sin": expected_sin,
            "month_cos": expected_cos,
        }
        values = tuple(float(feature_values[name]) for name in self._feature_order)
        if len(values) != 10 or not all(math.isfinite(value) for value in values):
            raise FeatureContractError("inference feature vector is invalid")
        return FeatureVector(self._feature_order, values)
