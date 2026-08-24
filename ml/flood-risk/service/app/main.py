"""Private FastAPI application for future approved flood-risk inference."""

from __future__ import annotations

import platform
from datetime import datetime, timezone
from fastapi import Depends, FastAPI, Request
from fastapi.encoders import jsonable_encoder
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from . import __version__
from .config import Settings
from .errors import ApiError
from .logging_config import SanitizedRequestLoggingMiddleware, configure_logging
from .model_runtime import TensorFlowModelRuntime
from .preprocessing import FeatureContractError, FeaturePreprocessor
from .risk_policy import RiskPolicyRuntime, RiskPolicyState
from .schemas import (
    ErrorResponse,
    FloodRiskPredictionRequest,
    FutureFloodRiskPredictionResponse,
    HealthResponse,
    ModelState,
    ModelStatusResponse,
    ReadinessResponse,
)
from .security import InternalAuthenticator


MODEL_UNAVAILABLE_MESSAGE = (
    "No approved TensorFlow flood-risk model is available for inference."
)


def create_app(settings: Settings | None = None) -> FastAPI:
    runtime_settings = settings or Settings()
    configure_logging(runtime_settings.log_level)

    app = FastAPI(
        title="CIVENTRAL Private Flood-Risk Inference Service",
        version=__version__,
        description=(
            "Private PHP-to-Python decision-support runtime. It cannot create or "
            "activate CIVENTRAL warnings."
        ),
        docs_url="/docs" if runtime_settings.enable_docs else None,
        redoc_url=None,
        openapi_url="/openapi.json" if runtime_settings.enable_docs else None,
    )
    app.add_middleware(
        SanitizedRequestLoggingMiddleware,
        max_request_bytes=runtime_settings.max_request_bytes,
    )

    preprocessor = FeaturePreprocessor(
        runtime_settings.feature_schema_path,
        runtime_settings.barangay_reference_path,
    )
    model_runtime = TensorFlowModelRuntime(runtime_settings, preprocessor)
    risk_policy = RiskPolicyRuntime(
        runtime_settings.risk_policy_path,
        runtime_settings.artifact_root,
    )
    authenticator = InternalAuthenticator(runtime_settings)

    app.state.settings = runtime_settings
    app.state.preprocessor = preprocessor
    app.state.model_runtime = model_runtime
    app.state.risk_policy = risk_policy

    @app.exception_handler(ApiError)
    async def handle_api_error(_request: Request, exc: ApiError) -> JSONResponse:
        payload: dict[str, object] = {
            "success": False,
            "code": exc.code,
            "message": exc.message,
        }
        if exc.model_status is not None:
            payload["model_status"] = exc.model_status
        return JSONResponse(status_code=exc.status_code, content=payload)

    @app.exception_handler(RequestValidationError)
    async def handle_validation_error(
        _request: Request, exc: RequestValidationError
    ) -> JSONResponse:
        errors = [
            {
                "location": [str(part) for part in error.get("loc", ())],
                "type": error.get("type", "validation_error"),
                "message": error.get("msg", "Invalid request value."),
            }
            for error in exc.errors()
        ]
        return JSONResponse(
            status_code=422,
            content={
                "success": False,
                "code": "INVALID_REQUEST",
                "message": "Prediction request validation failed.",
                "errors": errors,
            },
        )

    @app.exception_handler(Exception)
    async def handle_unexpected_error(_request: Request, _exc: Exception) -> JSONResponse:
        return JSONResponse(
            status_code=500,
            content={
                "success": False,
                "code": "INTERNAL_ERROR",
                "message": "The internal inference service encountered an error.",
            },
        )

    @app.get("/health", response_model=HealthResponse)
    async def health() -> HealthResponse:
        model_status = model_runtime.get_status(initialize=False)
        policy_status = risk_policy.get_status(initialize=False)
        return HealthResponse(
            service_version=__version__,
            python_version=platform.python_version(),
            tensorflow_installed=model_status.tensorflow_installed,
            model_status=model_status.state,
            risk_policy_status=policy_status.state.value,
            checked_at=datetime.now(timezone.utc),
        )

    @app.get(
        "/ready",
        response_model=ReadinessResponse,
        responses={503: {"model": ReadinessResponse}},
    )
    async def ready() -> ReadinessResponse | JSONResponse:
        model_status = await run_in_threadpool(model_runtime.get_status)
        policy_status = await run_in_threadpool(risk_policy.get_status)

        if model_status.state is not ModelState.MODEL_READY:
            payload = ReadinessResponse(
                success=False,
                ready=False,
                code=model_status.state.value,
                message=model_status.message,
                model_status=model_status.state,
                risk_policy_status=policy_status.state.value,
            )
            return JSONResponse(status_code=503, content=jsonable_encoder(payload))

        if (
            runtime_settings.require_internal_auth
            and not runtime_settings.internal_auth_configured
        ):
            payload = ReadinessResponse(
                success=False,
                ready=False,
                code="INTERNAL_AUTH_NOT_CONFIGURED",
                message="Internal service authentication is not configured.",
                model_status=model_status.state,
                risk_policy_status=policy_status.state.value,
            )
            return JSONResponse(status_code=503, content=jsonable_encoder(payload))

        manifest = model_runtime.manifest
        if (
            policy_status.state is not RiskPolicyState.READY
            or manifest is None
            or not risk_policy.supports(
                manifest.model_version, manifest.threshold_policy_version
            )
        ):
            payload = ReadinessResponse(
                success=False,
                ready=False,
                code="RISK_POLICY_NOT_READY",
                message="No compatible approved CIVENTRAL risk policy is available.",
                model_status=model_status.state,
                risk_policy_status=policy_status.state.value,
            )
            return JSONResponse(status_code=503, content=jsonable_encoder(payload))

        return ReadinessResponse(
            success=True,
            ready=True,
            code="READY",
            message="Approved flood-risk inference is available.",
            model_status=model_status.state,
            risk_policy_status=policy_status.state.value,
        )

    @app.get("/v1/model/status", response_model=ModelStatusResponse)
    async def model_status(
        _auth: None = Depends(authenticator),
    ) -> ModelStatusResponse:
        status = await run_in_threadpool(model_runtime.get_status)
        return ModelStatusResponse(
            model_status=status.state,
            model_available=status.model_available,
            approved_for_inference=status.approved_for_inference,
            model_version=status.model_version,
            model_declared_status=status.model_declared_status,
            feature_schema_version=preprocessor.feature_schema_version,
            threshold_policy_version=status.threshold_policy_version,
            tensorflow_installed=status.tensorflow_installed,
            tensorflow_version=status.tensorflow_version,
            python_version=platform.python_version(),
            message=status.message,
        )

    @app.post(
        "/v1/predictions/flood-risk",
        response_model=FutureFloodRiskPredictionResponse,
        responses={
            401: {"model": ErrorResponse},
            503: {"model": ErrorResponse},
        },
    )
    async def predict_flood_risk(
        request_data: FloodRiskPredictionRequest,
        _auth: None = Depends(authenticator),
    ) -> FutureFloodRiskPredictionResponse:
        status = await run_in_threadpool(model_runtime.get_status)
        if status.state is not ModelState.MODEL_READY:
            message = (
                MODEL_UNAVAILABLE_MESSAGE
                if status.state is ModelState.MODEL_NOT_AVAILABLE
                else status.message
            )
            raise ApiError(
                503,
                status.state.value,
                message,
                model_status=status.state.value,
            )

        manifest = model_runtime.manifest
        policy_status = await run_in_threadpool(risk_policy.get_status)
        if (
            manifest is None
            or policy_status.state is not RiskPolicyState.READY
            or not risk_policy.supports(
                manifest.model_version, manifest.threshold_policy_version
            )
        ):
            raise ApiError(
                503,
                "RISK_POLICY_NOT_READY",
                "No compatible approved CIVENTRAL risk policy is available.",
                model_status=status.state.value,
            )

        try:
            vector = preprocessor.prepare(request_data)
        except FeatureContractError as exc:
            raise ApiError(422, "INVALID_FEATURE_CONTRACT", str(exc)) from exc

        probability = await run_in_threadpool(
            model_runtime.predict_probability, vector
        )
        decision = risk_policy.classify(probability, manifest.model_version)
        limitations = tuple(dict.fromkeys((*manifest.limitations, *decision.limitations)))
        return FutureFloodRiskPredictionResponse(
            request_id=request_data.request_id,
            model_version=manifest.model_version,
            model_status=ModelState.MODEL_READY,
            probability=probability,
            predicted_outcome=decision.predicted_outcome,
            threshold_policy_version=decision.policy_version,
            civentral_risk_level=decision.risk_level,
            predicted_at=datetime.now(timezone.utc),
            valid_from=request_data.valid_from,
            valid_until=request_data.valid_until,
            limitations=list(limitations),
        )

    return app


app = create_app()
