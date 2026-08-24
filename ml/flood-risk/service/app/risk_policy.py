"""Versioned operational policy kept separate from TensorFlow probability."""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from datetime import datetime
from enum import Enum
from pathlib import Path
from threading import Lock
from typing import Literal

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, model_validator


class RiskPolicyState(str, Enum):
    NOT_CONFIGURED = "NOT_CONFIGURED"
    INVALID = "INVALID"
    AVAILABLE_NOT_APPROVED = "AVAILABLE_NOT_APPROVED"
    READY = "READY"


class RiskPolicyDocument(BaseModel):
    model_config = ConfigDict(extra="forbid", allow_inf_nan=False)

    policy_schema_version: Literal["1.0"]
    policy_version: str = Field(min_length=1, max_length=128)
    policy_status: Literal["DEVELOPMENT_NOT_APPROVED", "OPERATIONALLY_APPROVED"]
    approved_for_inference: bool
    approved_at: AwareDatetime | None
    compatible_model_versions: tuple[str, ...] = Field(min_length=1)
    flood_outcome_threshold: float = Field(ge=0, le=1)
    moderate_min_probability: float = Field(gt=0, le=1)
    high_min_probability: float = Field(gt=0, le=1)
    critical_min_probability: float = Field(gt=0, le=1)
    limitations: tuple[str, ...] = Field(min_length=1)

    @model_validator(mode="after")
    def validate_approval_and_order(self) -> "RiskPolicyDocument":
        thresholds = (
            self.moderate_min_probability,
            self.high_min_probability,
            self.critical_min_probability,
        )
        if not thresholds[0] < thresholds[1] < thresholds[2]:
            raise ValueError("risk thresholds must be strictly increasing")
        operational = self.policy_status == "OPERATIONALLY_APPROVED"
        if operational != self.approved_for_inference:
            raise ValueError("risk policy status and approval flag are inconsistent")
        if operational and self.approved_at is None:
            raise ValueError("an operational policy requires approved_at")
        if not operational and self.approved_at is not None:
            raise ValueError("an unapproved policy cannot contain approved_at")
        return self


@dataclass(frozen=True)
class RiskPolicyStatus:
    state: RiskPolicyState
    policy_version: str | None
    message: str


@dataclass(frozen=True)
class RiskDecision:
    risk_level: Literal["LOW", "MODERATE", "HIGH", "CRITICAL"]
    predicted_outcome: Literal["FLOOD", "NO_FLOOD"]
    policy_version: str
    limitations: tuple[str, ...]


class RiskPolicyRuntime:
    """Load a separately approved probability-to-category policy."""

    def __init__(self, policy_path: Path | None, artifact_root: Path) -> None:
        self._policy_path = policy_path
        self._artifact_root = artifact_root
        self._lock = Lock()
        self._initialized = False
        self._policy: RiskPolicyDocument | None = None
        self._status = RiskPolicyStatus(
            RiskPolicyState.NOT_CONFIGURED,
            None,
            "No approved CIVENTRAL risk policy is configured.",
        )

    def get_status(self, *, initialize: bool = True) -> RiskPolicyStatus:
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
            if self._policy_path is None:
                return
            try:
                path = self._resolve_inside_artifact_root(self._policy_path)
                if not path.is_file():
                    self._set_invalid("Configured risk policy file is unavailable.")
                    return
                payload = json.loads(path.read_text(encoding="utf-8"))
                policy = RiskPolicyDocument.model_validate(payload)
            except (OSError, json.JSONDecodeError, ValueError):
                self._set_invalid("Configured risk policy is invalid.")
                return

            self._policy = policy
            if not policy.approved_for_inference:
                self._status = RiskPolicyStatus(
                    RiskPolicyState.AVAILABLE_NOT_APPROVED,
                    policy.policy_version,
                    "A risk policy exists but is not operationally approved.",
                )
                return
            self._status = RiskPolicyStatus(
                RiskPolicyState.READY,
                policy.policy_version,
                "An approved CIVENTRAL risk policy is available.",
            )

    def supports(self, model_version: str, policy_version: str | None) -> bool:
        self.initialize()
        return bool(
            self._status.state is RiskPolicyState.READY
            and self._policy is not None
            and self._policy.policy_version == policy_version
            and model_version in self._policy.compatible_model_versions
        )

    def classify(self, probability: float, model_version: str) -> RiskDecision:
        self.initialize()
        policy = self._policy
        if self._status.state is not RiskPolicyState.READY or policy is None:
            raise RuntimeError("risk policy is not ready")
        if model_version not in policy.compatible_model_versions:
            raise RuntimeError("risk policy is incompatible with model version")
        if not math.isfinite(probability) or not 0 <= probability <= 1:
            raise RuntimeError("model probability is outside the required range")

        if probability >= policy.critical_min_probability:
            level = "CRITICAL"
        elif probability >= policy.high_min_probability:
            level = "HIGH"
        elif probability >= policy.moderate_min_probability:
            level = "MODERATE"
        else:
            level = "LOW"

        outcome = "FLOOD" if probability >= policy.flood_outcome_threshold else "NO_FLOOD"
        return RiskDecision(
            risk_level=level,
            predicted_outcome=outcome,
            policy_version=policy.policy_version,
            limitations=policy.limitations,
        )

    def _resolve_inside_artifact_root(self, path: Path) -> Path:
        root = self._artifact_root.resolve()
        resolved = path.resolve()
        try:
            resolved.relative_to(root)
        except ValueError as exc:
            raise ValueError("risk policy path must be inside artifact root") from exc
        return resolved

    def _set_invalid(self, message: str) -> None:
        self._status = RiskPolicyStatus(RiskPolicyState.INVALID, None, message)
