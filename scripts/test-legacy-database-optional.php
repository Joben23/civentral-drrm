<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function assertLegacyDatabaseOptional(bool $condition, string $label): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($label . ' failed.');
    }
    $assertions++;
    echo $label, '=PASS', PHP_EOL;
}

function setLegacyTestEnvironment(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        return;
    }

    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

// Prevent this isolated test from reading the developer environment file.
if (!function_exists('loadEnv')) {
    function loadEnv(string $_path): void
    {
    }
}

setLegacyTestEnvironment('CIVENTRAL_LEGACY_DB_ENABLED', 'false');
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
    setLegacyTestEnvironment($name, null);
}

require_once $root . '/config/database.php';
require_once $root . '/src/Repositories/UserRepository.php';
require_once $root . '/src/Services/UserService.php';
require_once $root . '/src/Services/AuthService.php';
require_once $root . '/src/Services/HeaderService.php';
require_once $root . '/src/Services/DrrmIncidentAuthorizationService.php';
require_once $root . '/config/proxy.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

assertLegacyDatabaseOptional(!LegacyDatabaseConfig::isEnabled(), 'LegacyDatabaseDefaultsDisabled');
assertLegacyDatabaseOptional(legacyDatabaseIfEnabled() === null, 'DisabledLegacyDatabaseReturnsNull');

$disabledConstructorRejected = false;
try {
    Database::getInstance();
} catch (LegacyDatabaseConfigurationException) {
    $disabledConstructorRejected = true;
}
assertLegacyDatabaseOptional($disabledConstructorRejected, 'DisabledLegacyDatabaseRejectsDirectConnection');

setLegacyTestEnvironment('CIVENTRAL_LEGACY_DB_ENABLED', 'true');
$missingConfigurationRejected = false;
try {
    LegacyDatabaseConfig::connectionParameters();
} catch (LegacyDatabaseConfigurationException $exception) {
    $missingConfigurationRejected = str_contains($exception->getMessage(), 'DB_HOST');
}
assertLegacyDatabaseOptional($missingConfigurationRejected, 'EnabledLegacyDatabaseRequiresConfiguration');
setLegacyTestEnvironment('CIVENTRAL_LEGACY_DB_ENABLED', 'false');

$_SESSION = [
    'remote_phpsessid' => 'remote-session-test-marker',
    'user_granted_actions' => ['EDIT'],
    'user_granted_resources' => ['DRRM_INCIDENT_RESPONSE'],
    'user_permissions_map' => ['DRRM_INCIDENT_RESPONSE' => ['EDIT']],
];
$remoteSessionEstablished = establishRemoteEmployeeSession(
    ['user_id' => 'remote-subject-test-marker'],
    [
        'employee_id' => 'remote-employee-test-marker',
        'role_id' => 'remote-role-test-marker',
        'is_superadmin' => false,
        'remote_profile_test_marker' => true,
    ]
);
assertLegacyDatabaseOptional($remoteSessionEstablished, 'RemoteProfileEstablishesLocalSession');
assertLegacyDatabaseOptional(
    ($_SESSION['current_user_details']['remote_profile_test_marker'] ?? false) === true,
    'LocalSessionRetainsServerProfile'
);
assertLegacyDatabaseOptional(
    $_SESSION['user_granted_actions'] === []
    && $_SESSION['user_granted_resources'] === []
    && $_SESSION['user_permissions_map'] === [],
    'RemoteSessionClearsPriorRbac'
);
assertLegacyDatabaseOptional(
    establishRemoteEmployeeSession([], []) === false,
    'MissingRemoteProfileCannotEstablishSession'
);
clearRemoteEmployeeAuthentication();
assertLegacyDatabaseOptional(
    empty($_SESSION['user_id']) && empty($_SESSION['employee_id']),
    'FailedHydrationCleanupRemovesLocalIdentity'
);

$_SESSION = [
    'current_user_details' => [
        'remote_profile_test_marker' => true,
    ],
];

$fallbackProbe = new class {
    public int $calls = 0;

    public function getUserWithRelations(mixed $_userId, mixed $_employeeId = null): ?array
    {
        $this->calls++;
        throw new RuntimeException('The legacy fallback must not run for a hydrated remote profile.');
    }
};
$userService = new \App\Services\UserService($fallbackProbe);
$profile = $userService->getCurrentUserDetails(null, null);
assertLegacyDatabaseOptional(
    ($profile['remote_profile_test_marker'] ?? false) === true,
    'RemoteProfileRemainsPrimary'
);
assertLegacyDatabaseOptional($fallbackProbe->calls === 0, 'RemoteProfileSkipsLegacyProvider');

$providerCalls = 0;
$repository = new \App\Repositories\UserRepository(
    static function () use (&$providerCalls) {
        $providerCalls++;
        return null;
    }
);
assertLegacyDatabaseOptional(
    $repository->getUserWithRelations(null, null) === null,
    'DisabledFallbackCreatesNoIdentity'
);
assertLegacyDatabaseOptional($providerCalls === 1, 'LegacyProviderResolvesOnlyOnFallback');

$_SESSION = [
    'user_granted_actions' => ['VIEW'],
    'user_granted_resources' => ['DRRM_INCIDENT_RESPONSE'],
    'user_permissions_map' => ['DRRM_INCIDENT_RESPONSE' => ['VIEW']],
];
$headerBuilder = new \App\Services\HeaderService(
    new class {
        public function getCurrentUserDetails(mixed $_userId, mixed $_employeeId): ?array
        {
            return null;
        }
    },
    new class {
    },
    new class {
        public function isLoggedIn(): bool
        {
            return true;
        }
    }
);
$headerUser = $headerBuilder->buildHeaderUser();
assertLegacyDatabaseOptional(
    $_SESSION['user_granted_actions'] === []
    && $_SESSION['user_granted_resources'] === []
    && $_SESSION['user_permissions_map'] === [],
    'MissingRemoteProfileClearsStaleRbac'
);
assertLegacyDatabaseOptional(
    ($headerUser['is_superadmin'] ?? true) === false,
    'MissingRemoteProfileGrantsNoSuperadmin'
);

$_SESSION = [];
$authService = new \App\Services\AuthService();
assertLegacyDatabaseOptional(!$authService->isLoggedIn(), 'MissingDatabaseDoesNotAuthenticate');

$_SESSION = [
    'current_user_details' => ['is_global_access' => true],
    'user_permissions_map' => [],
];
$authorization = \App\Services\DrrmIncidentAuthorizationService::fromTrustedSession();
assertLegacyDatabaseOptional(!$authorization->canView(), 'RbacRejectsUnmappedGlobalAccess');
assertLegacyDatabaseOptional(!$authorization->isSuperadmin(), 'RbacHasNoSuperadminFallback');

$_SESSION['user_permissions_map'] = [
    \App\Services\DrrmIncidentAuthorizationService::RESOURCE => ['VIEW'],
];
$authorization = \App\Services\DrrmIncidentAuthorizationService::fromTrustedSession();
assertLegacyDatabaseOptional($authorization->canView(), 'RbacUsesServerSessionPermissionMap');

$bootstrap = file_get_contents($root . '/src/bootstrap.php');
$employeeLogin = file_get_contents($root . '/api/employee/login.php');
$employeeOtp = file_get_contents($root . '/api/employee/verify-otp.php');
$headerService = file_get_contents($root . '/src/Services/HeaderService.php');
$module3 = file_get_contents($root . '/assets/js/incident-response.js');
$departmentsProxy = file_get_contents($root . '/api/employee/departments.php');
$usersProxy = file_get_contents($root . '/api/employee/users.php');
$browserLogin = file_get_contents($root . '/assets/js/login.js');
$drrmBootstrap = file_get_contents($root . '/api/drrm/_bootstrap.php');
$compose = file_get_contents($root . '/docker-compose.yml');

assertLegacyDatabaseOptional(
    is_string($bootstrap)
    && str_contains($bootstrap, 'legacyDatabaseProvider')
    && str_contains($bootstrap, '$authService->requireAuth($currentBasePath)')
    && !str_contains($bootstrap, 'Database::getInstance()'),
    'BootstrapRequiresSessionWithoutEagerDatabase'
);
assertLegacyDatabaseOptional(
    is_string($employeeLogin)
    && str_contains($employeeLogin, "proxyRequest(\$remoteUrl")
    && str_contains($employeeLogin, 'hydrateRemoteEmployeeSession')
    && !str_contains($employeeLogin, 'config/database.php'),
    'EmployeeLoginHydratesRemoteSessionWithoutDatabase'
);
assertLegacyDatabaseOptional(
    is_string($employeeOtp)
    && str_contains($employeeOtp, 'hydrateRemoteEmployeeSession')
    && str_contains($employeeOtp, 'clearRemoteEmployeeAuthentication'),
    'RemoteOtpStillHydratesServerSession'
);
assertLegacyDatabaseOptional(
    is_string($headerService)
    && str_contains($headerService, "'/permissions.php'")
    && str_contains($headerService, "\$_SESSION['user_permissions_map']")
    && !str_contains($headerService, 'rolePrefixUpper')
    && !str_contains($headerService, "roleNameLower"),
    'PermissionsRemainServerDerived'
);
assertLegacyDatabaseOptional(
    is_string($module3)
    && str_contains($module3, '../../api/employee/departments.php')
    && str_contains($module3, '../../api/employee/users.php'),
    'Module3UsesEmployeeReferenceApis'
);
assertLegacyDatabaseOptional(
    is_string($departmentsProxy)
    && is_string($usersProxy)
    && str_contains($departmentsProxy, 'EXPO_PUBLIC_API_BASE_URL')
    && str_contains($usersProxy, 'EXPO_PUBLIC_API_BASE_URL')
    && !str_contains($departmentsProxy, 'config/database.php')
    && !str_contains($usersProxy, 'config/database.php'),
    'Module3ReferenceApisRemainExternal'
);
assertLegacyDatabaseOptional(
    is_string($browserLogin)
    && !str_contains($browserLogin, 'validIds')
    && !str_contains($browserLogin, 'Fallback for direct offline'),
    'BrowserHasNoOfflineCredentialFallback'
);
assertLegacyDatabaseOptional(
    is_string($drrmBootstrap)
    && str_contains($drrmBootstrap, 'SupabaseConfig')
    && !str_contains($drrmBootstrap, 'config/database.php'),
    'DrrmSupabaseBootstrapUnaffected'
);
assertLegacyDatabaseOptional(
    is_string($compose)
    && str_contains(
        $compose,
        'CIVENTRAL_LEGACY_DB_ENABLED: ${CIVENTRAL_LEGACY_DB_ENABLED:-false}'
    )
    && !str_contains($compose, '${DB_HOST:?')
    && !str_contains($compose, '${DB_PORT:?')
    && !str_contains($compose, '${DB_NAME:?')
    && !str_contains($compose, '${DB_USER:?')
    && !str_contains($compose, '${DB_PASSWORD:?'),
    'ComposeDoesNotRequireDisabledLegacyDatabase'
);

$aiServiceOffset = is_string($compose) ? strpos($compose, "\n  flood-risk-ai:\n") : false;
$aiServiceCompose = $aiServiceOffset === false ? '' : substr($compose, $aiServiceOffset);
assertLegacyDatabaseOptional(
    is_string($compose)
    && !preg_match('/^[[:space:]]+ports:/m', $compose)
    && str_contains($compose, 'CIVENTRAL_AI_BASE_URL: http://flood-risk-ai:8098')
    && str_contains($aiServiceCompose, '- 8098'),
    'ComposeKeepsAiOnServiceNetwork'
);
assertLegacyDatabaseOptional(
    $aiServiceCompose !== ''
    && !str_contains($aiServiceCompose, 'SUPABASE_')
    && !str_contains($aiServiceCompose, 'DB_HOST')
    && !str_contains($aiServiceCompose, 'EXPO_PUBLIC_API_BASE_URL')
    && !str_contains($aiServiceCompose, 'CITIZEN_'),
    'ComposeKeepsWebSecretsOutOfAiService'
);

echo 'LegacyDatabaseOptionalAssertions=', $assertions, PHP_EOL;
echo 'LegacyDatabaseOptional=PASS', PHP_EOL;
