<?php

declare(strict_types=1);

use App\Middleware\AdminSessionGuard;
use App\Services\AdminSessionManager;

require_once __DIR__ . '/../../src/Services/AdminSessionManager.php';
require_once __DIR__ . '/../../src/Middleware/AdminSessionGuard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Allow: POST, OPTIONS');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

if (strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'REQUEST_NOT_ALLOWED']);
    exit;
}

$manager = new AdminSessionManager();
$guard = new AdminSessionGuard($manager);
$guard->requireApi();

echo json_encode([
    'success' => true,
    'status' => 'active',
    'expires_at' => $manager->expiresAt(),
]);
