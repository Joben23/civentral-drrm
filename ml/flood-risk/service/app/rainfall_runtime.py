"""Fail-closed runtime for the selected rainfall-regression research candidate."""

from __future__ import annotations

import hashlib
import importlib
import json
import math
from dataclasses import dataclass
from pathlib import Path
from threading import Lock
from typing import Any, Literal, Mapping, Sequence

import numpy as np
from pydantic import BaseModel, ConfigDict, Field, ValidationError, model_validator

from common.rainfall_features import (
    EXPECTED_FEATURE_COUNT,
    EXPECTED_HISTORY_COUNT,
    RainfallFeatureError,
    feature_names,
    make_features,
    validate_history,
)

from .config import FLOOD_RISK_ROOT, Settings


EXPECTED_MODEL_VERSION = (
    "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
)
EXPECTED_MODEL_SHA256 = (
    "51c89c12ac5919599998805c11e620aa0d80eda3bd5a6be50ec1570c1fda2865"
)
EXPECTED_PREPROCESSING_SHA256 = (
    "e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4"
)
EXPECTED_AUTHORIZATION = "APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY"


class RainfallRuntimeError(RuntimeError):
    """Stable sanitized rainfall runtime failure."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class BundleFile(BaseModel):
    model_config = ConfigDict(extra="forbid")

    filename: str
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    byte_length: int = Field(gt=0)


class RainfallArchitecture(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["SMALL_DENSE_REGRESSION"]
    dense_units: tuple[Literal[64], Literal[32], Literal[1]]
    activations: tuple[Literal["relu"], Literal["relu"], Literal["softplus"]]
    parameter_count: Literal[5825]
    tensorflow_version: Literal["2.21.0"]


class RainfallDeploymentManifest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    schema_version: Literal["1.0.0"]
    model_problem: Literal["RAINFALL_REGRESSION"]
    model_version: Literal[
        "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
    ]
    model_status: Literal["VALIDATED_RESEARCH_CANDIDATE"]
    model: BundleFile
    preprocessing: BundleFile
    feature_count: Literal[57]
    history_intervals: Literal[48]
    interval_minutes: Literal[30]
    output_policy: Literal["MODEL_NONNEGATIVE_SOFTPLUS"]
    target: Literal["NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM"]
    forecast_horizon_hours: Literal[3]
    architecture: RainfallArchitecture
    operational: Literal[False]
    public_use_approved: Literal[False]
    flood_classifier: Literal[False]
    mgb_fusion_approved: Literal[False]

    @model_validator(mode="after")
    def validate_frozen_contract(self) -> "RainfallDeploymentManifest":
        if self.model.filename != "model.keras":
            raise ValueError("unexpected model filename")
        if self.preprocessing.filename != "preprocessing.json":
            raise ValueError("unexpected preprocessing filename")
        if self.model.sha256 != EXPECTED_MODEL_SHA256:
            raise ValueError("unexpected model checksum")
        if self.preprocessing.sha256 != EXPECTED_PREPROCESSING_SHA256:
            raise ValueError("unexpected preprocessing checksum")
        return self


class RainfallInferenceAuthorization(BaseModel):
    model_config = ConfigDict(extra="forbid")

    schema_version: Literal["1.0.0"]
    phase: Literal["TENSORFLOW_PHASE_3F_B"]
    recorded_at_utc: str
    decision_scope: Literal["PROJECT_RESEARCH_ONLY"]
    authorization_type: Literal["PRIVATE_RAINFALL_REGRESSION_INFERENCE"]
    status: Literal["APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY"]
    authorized_model_version: Literal[
        "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
    ]
    private_internal_inference: Literal[True]
    operational_use_approved: Literal[False]
    public_use_approved: Literal[False]
    citizen_direct_access_approved: Literal[False]
    flood_classifier_approved: Literal[False]
    mgb_fusion_approved: Literal[False]
    emergency_decision_approved: Literal[False]
    global_training_ready: Literal[False]
    global_training_authorization: Literal["NOT_APPROVED"]
    statement: str


@dataclass(frozen=True)
class RainfallRuntimeStatus:
    ready: bool
    code: str
    message: str
    model_version: str | None = None
    model_status: str | None = None
    authorization_status: str = "NOT_APPROVED"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


class RainfallRegressionRuntime:
    """Load and serve only the frozen 57-feature Softplus rainfall model."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._lock = Lock()
        self._initialized = False
        self._model: Any = None
        self._preprocessing: dict[str, Any] | None = None
        self._manifest: RainfallDeploymentManifest | None = None
        self._status = RainfallRuntimeStatus(
            False,
            "MODEL_UNAVAILABLE",
            "Private rainfall research inference is unavailable.",
        )

    @property
    def manifest(self) -> RainfallDeploymentManifest | None:
        return self._manifest

    def get_status(self, *, initialize: bool = True) -> RainfallRuntimeStatus:
        if initialize:
            self.initialize()
        return self._status

    def initialize(self) -> None:
        if self._initialized:
            return
        with self._lock:
            if self._initialized:
                return
            self._initialized = True
            authorization_status = "NOT_APPROVED"
            try:
                authorization = self._load_authorization()
                authorization_status = authorization.status
                bundle = self._resolve_under_root(
                    self._settings.rainfall_bundle_path, FLOOD_RISK_ROOT
                )
                manifest_path = bundle / "deployment-manifest.json"
                if not bundle.is_dir() or not manifest_path.is_file():
                    raise RainfallRuntimeError(
                        "MODEL_UNAVAILABLE",
                        "Private rainfall research model bundle is unavailable.",
                    )
                manifest = self._load_manifest(manifest_path)
                model_path = bundle / manifest.model.filename
                preprocessing_path = bundle / manifest.preprocessing.filename
                self._verify_file(
                    model_path, manifest.model, "MODEL_ARTIFACT_MISMATCH"
                )
                self._verify_file(
                    preprocessing_path,
                    manifest.preprocessing,
                    "PREPROCESSING_MISMATCH",
                )
                preprocessing = self._load_preprocessing(preprocessing_path)
                model = self._load_model(model_path)
                self._manifest = manifest
                self._preprocessing = preprocessing
                self._model = model
                self._status = RainfallRuntimeStatus(
                    True,
                    "RAINFALL_RESEARCH_READY",
                    "Private research-only rainfall regression inference is ready.",
                    model_version=manifest.model_version,
                    model_status=manifest.model_status,
                    authorization_status=authorization.status,
                )
            except RainfallRuntimeError as exc:
                self._status = RainfallRuntimeStatus(
                    False,
                    exc.code,
                    str(exc),
                    authorization_status=authorization_status,
                )
            except Exception:
                self._status = RainfallRuntimeStatus(
                    False,
                    "MODEL_LOAD_FAILED",
                    "Approved rainfall model could not be loaded.",
                    authorization_status=authorization_status,
                )

    def _load_authorization(self) -> RainfallInferenceAuthorization:
        path = self._resolve_under_root(
            self._settings.rainfall_authorization_path, FLOOD_RISK_ROOT
        )
        if not path.is_file():
            raise RainfallRuntimeError(
                "MODEL_INFERENCE_NOT_APPROVED",
                "Private rainfall research inference is not approved.",
            )
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            authorization = RainfallInferenceAuthorization.model_validate(data)
        except (OSError, json.JSONDecodeError, ValidationError) as exc:
            raise RainfallRuntimeError(
                "MODEL_INFERENCE_NOT_APPROVED",
                "Private rainfall research inference is not approved.",
            ) from exc
        if (
            authorization.status != EXPECTED_AUTHORIZATION
            or authorization.authorized_model_version != EXPECTED_MODEL_VERSION
        ):
            raise RainfallRuntimeError(
                "MODEL_INFERENCE_NOT_APPROVED",
                "Private rainfall research inference is not approved.",
            )
        return authorization

    def _load_manifest(self, path: Path) -> RainfallDeploymentManifest:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            return RainfallDeploymentManifest.model_validate(data)
        except (OSError, json.JSONDecodeError, ValidationError) as exc:
            raise RainfallRuntimeError(
                "MODEL_ARTIFACT_MISMATCH",
                "Rainfall deployment manifest does not match the approved candidate.",
            ) from exc

    def _load_preprocessing(self, path: Path) -> dict[str, Any]:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            means = data["feature_mean"]
            stds = data["feature_std"]
        except (OSError, json.JSONDecodeError, KeyError, TypeError) as exc:
            raise RainfallRuntimeError(
                "PREPROCESSING_MISMATCH",
                "Stored train-derived rainfall preprocessing is invalid.",
            ) from exc
        if (
            data.get("model_problem") != "RAINFALL_REGRESSION"
            or data.get("fit_split") != "train"
            or data.get("method") != "STANDARD_SCALER"
            or data.get("expected_feature_count") != EXPECTED_FEATURE_COUNT
            or data.get("feature_order") != feature_names()
            or not isinstance(means, list)
            or not isinstance(stds, list)
            or len(means) != EXPECTED_FEATURE_COUNT
            or len(stds) != EXPECTED_FEATURE_COUNT
        ):
            raise RainfallRuntimeError(
                "FEATURE_CONTRACT_MISMATCH",
                "Rainfall preprocessing feature contract does not match Phase 3C.",
            )
        try:
            arrays = [float(value) for value in (*means, *stds)]
            valid = all(math.isfinite(value) for value in arrays) and all(
                float(value) > 0 for value in stds
            )
        except (TypeError, ValueError):
            valid = False
        if not valid:
            raise RainfallRuntimeError(
                "PREPROCESSING_MISMATCH",
                "Stored train-derived rainfall preprocessing is invalid.",
            )
        return data

    def _load_model(self, path: Path) -> Any:
        try:
            tensorflow = importlib.import_module("tensorflow")
            model = tensorflow.keras.models.load_model(path, compile=False)
        except Exception as exc:
            raise RainfallRuntimeError(
                "MODEL_LOAD_FAILED", "Approved rainfall model could not be loaded."
            ) from exc
        dense_layers = [
            layer for layer in model.layers if layer.__class__.__name__ == "Dense"
        ]
        try:
            compatible = (
                tuple(model.input_shape) == (None, EXPECTED_FEATURE_COUNT)
                and tuple(model.output_shape) == (None, 1)
                and model.count_params() == 5825
                and [layer.units for layer in dense_layers] == [64, 32, 1]
                and [layer.activation.__name__ for layer in dense_layers]
                == ["relu", "relu", "softplus"]
            )
        except (AttributeError, TypeError):
            compatible = False
        if not compatible:
            raise RainfallRuntimeError(
                "FEATURE_CONTRACT_MISMATCH",
                "Loaded rainfall model is incompatible with the approved feature contract.",
            )
        return model

    @staticmethod
    def _resolve_under_root(path: Path, root: Path) -> Path:
        resolved = path.resolve()
        try:
            resolved.relative_to(root.resolve())
        except ValueError as exc:
            raise RainfallRuntimeError(
                "MODEL_UNAVAILABLE", "Rainfall runtime path is outside its governed root."
            ) from exc
        return resolved

    @staticmethod
    def _verify_file(path: Path, declared: BundleFile, code: str) -> None:
        if not path.is_file():
            raise RainfallRuntimeError(
                code if code == "PREPROCESSING_MISMATCH" else "MODEL_UNAVAILABLE",
                "Required rainfall runtime artifact is unavailable.",
            )
        if path.stat().st_size != declared.byte_length or sha256(path) != declared.sha256:
            raise RainfallRuntimeError(
                code, "Rainfall runtime artifact failed integrity validation."
            )

    def predict(self, history: Sequence[Mapping[str, Any]]) -> dict[str, Any]:
        status = self.get_status()
        if not status.ready or self._model is None or self._preprocessing is None:
            raise RainfallRuntimeError(status.code, status.message)
        try:
            records = validate_history(history)
            values, names = make_features(records, EXPECTED_HISTORY_COUNT - 1)
        except RainfallFeatureError:
            raise
        if names != feature_names() or len(values) != EXPECTED_FEATURE_COUNT:
            raise RainfallRuntimeError(
                "FEATURE_CONTRACT_MISMATCH",
                "Rainfall feature construction did not match Phase 3C.",
            )
        try:
            vector = (
                np.asarray(values, dtype=np.float32)
                - np.asarray(self._preprocessing["feature_mean"], dtype=np.float32)
            ) / np.asarray(self._preprocessing["feature_std"], dtype=np.float32)
            if vector.shape != (EXPECTED_FEATURE_COUNT,) or not np.isfinite(vector).all():
                raise ValueError("invalid transformed vector")
            raw = float(
                self._model.predict(vector.reshape(1, -1), verbose=0).reshape(-1)[0]
            )
        except Exception as exc:
            raise RainfallRuntimeError(
                "PREDICTION_FAILED", "Rainfall regression prediction failed."
            ) from exc
        if not math.isfinite(raw) or raw < 0:
            raise RainfallRuntimeError(
                "PREDICTION_FAILED",
                "Rainfall regression prediction was not finite and nonnegative.",
            )
        return {
            "model_problem": "RAINFALL_REGRESSION",
            "forecast_origin_utc": records[-1]["observed_at_end"],
            "forecast_horizon_hours": 3,
            "target": "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM",
            "raw_prediction_mm": raw,
            "final_prediction_mm": raw,
            "output_policy": "MODEL_NONNEGATIVE_SOFTPLUS",
            "model_version": EXPECTED_MODEL_VERSION,
            "model_status": "VALIDATED_RESEARCH_CANDIDATE",
            "operational": False,
        }
