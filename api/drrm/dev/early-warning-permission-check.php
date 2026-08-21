<?php

declare(strict_types=1);

use App\Config\AppEnvironment;

require_once __DIR__ . '/../../../config/app_environment.php';

ini_set('display_errors', '0');

$environmentAllowed = AppEnvironment::allowsLocalDevelopmentRequest(
    __DIR__ . '/../../../.env',
    $_SERVER
);

if (!$environmentAllowed) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    echo '{"success":false,"message":"Not found."}';
    exit;
}

require_once __DIR__ . '/../_bootstrap.php';

drrmApiSendHeaders();
header('Allow: GET');
header('X-Robots-Tag: noindex, nofollow');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}

$authService = new \App\Services\AuthService();

if (!$authService->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

// Refresh the trusted server-side user and permission context so this probe
// reflects permission changes without accepting any browser-supplied scope.
$basePath = '../../../';
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Services/DrrmEarlyWarningAuthorizationService.php';

$authorization = \App\Services\DrrmEarlyWarningAuthorizationService::fromTrustedSession($headerUser);
$capabilities = $authorization->capabilities();
$permissionResults = [
    'VIEW' => $capabilities['canView'],
    'CREATE_WARNING' => $capabilities['canCreateWarning'],
    'ACTIVATE_WARNING' => $capabilities['canActivateWarning'],
    'CANCEL_WARNING' => $capabilities['canCancelWarning'],
];
$hasAllManagementPermissions = !in_array(false, $permissionResults, true);

drrmApiRespond(true, [
    'authenticated' => true,
    'resource' => \App\Services\DrrmEarlyWarningAuthorizationService::RESOURCE,
    'resource_present' => $authorization->hasModuleResource(),
    'is_superadmin' => $authorization->isSuperadmin(),
    'is_global_access' => filter_var(
        $headerUser['is_global_access'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    ),
    'permissions' => $permissionResults,
    'ready_for_module4_security' => $authorization->isSuperadmin() || $hasAllManagementPermissions,
]);
