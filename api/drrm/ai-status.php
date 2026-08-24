<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/ai.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmFloodRiskAiClient.php';
require_once __DIR__ . '/../../src/Services/DrrmAiStatusService.php';

use App\Config\AiServiceConfig;
use App\Services\AuthService;
use App\Services\DrrmAiStatusService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmFloodRiskAiClient;

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: GET, OPTIONS');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}

if (!empty($_GET)) {
    drrmApiRespond(false, null, 'Query parameters are not accepted.', 400);
}

$authService = new AuthService();
if (!$authService->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication is required.', 401);
}

$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
if (!$authorization->canView()) {
    drrmApiRespond(false, null, 'You are not authorized to view AI service status.', 403);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $config = AiServiceConfig::fromEnvironment(__DIR__ . '/../../.env');
    $status = (new DrrmAiStatusService(new DrrmFloodRiskAiClient($config)))->status();
} catch (Throwable $exception) {
    $status = [
        'runtime_reachable' => false,
        'service_health' => 'UNKNOWN',
        'tensorflow_installed' => null,
        'model_status' => 'UNKNOWN',
        'risk_policy_status' => 'UNKNOWN',
        'prediction_ready' => false,
        'code' => 'AI_SERVICE_NOT_CONFIGURED',
        'message' => 'The private AI service is not configured for server-side access.',
    ];
}

drrmApiRespond(true, $status);
