<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmReliefGoodsAuthorizationService;
use App\Services\DrrmReliefGoodsAuthorizationException;
use App\Services\DrrmReliefGoodsCsrfException;
use App\Services\DrrmReliefGoodsCsrfService;
use App\Services\DrrmReliefGoodsService;
use App\Services\DrrmReliefGoodsValidationException;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../src/Services/DrrmReliefGoodsAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmReliefGoodsCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmReliefGoodsService.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reliefResponse(bool $success, mixed $data = null, string $message = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode($success ? ['success' => true, 'data' => $data ?? []] : ['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$auth = new AuthService();
if (!$auth->isLoggedIn()) reliefResponse(false, null, 'Authentication required.', 401);
$authorization = DrrmReliefGoodsAuthorizationService::fromTrustedSession();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
try {
    if ($method === 'GET') $authorization->requireAction(DrrmReliefGoodsAuthorizationService::ACTION_VIEW);
    elseif ($method === 'POST') {
        $authorization->requireAction(DrrmReliefGoodsAuthorizationService::ACTION_CREATE);
        try { (new DrrmReliefGoodsCsrfService())->requireValidHeader(); } catch (DrrmReliefGoodsCsrfException) { reliefResponse(false, null, 'Security token expired. Refresh and try again.', 403); }
    } else reliefResponse(false, null, 'Method not allowed.', 405);

    $service = new DrrmReliefGoodsService(new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env')));
    if ($method === 'GET') {
        $inventory = $service->inventory();
        $distributions = $service->distributions();
        reliefResponse(true, ['summary' => $service->summary($inventory, $distributions), 'inventory' => $inventory, 'distributions' => $distributions, 'destinations' => $service->destinations(), 'capabilities' => $authorization->capabilities()]);
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['action'])) reliefResponse(false, null, 'Invalid request.', 400);
    $details = $_SESSION['current_user_details'] ?? [];
    $actor = (string) ($auth->currentUserId() ?? ($details['full_name'] ?? 'authenticated-user'));
    $result = match ($payload['action']) {
        'create_item' => $service->createItem($payload),
        'receive' => $service->receive($payload, $actor),
        'release' => $service->release($payload, $actor),
        default => throw new DrrmReliefGoodsValidationException('Invalid request action.'),
    };
    reliefResponse(true, $result, '', 201);
} catch (DrrmReliefGoodsValidationException $exception) {
    reliefResponse(false, null, $exception->getMessage(), 422);
} catch (DrrmReliefGoodsAuthorizationException) {
    reliefResponse(false, null, 'Module 2 permission required.', 403);
} catch (Throwable) {
    reliefResponse(false, null, 'Request could not be completed.', 502);
}