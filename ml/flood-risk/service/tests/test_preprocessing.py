from __future__ import annotations

import pytest

from app.preprocessing import EXPECTED_FEATURE_ORDER, FeatureContractError
from app.schemas import FloodRiskPredictionRequest


def test_feature_vector_uses_phase_7b_order(settings, valid_request) -> None:
    from app.preprocessing import FeaturePreprocessor

    preprocessor = FeaturePreprocessor(
        settings.feature_schema_path,
        settings.barangay_reference_path,
    )
    request = FloodRiskPredictionRequest.model_validate(valid_request)

    vector = preprocessor.prepare(request)

    assert vector.names == EXPECTED_FEATURE_ORDER
    assert vector.values == (10.0, 5.0, 15.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0)


def test_unknown_current_barangay_identifier_is_rejected(settings, copy_request) -> None:
    from app.preprocessing import FeaturePreprocessor

    payload = copy_request()
    payload["location"]["barangay_id"] = "1380100176"
    request = FloodRiskPredictionRequest.model_validate(payload)
    preprocessor = FeaturePreprocessor(
        settings.feature_schema_path,
        settings.barangay_reference_path,
    )

    with pytest.raises(FeatureContractError, match="validated current Caloocan"):
        preprocessor.prepare(request)


def test_month_components_must_match_valid_window(settings, copy_request) -> None:
    from app.preprocessing import FeaturePreprocessor

    payload = copy_request()
    payload["features"]["month_cos"] = 0.0
    request = FloodRiskPredictionRequest.model_validate(payload)
    preprocessor = FeaturePreprocessor(
        settings.feature_schema_path,
        settings.barangay_reference_path,
    )

    with pytest.raises(FeatureContractError, match="month_cos"):
        preprocessor.prepare(request)
