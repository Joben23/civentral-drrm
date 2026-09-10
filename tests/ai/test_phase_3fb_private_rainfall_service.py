"""Focused Phase 3F-B deployment and governance tests."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
SERVICE_ROOT = WORKSPACE / "service"
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from common.rainfall_features import feature_names


BUNDLE = (
    WORKSPACE
    / "deployment/rainfall/rainfall-regression-dense-57-v0.1.1-softplus-candidate"
)
MANIFEST = BUNDLE / "deployment-manifest.json"
AUTHORIZATION = (
    WORKSPACE
    / "manifests/phase-3fb-private-rainfall-inference-authorization.json"
)
EXPECTED_MODEL_SHA256 = (
    "51c89c12ac5919599998805c11e620aa0d80eda3bd5a6be50ec1570c1fda2865"
)
EXPECTED_PREPROCESSING_SHA256 = (
    "e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4"
)


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def test_deployed_bundle_matches_frozen_candidate() -> None:
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    assert digest(BUNDLE / "model.keras") == EXPECTED_MODEL_SHA256
    assert digest(BUNDLE / "preprocessing.json") == EXPECTED_PREPROCESSING_SHA256
    assert manifest["model"]["sha256"] == EXPECTED_MODEL_SHA256
    assert manifest["preprocessing"]["sha256"] == EXPECTED_PREPROCESSING_SHA256
    assert manifest["model"]["byte_length"] == (BUNDLE / "model.keras").stat().st_size
    assert manifest["preprocessing"]["byte_length"] == (
        BUNDLE / "preprocessing.json"
    ).stat().st_size


def test_bundle_scope_is_exact_and_intentionally_visible_to_git() -> None:
    assert {item.name for item in BUNDLE.iterdir()} == {
        "model.keras",
        "preprocessing.json",
        "deployment-manifest.json",
    }
    ignored = subprocess.run(
        ["git", "check-ignore", str(BUNDLE / "model.keras")],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
    )
    assert ignored.returncode == 1


def test_train_only_feature_preprocessing_is_frozen() -> None:
    preprocessing = json.loads(
        (BUNDLE / "preprocessing.json").read_text(encoding="utf-8")
    )
    assert preprocessing["fit_split"] == "train"
    assert preprocessing["expected_feature_count"] == 57
    assert preprocessing["feature_order"] == feature_names()
    assert len(preprocessing["feature_mean"]) == 57
    assert len(preprocessing["feature_std"]) == 57


def test_private_research_authorization_does_not_activate_flood_ai() -> None:
    authorization = json.loads(AUTHORIZATION.read_text(encoding="utf-8"))
    assert (
        authorization["status"]
        == "APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY"
    )
    assert authorization["global_training_ready"] is False
    assert authorization["global_training_authorization"] == "NOT_APPROVED"
    assert authorization["operational_use_approved"] is False
    assert authorization["public_use_approved"] is False
    assert authorization["flood_classifier_approved"] is False
    assert authorization["mgb_fusion_approved"] is False


def test_docker_copy_is_narrow_and_excludes_training_data() -> None:
    dockerfile = (SERVICE_ROOT / "Dockerfile").read_text(encoding="utf-8")
    dockerignore = (REPO_ROOT / ".dockerignore").read_text(encoding="utf-8")
    assert "ml/flood-risk/deployment/rainfall/rainfall-regression-dense-57-v0.1.1-softplus-candidate" in dockerfile
    assert "COPY --chown=civentral:civentral ml/flood-risk/artifacts " not in dockerfile
    for forbidden in (
        "data/raw",
        "data/processed",
        "rainfall-regression-windows-24h-to-3h.json",
        "history.json",
    ):
        assert forbidden not in dockerfile
    assert "ml/flood-risk/data/raw/" in dockerignore
    assert "ml/flood-risk/data/processed/" in dockerignore


def test_compose_has_internal_expose_but_no_host_port_mapping() -> None:
    compose = (REPO_ROOT / "docker-compose.yml").read_text(encoding="utf-8")
    ai_section = compose.split("\n  flood-risk-ai:\n", 1)[1].split(
        "\n\nnetworks:", 1
    )[0]
    assert "expose:" in ai_section
    assert "- 8098" in ai_section
    assert not re.search(r"(?m)^\s+ports:\s*$", ai_section)
    assert "CIVENTRAL_AI_HOST: 0.0.0.0" in ai_section
    assert "CIVENTRAL_AI_ALLOW_PUBLIC_BIND: !!str true" in ai_section


def test_flood_runtime_route_and_global_governance_remain_unchanged() -> None:
    main = (SERVICE_ROOT / "app/main.py").read_text(encoding="utf-8")
    assert '"/v1/predictions/flood-risk"' in main
    assert "TensorFlowModelRuntime" in main
    global_authorization = json.loads(
        (WORKSPACE / "manifests/training-authorization.json").read_text(
            encoding="utf-8"
        )
    )
    assert global_authorization["status"] == "NOT_APPROVED"
    events = json.loads(
        (WORKSPACE / "manifests/flood-event-registry.json").read_text(
            encoding="utf-8"
        )
    )
    for event in events["events"]:
        assert event["label_status"] == "UNKNOWN"
        assert event["training_eligible"] is False
        assert event.get("prediction_cutoff") is None


def test_no_files_are_staged() -> None:
    staged = subprocess.run(
        ["git", "diff", "--cached", "--name-only"],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=True,
    ).stdout.splitlines()
    assert staged == []
