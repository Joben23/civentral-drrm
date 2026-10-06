<?php

declare(strict_types=1);

use App\Middleware\AdminSessionGuard;
use App\Services\AdminSessionManager;

require_once __DIR__ . '/../../src/Services/AdminSessionManager.php';
require_once __DIR__ . '/../../src/Middleware/AdminSessionGuard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Allow: GET, OPTIONS');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$manager = new AdminSessionManager();
$guard = new AdminSessionGuard($manager);
$status = $guard->status(false);
if ($status !== AdminSessionManager::STATUS_ACTIVE) {
    AdminSessionGuard::respondApiFailure($status);
}

echo json_encode([
    'success' => true,
    'status' => 'active',
    'expires_at' => $manager->expiresAt(),
]);
