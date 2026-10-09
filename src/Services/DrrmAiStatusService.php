<?php

declare(strict_types=1);

namespace App\Services;

/** Combines process, task-model, policy, and trusted-input readiness. */
final class DrrmAiStatusService
{
    public function __construct(
        private readonly DrrmFloodRiskAiClient $client,
        private readonly ?DrrmRainfallInferenceClient $rainfallClient = null,
        private readonly ?DrrmFloodRiskPredictionService $inputService = null
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $health = $this->client->health();
        if (($health['available'] ?? false) !== true) {
            return $this->unavailableStatus(
                (string) ($health['code'] ?? 'AI_SERVICE_UNAVAILABLE'),
                (string) ($health['message'] ?? 'The private AI service could not be reached.')
            );
        }

        $readiness = $this->client->ready();
        $model = $this->client->modelStatus();
        $rainfall = $this->rainfallClient?->ready() ?? [
            'rainfall_model_ready' => false,
            'model_status' => 'UNKNOWN',
        ];
        $input = $this->inputService?->readiness() ?? [
            'input_data_ready' => false,
            'input_data_status' => 'UNKNOWN',
        ];

        $runtimeAvailable = ($health['tensorflow_runtime_available'] ?? false) === true;
        $floodModelReady = ($readiness['flood_risk_model_ready'] ?? false) === true
            && ($model['flood_risk_model_ready'] ?? false) === true
            && ($model['approved_for_inference'] ?? false) === true;
        $policyReady = ($readiness['threshold_policy_ready'] ?? false) === true
            && ($model['threshold_policy_ready'] ?? false) === true;
        $inputReady = ($input['input_data_ready'] ?? false) === true;
        $predictionReady = $runtimeAvailable && $floodModelReady && $policyReady && $inputReady;

        $modelAccessFailure = in_array($model['code'] ?? null, [
            'AI_SERVICE_NOT_CONFIGURED',
            'AI_AUTHENTICATION_FAILED',
            'AI_SERVICE_INVALID_RESPONSE',
            'AI_SERVICE_UNREACHABLE',
            'AI_SERVICE_ERROR',
        ], true);
        if ($predictionReady) {
            $code = 'PREDICTION_AVAILABLE';
            $message = 'Approved flood-risk decision support is available for officer review.';
        } elseif ($modelAccessFailure) {
            $code = (string) $model['code'];
            $message = (string) $model['message'];
        } elseif (!$runtimeAvailable) {
            $code = 'TENSORFLOW_RUNTIME_UNAVAILABLE';
            $message = 'The AI service is alive, but its TensorFlow runtime is unavailable.';
        } elseif (!$floodModelReady) {
            $code = 'TENSORFLOW_RUNTIME_AVAILABLE_BUT_MODEL_NOT_READY';
            $message = 'TensorFlow is available, but no approved flood-risk model is ready.';
        } elseif (!$policyReady) {
            $code = 'THRESHOLD_POLICY_NOT_READY';
            $message = 'The approved flood-risk classification policy is not ready.';
        } else {
            $code = 'INPUT_DATA_UNAVAILABLE';
            $message = (string) ($input['message']
                ?? 'Required trusted prediction inputs are unavailable.');
        }

        return [
            'runtime_reachable' => true,
            'service_health' => 'HEALTHY',
            'tensorflow_runtime_available' => $runtimeAvailable,
            'tensorflow_runtime_ready' => $runtimeAvailable,
            'tensorflow_installed' => $runtimeAvailable,
            'rainfall_model_ready' => ($rainfall['rainfall_model_ready'] ?? false) === true,
            'rainfall_model_status' => (string) ($rainfall['model_status'] ?? 'UNKNOWN'),
            'rainfall_research_only' => true,
            'flood_risk_model_ready' => $floodModelReady,
            'model_status' => (string) ($model['model_status']
                ?? $readiness['model_status'] ?? 'UNKNOWN'),
            'threshold_policy_ready' => $policyReady,
            'risk_policy_status' => (string) ($readiness['risk_policy_status'] ?? 'UNKNOWN'),
            'input_data_ready' => $inputReady,
            'input_data_status' => (string) ($input['input_data_status'] ?? 'UNKNOWN'),
            'prediction_ready' => $predictionReady,
            'code' => $code,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableStatus(string $sourceCode, string $sourceMessage): array
    {
        return [
            'runtime_reachable' => false,
            'service_health' => 'UNAVAILABLE',
            'tensorflow_runtime_available' => null,
            'tensorflow_runtime_ready' => null,
            'tensorflow_installed' => null,
            'rainfall_model_ready' => false,
            'rainfall_model_status' => 'UNKNOWN',
            'rainfall_research_only' => true,
            'flood_risk_model_ready' => false,
            'model_status' => 'UNKNOWN',
            'threshold_policy_ready' => false,
            'risk_policy_status' => 'UNKNOWN',
            'input_data_ready' => false,
            'input_data_status' => 'UNKNOWN',
            'prediction_ready' => false,
            'code' => in_array($sourceCode, [
                'AI_SERVICE_NOT_CONFIGURED',
                'AI_AUTHENTICATION_FAILED',
                'AI_SERVICE_INVALID_RESPONSE',
            ], true) ? $sourceCode : 'AI_SERVICE_UNAVAILABLE',
            'message' => $sourceMessage,
        ];
    }
}
