"""Fail-closed TensorFlow/Keras artifact loader and inference runtime."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import math
import platform
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from threading import Lock
from typing import Any, Literal

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, model_validator

from .config import Settings
from .preprocessing import (
    ApprovedStandardScaler,
    FeatureContractError,
    FeatureVector,
    EXPECTED_FEATURE_SCHEMA_VERSION,
    FeaturePreprocessor,
    load_approved_standard_scaler,
)
from .schemas import ModelState


class ModelArtifactManifest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    manifest_schema_version: Literal["1.0"]
    model_version: str = Field(min_length=1, max_length=128)
    model_status: Literal[
        "DEVELOPMENT_NOT_OPERATIONALLY_VALIDATED", "OPERATIONALLY_VALIDATED"
    ]
    feature_schema_version: Literal["1.0.0"]
    training_dataset_hash: str = Field(pattern=r"^[a-f0-9]{64}$")
    trained_at: AwareDatetime
    tensorflow_version: str = Field(pattern=r"^[0-9]+\.[0-9]+(?:\.[0-9]+)?$")
    python_version: str = Field(pattern=r"^[0-9]+\.[0-9]+(?:\.[0-9]+)?$")
    artifact_filename: str = Field(
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]*\.keras$"
    )
    artifact_format: Literal["KERAS_V3"]
    artifact_checksum: str = Field(pattern=r"^[a-f0-9]{64}$")
    approved_for_inference: bool
    threshold_policy_version: str | None = Field(default=None, min_length=1, max_length=128)
    input_shape: Literal[10]
    output_semantics: Literal["FLOOD_PROBABILITY"]
    preprocessing_artifact_format: Literal["STANDARD_SCALER_JSON"] | None
    preprocessing_artifact_filename: str | None
    preprocessing_artifact_checksum: str | None = Field(
        default=None, pattern=r"^[a-f0-9]{64}$"
    )
    limitations: tuple[str, ...] = Field(min_length=1)

    @model_validator(mode="after")
    def validate_consistency(self) -> "ModelArtifactManifest":
        operational = self.model_status == "OPERATIONALLY_VALIDATED"
        if operational != self.approved_for_inference:
            raise ValueError("model status and approval flag are inconsistent")
        if self.approved_for_inference and self.threshold_policy_version is None:
            raise ValueError("an approved model requires a threshold policy version")
        preprocessing = (
            self.preprocessing_artifact_format,
            self.preprocessing_artifact_filename,
            self.preprocessing_artifact_checksum,
        )
        if any(item is not None for item in preprocessing) and not all(
            item is not None for item in preprocessing
        ):
            raise ValueError("preprocessing artifact metadata must be all null or complete")
        return self


@dataclass(frozen=True)
class ModelRuntimeStatus:
    state: ModelState
    message: str
    model_version: str | None = None
    model_declared_status: str | None = None
    approved_for_inference: bool = False
    threshold_policy_version: str | None = None
    tensorflow_installed: bool = False
    tensorflow_version: str | None = None

    @property
    def model_available(self) -> bool:
        return self.state in {
            ModelState.MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED,
            ModelState.MODEL_READY,
        }


class TensorFlowModelRuntime:
    """Load only an explicitly configured, checksummed and approved Keras model."""

    def __init__(self, settings: Settings, preprocessor: FeaturePreprocessor) -> None:
        self._settings = settings
        self._preprocessor = preprocessor
        self._lock = Lock()
        self._initialized = False
        self._model: Any = None
        self._manifest: ModelArtifactManifest | None = None
        self._scaler: ApprovedStandardScaler | None = None
        installed = self.tensorflow_is_installed()
        self._status = ModelRuntimeStatus(
            ModelState.MODEL_NOT_AVAILABLE,
            "No approved TensorFlow flood-risk model is available for inference.",
            tensorflow_installed=installed,
        )

    @staticmethod
    def tensorflow_is_installed() -> bool:
        try:
            return importlib.util.find_spec("tensorflow") is not None
        except (ImportError, ValueError):
            return False

    def get_status(self, *, initialize: bool = True) -> ModelRuntimeStatus:
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
            model_path = self._settings.model_path
            manifest_path = self._settings.model_manifest_path
            if model_path is None and manifest_path is None:
                return
            if model_path is None or manifest_path is None:
                self._set_status(
                    ModelState.MODEL_INVALID,
                    "Model and manifest paths must be configured together.",
                )
                return

            try:
                resolved_model = self._resolve_inside_artifact_root(model_path)
                resolved_manifest = self._resolve_inside_artifact_root(manifest_path)
                if not resolved_model.is_file() or not resolved_manifest.is_file():
                    self._set_status(
                        ModelState.MODEL_NOT_AVAILABLE,
                        "Configured TensorFlow model artifact or manifest is unavailable.",
                    )
                    return
                manifest = self._load_manifest(resolved_manifest)
                self._manifest = manifest
                self._validate_static_artifact(resolved_model, manifest)
                self._preprocessor.feature_order
                self._scaler = self._load_optional_scaler(resolved_model.parent, manifest)
            except (OSError, ValueError, FeatureContractError):
                self._set_status(
                    ModelState.MODEL_INVALID,
                    "Configured TensorFlow model bundle failed validation.",
                )
                return

            if not manifest.approved_for_inference:
                self._set_status(
                    ModelState.MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED,
                    "A model artifact exists but is not operationally validated.",
                    manifest=manifest,
                )
                return

            if not self.tensorflow_is_installed():
                self._set_status(
                    ModelState.MODEL_INVALID,
                    "TensorFlow is unavailable in this Python runtime.",
                    manifest=manifest,
                )
                return

            try:
                import tensorflow as tf  # Imported only for an approved configured model.

                if not self._versions_equal(tf.__version__, manifest.tensorflow_version):
                    raise ValueError("TensorFlow version does not match manifest")
                model = tf.keras.models.load_model(
                    resolved_model,
                    compile=False,
                    safe_mode=True,
                )
                self._validate_model_shapes(model)
            except Exception:
                self._set_status(
                    ModelState.MODEL_INVALID,
                    "Approved TensorFlow model could not be loaded safely.",
                    manifest=manifest,
                )
                return

            self._model = model
            self._set_status(
                ModelState.MODEL_READY,
                "Approved TensorFlow model artifact is loaded for inference.",
                manifest=manifest,
                tensorflow_version=tf.__version__,
            )

    def predict_probability(self, vector: FeatureVector) -> float:
        self.initialize()
        if self._status.state is not ModelState.MODEL_READY or self._model is None:
            raise RuntimeError("model is not ready")
        values = vector.values
        if self._scaler is not None:
            values = self._scaler.transform(values)
        result = self._model([values], training=False)
        try:
            array = result.numpy().reshape(-1)
            if len(array) != 1:
                raise ValueError("model output must contain one value")
            probability = float(array[0])
        except (AttributeError, TypeError, ValueError) as exc:
            raise RuntimeError("model produced an invalid output") from exc
        if not math.isfinite(probability) or not 0 <= probability <= 1:
            raise RuntimeError("model probability is outside the required range")
        return probability

    @property
    def manifest(self) -> ModelArtifactManifest | None:
        self.initialize()
        return self._manifest

    def _load_manifest(self, path: Path) -> ModelArtifactManifest:
        payload = json.loads(path.read_text(encoding="utf-8"))
        return ModelArtifactManifest.model_validate(payload)

    def _validate_static_artifact(
        self, model_path: Path, manifest: ModelArtifactManifest
    ) -> None:
        if model_path.name != manifest.artifact_filename:
            raise ValueError("model filename does not match manifest")
        if manifest.feature_schema_version != EXPECTED_FEATURE_SCHEMA_VERSION:
            raise ValueError("feature schema version is incompatible")
        if self._sha256(model_path) != manifest.artifact_checksum:
            raise ValueError("model checksum does not match manifest")
        running_python = platform.python_version()
        if self._major_minor(running_python) != self._major_minor(manifest.python_version):
            raise ValueError("Python version does not match manifest")

    def _load_optional_scaler(
        self, bundle_directory: Path, manifest: ModelArtifactManifest
    ) -> ApprovedStandardScaler | None:
        if manifest.preprocessing_artifact_filename is None:
            return None
        candidate = self._resolve_inside_artifact_root(
            bundle_directory / manifest.preprocessing_artifact_filename
        )
        if not candidate.is_file():
            raise ValueError("preprocessing artifact is unavailable")
        if self._sha256(candidate) != manifest.preprocessing_artifact_checksum:
            raise ValueError("preprocessing artifact checksum does not match manifest")
        return load_approved_standard_scaler(
            candidate,
            expected_training_dataset_hash=manifest.training_dataset_hash,
        )

    @staticmethod
    def _validate_model_shapes(model: Any) -> None:
        input_shape = model.input_shape
        output_shape = model.output_shape
        if isinstance(input_shape, list) or isinstance(output_shape, list):
            raise ValueError("multi-input or multi-output models are not supported")
        if not input_shape or input_shape[-1] != 10:
            raise ValueError("model input shape must end in 10")
        if not output_shape or output_shape[-1] != 1:
            raise ValueError("model output shape must end in 1")

    def _resolve_inside_artifact_root(self, path: Path) -> Path:
        root = self._settings.artifact_root.resolve()
        resolved = path.resolve()
        try:
            resolved.relative_to(root)
        except ValueError as exc:
            raise ValueError("artifact paths must remain inside artifact root") from exc
        return resolved

    @staticmethod
    def _sha256(path: Path) -> str:
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()

    @staticmethod
    def _major_minor(version: str) -> tuple[int, int]:
        parts = version.split(".")
        if len(parts) < 2:
            raise ValueError("version must contain major and minor")
        return int(parts[0]), int(parts[1])

    @classmethod
    def _versions_equal(cls, left: str, right: str) -> bool:
        def normalized(version: str) -> tuple[int, ...]:
            return tuple(int(part) for part in version.split("."))

        try:
            return normalized(left) == normalized(right)
        except ValueError:
            return False

    def _set_status(
        self,
        state: ModelState,
        message: str,
        *,
        manifest: ModelArtifactManifest | None = None,
        tensorflow_version: str | None = None,
    ) -> None:
        self._status = ModelRuntimeStatus(
            state=state,
            message=message,
            model_version=manifest.model_version if manifest else None,
            model_declared_status=manifest.model_status if manifest else None,
            approved_for_inference=bool(manifest and manifest.approved_for_inference),
            threshold_policy_version=(manifest.threshold_policy_version if manifest else None),
            tensorflow_installed=self.tensorflow_is_installed(),
            tensorflow_version=tensorflow_version,
        )
