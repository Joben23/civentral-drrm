<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmBarangayCoordinationAuthorizationException;
use App\Services\DrrmBarangayCoordinationAuthorizationService;
use App\Services\DrrmBarangayCoordinationCsrfException;
use App\Services\DrrmBarangayCoordinationCsrfService;
use App\Services\DrrmBarangayCoordinationService;
use App\Services\DrrmBarangayCoordinationValidationException;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../src/Services/DrrmBarangayCoordinationAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmBarangayCoordinationCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmBarangayCoordinationService.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function barangayCoordinationResponse(bool $success, mixed $data = null, string $message = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode($success ? ['success' => true, 'data' => $data ?? []] : ['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthService();
if (!$auth->isLoggedIn()) {
    barangayCoordinationResponse(false, null, 'Authentication required.', 401);
}

$authorization = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $authorization->requireAction(DrrmBarangayCoordinationAuthorizationService::ACTION_VIEW);
    } elseif ($method === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload) || !isset($payload['action'])) {
            barangayCoordinationResponse(false, null, 'Invalid request.', 400);
        }

        $authorizedAction = match ($payload['action']) {
            'create_situation_report' => DrrmBarangayCoordinationAuthorizationService::ACTION_CREATE,
            'create_assistance_request' => DrrmBarangayCoordinationAuthorizationService::ACTION_CREATE,
            'transition_assistance_request' => DrrmBarangayCoordinationAuthorizationService::ACTION_EDIT,
            default => throw new DrrmBarangayCoordinationValidationException('Invalid request action.'),
        };
        $authorization->requireAction($authorizedAction);

        try {
            (new DrrmBarangayCoordinationCsrfService())->requireValidHeader();
        } catch (DrrmBarangayCoordinationCsrfException) {
            barangayCoordinationResponse(false, null, 'Security token expired. Refresh and try again.', 403);
        }
    } else {
        barangayCoordinationResponse(false, null, 'Method not allowed.', 405);
    }

    $service = new DrrmBarangayCoordinationService(new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env')));

    if ($method === 'GET') {
        $payload = [];
        $payload['summary'] = $service->summary();
        $payload['barangays'] = $service->availableBarangays();
        $payload['current_situations'] = $service->currentSituations();
        $payload['situation_history'] = $service->situationHistory();
        $payload['assistance_requests'] = $service->assistanceRequests();
        $payload['assistance_request_history'] = $service->assistanceRequestHistory();
        $payload['capabilities'] = $authorization->capabilities();
        barangayCoordinationResponse(true, $payload, '', 200);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['action'])) {
        barangayCoordinationResponse(false, null, 'Invalid request.', 400);
    }

    $actor = (string) ($auth->currentUserId() ?? '');
    if ($actor === '') {
        barangayCoordinationResponse(false, null, 'Authentication required.', 401);
    }

    $result = match ($payload['action']) {
        'create_situation_report' => $service->createStatusReport($payload, $actor),
        'create_assistance_request' => $service->createAssistanceRequest($payload, $actor),
        'transition_assistance_request' => $service->transitionAssistanceRequest($payload, $actor),
        default => throw new DrrmBarangayCoordinationValidationException('Invalid request action.'),
    };

    barangayCoordinationResponse(true, $result, '', 201);
} catch (DrrmBarangayCoordinationValidationException $exception) {
    barangayCoordinationResponse(false, null, $exception->getMessage(), 422);
} catch (DrrmBarangayCoordinationAuthorizationException) {
    barangayCoordinationResponse(false, null, 'Module 5 permission required.', 403);
} catch (Throwable) {
    barangayCoordinationResponse(false, null, 'Request could not be completed.', 502);
}
