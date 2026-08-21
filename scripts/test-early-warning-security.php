<?php

declare(strict_types=1);

use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningCsrfService;

require_once __DIR__ . '/../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningCsrfService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

$testSessionPath = sys_get_temp_dir();
if (!is_dir($testSessionPath) || !is_writable($testSessionPath)) {
    fwrite(STDERR, "A writable temporary directory is required for the isolated test session.\n");
    exit(1);
}

session_save_path($testSessionPath);
session_id('module4-security-' . bin2hex(random_bytes(8)));
session_start();

$failures = [];

/** @param mixed $actual */
function assertSecurityResult(string $name, mixed $actual, mixed $expected): void
{
    global $failures;

    $passed = $actual === $expected;
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;

    if (!$passed) {
        $failures[] = $name;
    }
}

$_SESSION = [
    'current_user_details' => [
        'is_superadmin' => false,
        'is_global_access' => true,
    ],
    'user_permissions_map' => [
        DrrmEarlyWarningAuthorizationService::RESOURCE => ['VIEW'],
    ],
];

$viewOnly = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
assertSecurityResult('ViewOnlyCanView', $viewOnly->canView(), true);
assertSecurityResult('ViewOnlyCanCreate', $viewOnly->canCreateWarning(), false);
assertSecurityResult('ViewOnlyCanActivate', $viewOnly->canActivateWarning(), false);
assertSecurityResult('ViewOnlyCanCancel', $viewOnly->canCancelWarning(), false);

$_SESSION['user_permissions_map'] = [];
$globalOnly = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
assertSecurityResult('GlobalAccessAloneCanView', $globalOnly->canView(), false);
assertSecurityResult('GlobalAccessAloneCanCreate', $globalOnly->canCreateWarning(), false);

$_SESSION['current_user_details']['is_superadmin'] = true;
$superadmin = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
assertSecurityResult('SuperadminCanView', $superadmin->canView(), true);
assertSecurityResult('SuperadminCanCreate', $superadmin->canCreateWarning(), true);
assertSecurityResult('SuperadminCanActivate', $superadmin->canActivateWarning(), true);
assertSecurityResult('SuperadminCanCancel', $superadmin->canCancelWarning(), true);

$_SESSION['current_user_details']['is_superadmin'] = false;
$_SESSION['user_permissions_map'] = [
    'disaster early warning' => [
        'VIEW',
        'CREATE_WARNING',
        'ACTIVATE_WARNING',
        'CANCEL_WARNING',
    ],
];
$wrongResource = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
assertSecurityResult('ResourceAliasRejected', $wrongResource->canView(), false);

$_SESSION['user_permissions_map'] = [
    DrrmEarlyWarningAuthorizationService::RESOURCE => [
        'VIEW',
        'CREATE_WARNING',
        'ACTIVATE_WARNING',
        'CANCEL_WARNING',
    ],
];

$allPermissions = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
assertSecurityResult('ExactResourcePresent', $allPermissions->hasModuleResource(), true);
assertSecurityResult('ExactCreateAllowed', $allPermissions->canCreateWarning(), true);
assertSecurityResult('ExactActivateAllowed', $allPermissions->canActivateWarning(), true);
assertSecurityResult('ExactCancelAllowed', $allPermissions->canCancelWarning(), true);

$csrf = new DrrmEarlyWarningCsrfService();
$firstToken = $csrf->token();
assertSecurityResult('CsrfEncodedLength', strlen($firstToken), 43);
assertSecurityResult('CsrfValid', $csrf->validate($firstToken), true);
assertSecurityResult('CsrfMissingRejected', $csrf->validate(null), false);
assertSecurityResult('CsrfInvalidRejected', $csrf->validate('invalid-token'), false);

$_SESSION['drrm_early_warning_csrf']['issued_at'] = time()
    - DrrmEarlyWarningCsrfService::TOKEN_TTL_SECONDS;
assertSecurityResult('CsrfExpiredRejected', $csrf->validate($firstToken), false);

$replacementToken = $csrf->token();
assertSecurityResult('CsrfExpiredRegenerated', $replacementToken !== $firstToken, true);
assertSecurityResult('CsrfReplacementValid', $csrf->validate($replacementToken), true);

$_SESSION = [];
session_destroy();

if ($failures !== []) {
    fwrite(STDERR, 'Security test failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Module4SecurityFoundation=PASS\n";
