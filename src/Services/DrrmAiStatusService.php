<?php

declare(strict_types=1);

namespace App\Services;

/** Combines process health and inference readiness without conflating them. */
final class DrrmAiStatusService
{
    public function __construct(private readonly DrrmFloodRiskAiClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $health = $this->client->health();
        if (($health['available'] ?? false) !== true) {
            return [
                'runtime_reachable' => false,
                'service_health' => 'UNAVAILABLE',
                'tensorflow_installed' => null,
                'model_status' => 'UNKNOWN',
                'risk_policy_status' => 'UNKNOWN',
                'prediction_ready' => false,
                'code' => $health['code'] ?? 'AI_SERVICE_ERROR',
                'message' => $health['message'] ?? 'The private AI service could not be reached.',
            ];
        }

        $readiness = $this->client->ready();
        $model = $this->client->modelStatus();
        $modelStatus = is_string($model['model_status'] ?? null)
            ? $model['model_status']
            : (string) ($readiness['model_status'] ?? $health['model_status']);
        $riskPolicyStatus = is_string($readiness['risk_policy_status'] ?? null)
            ? $readiness['risk_policy_status']
            : (string) $health['risk_policy_status'];
        $predictionReady = ($readiness['ready'] ?? false) === true
            && ($model['approved_for_inference'] ?? false) === true;
        $modelAccessFailure = in_array($model['code'] ?? null, [
            'AI_SERVICE_NOT_CONFIGURED',
            'AI_AUTHENTICATION_FAILED',
            'AI_SERVICE_INVALID_RESPONSE',
            'AI_SERVICE_UNREACHABLE',
            'AI_SERVICE_ERROR',
        ], true);
        $code = $predictionReady ? 'READY' : ($modelAccessFailure
            ? (string) $model['code']
            : (string) ($readiness['code'] ?? $model['code'] ?? 'AI_SERVICE_ERROR'));
        $message = $predictionReady
            ? 'Approved flood-risk inference is available.'
            : ($modelAccessFailure
                ? (string) $model['message']
                : (string) ($readiness['message'] ?? $model['message']
                    ?? 'TensorFlow flood-risk prediction is currently unavailable.'));

        return [
            'runtime_reachable' => true,
            'service_health' => 'HEALTHY',
            'tensorflow_installed' => $health['tensorflow_installed'],
            'model_status' => $modelStatus,
            'risk_policy_status' => $riskPolicyStatus,
            'prediction_ready' => $predictionReady,
            'code' => $code,
            'message' => $message,
        ];
    }
}
