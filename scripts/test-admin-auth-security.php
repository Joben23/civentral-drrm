<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\RecaptchaConfig;
use App\Services\AdminSessionManager;
use App\Services\RecaptchaVerificationResult;
use App\Services\RecaptchaVerifier;

$root = dirname(__DIR__);

require_once $root . '/src/Services/AdminSessionManager.php';
require_once $root . '/config/recaptcha.php';
require_once $root . '/src/Services/RecaptchaVerifier.php';

ob_start();
$results = [];

function adminSecurityTest(string $name, bool $passed): void
{
    global $results;
    $results[$name] = $passed;
}

function adminSecuritySource(string $relativePath): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relativePath);
    if (!is_string($source)) {
        throw new RuntimeException('Missing test source: ' . $relativePath);
    }

    return $source;
}

/** @return array{output:string,error:string,exit_code:int} */
function adminSecurityChild(string $code): array
{
    global $root;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start an isolated PHP security test.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'output' => is_string($output) ? $output : '',
        'error' => is_string($error) ? $error : '',
        'exit_code' => $exitCode,
    ];
}

function setAdminSecurityEnvironment(string $name, ?string $value): void
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

function employeeSessionState(int $lastActivity): array
{
    return [
        'admin_auth_context' => 'employee',
        'user_id' => 'security-test-user',
        'employee_id' => 'security-test-employee',
        'LAST_ACTIVITY' => $lastActivity,
    ];
}

// Exact idle-timeout semantics and authenticated activity refresh.
$clock = 1000;
$sessions = new AdminSessionManager(static fn (): int => $clock);

$state = employeeSessionState(1000);
adminSecurityTest(
    'SessionZeroSecondsAccepted',
    $sessions->evaluate($state) === AdminSessionManager::STATUS_ACTIVE
        && $state['LAST_ACTIVITY'] === 1000
);

$state = employeeSessionState(821);
adminSecurityTest(
    'Session179SecondsAcceptedAndRefreshed',
    $sessions->evaluate($state) === AdminSessionManager::STATUS_ACTIVE
        && $state['LAST_ACTIVITY'] === 1000
);

$state = employeeSessionState(820);
adminSecurityTest(
    'SessionExactly180SecondsExpired',
    $sessions->evaluate($state) === AdminSessionManager::STATUS_EXPIRED
        && $state['LAST_ACTIVITY'] === 820
);

$state = employeeSessionState(819);
adminSecurityTest(
    'SessionOver180SecondsExpired',
    $sessions->evaluate($state) === AdminSessionManager::STATUS_EXPIRED
);

$state = employeeSessionState(821);
adminSecurityTest(
    'SessionStatusCheckDoesNotRefresh',
    $sessions->evaluate($state, false) === AdminSessionManager::STATUS_ACTIVE
        && $state['LAST_ACTIVITY'] === 821
);

$state = ['user_id' => 'security-test-user', 'LAST_ACTIVITY' => 1000];
adminSecurityTest(
    'CitizenOrLegacyIdentityCannotBecomeAdminSession',
    $sessions->evaluate($state) === AdminSessionManager::STATUS_UNAUTHENTICATED
);

adminSecurityTest('SingleAuthoritativeTimeoutConstant', AdminSessionManager::IDLE_TIMEOUT_SECONDS === 180);

// Stable protected-API failure behavior, exercised in isolated sessions because
// the guard intentionally exits after sending its response.
$expiredApiCode = sprintf(
    'require_once %s; require_once %s;'
    . '$_SERVER["SCRIPT_NAME"]="/api/security-test.php";'
    . '$_SERVER["REQUEST_URI"]="/api/security-test.php";'
    . '\\App\\Services\\AdminSessionManager::start();'
    . '$_SESSION=["admin_auth_context"=>"employee","user_id"=>"test","LAST_ACTIVITY"=>time()-180];'
    . 'register_shutdown_function(static function(){echo "\\n__HTTP_STATUS__=".(string)http_response_code();});'
    . '(new \\App\\Middleware\\AdminSessionGuard())->requireApi();',
    var_export($root . '/src/Services/AdminSessionManager.php', true),
    var_export($root . '/src/Middleware/AdminSessionGuard.php', true)
);
$expiredApi = adminSecurityChild($expiredApiCode);
adminSecurityTest(
    'ProtectedApiExpiredStable401',
    str_contains($expiredApi['output'], '"code":"SESSION_EXPIRED"')
        && str_contains($expiredApi['output'], '__HTTP_STATUS__=401')
        && !str_contains($expiredApi['output'], '<html')
);

$unauthenticatedApiCode = sprintf(
    'require_once %s; require_once %s;'
    . '$_SERVER["SCRIPT_NAME"]="/api/security-test.php";'
    . '$_SERVER["REQUEST_URI"]="/api/security-test.php";'
    . '\\App\\Services\\AdminSessionManager::start(); $_SESSION=[];'
    . 'register_shutdown_function(static function(){echo "\\n__HTTP_STATUS__=".(string)http_response_code();});'
    . '(new \\App\\Middleware\\AdminSessionGuard())->requireApi();',
    var_export($root . '/src/Services/AdminSessionManager.php', true),
    var_export($root . '/src/Middleware/AdminSessionGuard.php', true)
);
$unauthenticatedApi = adminSecurityChild($unauthenticatedApiCode);
adminSecurityTest(
    'ProtectedApiUnauthenticatedDenied',
    str_contains($unauthenticatedApi['output'], '"code":"AUTHENTICATION_REQUIRED"')
        && str_contains($unauthenticatedApi['output'], '__HTTP_STATUS__=401')
);

$pageBootstrap = adminSecuritySource('src/bootstrap.php');
$guardSource = adminSecuritySource('src/Middleware/AdminSessionGuard.php');
$drrmBootstrap = adminSecuritySource('api/drrm/_bootstrap.php');
adminSecurityTest(
    'ExpiredProtectedPageRedirectsToLogin',
    str_contains($pageBootstrap, '$adminSessionGuard->requirePage(')
        && str_contains($guardSource, 'AdminSessionManager::STATUS_EXPIRED')
        && str_contains($guardSource, 'login.php' . "'" . ' . $reason')
        && str_contains($guardSource, '?reason=session_expired')
);
adminSecurityTest(
    'DrrmApisUseCentralGuard',
    str_contains($drrmBootstrap, 'AdminSessionManager::start();')
        && str_contains($drrmBootstrap, '(new AdminSessionGuard())->requireApi();')
        && str_contains(adminSecuritySource('api/drrm/barangay-coordination.php'), '(new AdminSessionGuard())->requireApi();')
        && str_contains(adminSecuritySource('api/drrm/relief-goods.php'), '(new AdminSessionGuard())->requireApi();')
);

$drrmApiCoverage = true;
$drrmApiIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api/drrm', FilesystemIterator::SKIP_DOTS)
);
foreach ($drrmApiIterator as $drrmApiFile) {
    if (!$drrmApiFile->isFile()
        || strtolower($drrmApiFile->getExtension()) !== 'php'
        || $drrmApiFile->getFilename() === '_bootstrap.php') {
        continue;
    }
    $relativeDrrmApi = str_replace('\\', '/', substr($drrmApiFile->getPathname(), strlen($root) + 1));
    $drrmApiSource = adminSecuritySource($relativeDrrmApi);
    $drrmApiCoverage = $drrmApiCoverage && (
        str_contains($drrmApiSource, '_bootstrap.php')
        || str_contains($drrmApiSource, '(new AdminSessionGuard())->requireApi();')
    );
}
adminSecurityTest('EveryDrrmApiReachesCentralGuard', $drrmApiCoverage);

$employeeApiFiles = [
    'access-control.php',
    'actions.php',
    'audit-logs.php',
    'change-password.php',
    'departments.php',
    'get-profile.php',
    'login-history.php',
    'modules.php',
    'permissions.php',
    'profile.php',
    'resources.php',
    'roles.php',
    'users.php',
];
$employeeApiCoverage = true;
foreach ($employeeApiFiles as $employeeApiFile) {
    $employeeApiCoverage = $employeeApiCoverage
        && str_contains(adminSecuritySource('api/employee/' . $employeeApiFile), "require_once __DIR__ . '/_admin-session.php';");
}
adminSecurityTest('EmployeeApisUseCentralGuard', $employeeApiCoverage);

$adminPageCoverage = true;
$pageIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/pages', FilesystemIterator::SKIP_DOTS)
);
foreach ($pageIterator as $pageFile) {
    if (!$pageFile->isFile()
        || strtolower($pageFile->getExtension()) !== 'php'
        || $pageFile->getFilename() === 'logout.php') {
        continue;
    }
    $relativePage = str_replace('\\', '/', substr($pageFile->getPathname(), strlen($root) + 1));
    $pageSource = adminSecuritySource($relativePage);
    $adminPageCoverage = $adminPageCoverage && (
        str_contains($pageSource, 'includes/header.php')
        || str_contains($pageSource, 'src/bootstrap.php')
        || str_contains($pageSource, "include __DIR__ . '/action-management.php';")
        || str_contains($pageSource, "include __DIR__ . '/resource-management.php';")
    );
}
adminSecurityTest('EveryAdminPageReachesCentralGuard', $adminPageCoverage);

$inactivity = adminSecuritySource('assets/js/header/inactivity.js');
$header = adminSecuritySource('includes/header.php');
$touchEndpoint = adminSecuritySource('api/employee/session-touch.php');
$statusEndpoint = adminSecuritySource('api/employee/session-status.php');
adminSecurityTest(
    'BrowserWarningUsesServerExpiry',
    str_contains($inactivity, 'SESSION_WARNING_SECONDS = 60')
        && str_contains($inactivity, 'X-Civentral-Session-Expires-At')
        && str_contains($header, 'Your session will expire soon due to inactivity.')
);
adminSecurityTest(
    'BrowserTimeoutUsesThreeMinuteServerDeadline',
    str_contains($header, 'id="inactivityCountdown">03:00</span>')
        && str_contains($header, '$adminSessionExpiresAt * 1000')
        && str_contains($inactivity, 'Number(window.civentralSessionExpiresAt)')
        && str_contains($inactivity, 'SESSION_WARNING_SECONDS = 60')
);
adminSecurityTest(
    'BrowserTimerCannotReviveExpiredSession',
    str_contains($inactivity, 'if (!sessionExpiryAt || now >= sessionExpiryAt)')
        && str_contains($inactivity, 'void confirmServerSession();')
        && str_contains($statusEndpoint, '$guard->status(false);')
        && !str_contains($inactivity, 'resetInactivityTimer')
);
adminSecurityTest(
    'ActivityTouchIsThrottledAndAuthenticated',
    str_contains($inactivity, 'SESSION_TOUCH_THROTTLE_MS = 30000')
        && str_contains($inactivity, "credentials: 'same-origin'")
        && str_contains($inactivity, "'X-Requested-With': 'XMLHttpRequest'")
        && str_contains($touchEndpoint, "'REQUEST_NOT_ALLOWED'")
);

$localHttpCookieCode = sprintf(
    'require_once %s; $_SERVER["HTTPS"]="off"; $_SERVER["SERVER_PORT"]=80;'
    . '\\App\\Services\\AdminSessionManager::start(); $params=session_get_cookie_params();'
    . '(new \\App\\Services\\AdminSessionManager())->invalidate();'
    . 'echo json_encode(["secure"=>$params["secure"],"httponly"=>$params["httponly"],'
    . '"samesite"=>$params["samesite"],"lifetime"=>$params["lifetime"],'
    . '"strict"=>ini_get("session.use_strict_mode"),"cookies"=>ini_get("session.use_only_cookies")]);',
    var_export($root . '/src/Services/AdminSessionManager.php', true)
);
$localHttpCookie = adminSecurityChild($localHttpCookieCode);
$localHttpCookiePayload = json_decode($localHttpCookie['output'], true);
adminSecurityTest(
    'LocalHttpCookieRemainsUsableAndHardened',
    is_array($localHttpCookiePayload)
        && $localHttpCookiePayload['secure'] === false
        && $localHttpCookiePayload['httponly'] === true
        && strcasecmp((string) $localHttpCookiePayload['samesite'], 'Lax') === 0
        && $localHttpCookiePayload['lifetime'] === 0
        && $localHttpCookiePayload['strict'] === '1'
        && $localHttpCookiePayload['cookies'] === '1'
);

// reCAPTCHA verification is tested through an injected transport. No Google
// request is made by this suite.
$environmentNames = [
    'RECAPTCHA_SITE_KEY',
    'RECAPTCHA_SECRET_KEY',
    'RECAPTCHA_ALLOWED_HOSTNAMES',
    'RECAPTCHA_CONNECT_TIMEOUT_MS',
    'RECAPTCHA_REQUEST_TIMEOUT_MS',
];
$savedEnvironment = [];
foreach ($environmentNames as $environmentName) {
    $savedEnvironment[$environmentName] = getenv($environmentName);
}

try {
    setAdminSecurityEnvironment('RECAPTCHA_SITE_KEY', 'site-key-for-test');
    setAdminSecurityEnvironment('RECAPTCHA_SECRET_KEY', 'secret-key-for-test');
    setAdminSecurityEnvironment('RECAPTCHA_ALLOWED_HOSTNAMES', 'admin.example.test');
    setAdminSecurityEnvironment('RECAPTCHA_CONNECT_TIMEOUT_MS', '900');
    setAdminSecurityEnvironment('RECAPTCHA_REQUEST_TIMEOUT_MS', '1800');
    $recaptchaConfig = RecaptchaConfig::fromEnvironment();

    $transportCalls = 0;
    $validVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static function (string $url, array $fields, RecaptchaConfig $config) use (&$transportCalls): array {
            $transportCalls++;
            $contractValid = $url === RecaptchaVerifier::VERIFY_URL
                && $fields['secret'] === $config->secretKey()
                && $fields['response'] === 'valid-token';
            return [
                'status' => $contractValid ? 200 : 500,
                'body' => '{"success":true,"hostname":"admin.example.test"}',
                'transport_error' => false,
            ];
        }
    );
    adminSecurityTest(
        'RecaptchaValidVerificationAccepted',
        $validVerifier->verify('valid-token')->status === RecaptchaVerificationResult::VERIFIED
            && $transportCalls === 1
    );

    $malformedCalls = 0;
    $malformedVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static function () use (&$malformedCalls): array {
            $malformedCalls++;
            return ['status' => 200, 'body' => '{}', 'transport_error' => false];
        }
    );
    adminSecurityTest(
        'RecaptchaMalformedTokenRejectedLocally',
        $malformedVerifier->verify("bad token\n")->status === RecaptchaVerificationResult::INVALID
            && $malformedVerifier->verify(str_repeat('x', RecaptchaVerifier::MAX_TOKEN_LENGTH + 1))->status === RecaptchaVerificationResult::INVALID
            && $malformedCalls === 0
    );

    $invalidVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => [
            'status' => 200,
            'body' => '{"success":false,"error-codes":["invalid-input-response"]}',
            'transport_error' => false,
        ]
    );
    adminSecurityTest(
        'RecaptchaInvalidTokenRejected',
        $invalidVerifier->verify('invalid-token')->status === RecaptchaVerificationResult::INVALID
    );

    $expiredVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => [
            'status' => 200,
            'body' => '{"success":false,"error-codes":["timeout-or-duplicate"]}',
            'transport_error' => false,
        ]
    );
    adminSecurityTest(
        'RecaptchaExpiredOrDuplicateRejected',
        $expiredVerifier->verify('expired-token')->status === RecaptchaVerificationResult::INVALID
    );

    $timeoutVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => ['status' => 0, 'body' => '', 'transport_error' => true]
    );
    adminSecurityTest(
        'RecaptchaProviderTimeoutFailsClosed',
        $timeoutVerifier->verify('timeout-token')->status === RecaptchaVerificationResult::UNAVAILABLE
    );

    $malformedJsonVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => ['status' => 200, 'body' => '{bad-json', 'transport_error' => false]
    );
    adminSecurityTest(
        'RecaptchaMalformedProviderJsonFailsClosed',
        $malformedJsonVerifier->verify('malformed-json-token')->status === RecaptchaVerificationResult::UNAVAILABLE
    );

    $malformedPayloadVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => ['status' => 200, 'body' => '{}', 'transport_error' => false]
    );
    adminSecurityTest(
        'RecaptchaMalformedProviderPayloadFailsClosed',
        $malformedPayloadVerifier->verify('malformed-payload-token')->status === RecaptchaVerificationResult::UNAVAILABLE
    );

    $nonSuccessVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => ['status' => 503, 'body' => 'unavailable', 'transport_error' => false]
    );
    adminSecurityTest(
        'RecaptchaNon2xxFailsClosed',
        $nonSuccessVerifier->verify('provider-error-token')->status === RecaptchaVerificationResult::UNAVAILABLE
    );

    $hostnameVerifier = new RecaptchaVerifier(
        $recaptchaConfig,
        static fn (): array => [
            'status' => 200,
            'body' => '{"success":true,"hostname":"attacker.example.test"}',
            'transport_error' => false,
        ]
    );
    adminSecurityTest(
        'RecaptchaHostnameMismatchRejected',
        $hostnameVerifier->verify('hostname-token')->status === RecaptchaVerificationResult::INVALID
    );
} finally {
    foreach ($savedEnvironment as $environmentName => $environmentValue) {
        setAdminSecurityEnvironment(
            $environmentName,
            is_string($environmentValue) ? $environmentValue : null
        );
    }
}

$loginEndpoint = adminSecuritySource('api/employee/login.php');
$loginPage = adminSecuritySource('login.php');
$loginJavascript = adminSecuritySource('assets/js/login.js');
$verifyOtp = adminSecuritySource('api/employee/verify-otp.php');
$resendOtp = adminSecuritySource('api/employee/resend-otp.php');
$proxy = adminSecuritySource('config/proxy.php');

$verificationPosition = strpos($loginEndpoint, '->verify($recaptchaToken)');
$proxyPosition = strpos($loginEndpoint, 'proxyRequest($remoteUrl');
adminSecurityTest(
    'RecaptchaPrecedesCredentialAuthority',
    is_int($verificationPosition) && is_int($proxyPosition) && $verificationPosition < $proxyPosition
);
adminSecurityTest(
    'RecaptchaUiOnlyOnEmployeeLogin',
    str_contains($loginPage, 'g-recaptcha')
        && str_contains($loginPage, '$recaptchaConfig->siteKey()')
        && str_contains($loginJavascript, 'recaptcha_token')
        && !str_contains($verifyOtp, 'recaptcha')
        && !str_contains($resendOtp, 'recaptcha')
);
adminSecurityTest(
    'RecaptchaSecretNeverRendered',
    !str_contains($loginPage, 'RECAPTCHA_SECRET_KEY')
        && !str_contains($loginJavascript, 'RECAPTCHA_SECRET_KEY')
        && !str_contains($loginJavascript, 'secretKey')
);
adminSecurityTest(
    'CaptchaAndProxyErrorsAreSanitized',
    !str_contains($loginEndpoint, 'curl_error(')
        && !str_contains($proxy, 'curl_error(')
        && str_contains($proxy, 'CIVENTRAL upstream request failed with cURL code ')
        && str_contains($loginEndpoint, 'Unable to verify the security check right now.')
);
adminSecurityTest(
    'OtpAuthenticationStillHydratesAdminSession',
    str_contains($verifyOtp, 'hydrateRemoteEmployeeSession(')
        && str_contains($loginEndpoint, 'hydrateRemoteEmployeeSession(')
);
adminSecurityTest(
    'KeepMeSignedInRemoved',
    !str_contains(strtolower($loginPage), 'keep me signed in')
        && !str_contains($loginPage, 'rememberMe')
);

$responseProjector = adminSecuritySource('src/Services/EmployeeAuthResponseProjector.php');
adminSecurityTest(
    'EmployeeAuthEndpointsUseResponseProjector',
    str_contains($loginEndpoint, 'EmployeeAuthResponseProjector::login($result)')
        && str_contains($verifyOtp, 'EmployeeAuthResponseProjector::verifyOtp($result)')
        && str_contains($resendOtp, 'EmployeeAuthResponseProjector::resendOtp($result)')
        && !str_contains($loginEndpoint, "respond(\$result['body']")
        && !str_contains($verifyOtp, "respond(\$result['body']")
        && !str_contains($resendOtp, "respond(\$result['body']")
        && !str_contains($responseProjector, 'error_log(')
);

$projectionSuiteCode = 'require '
    . var_export($root . '/scripts/test-employee-auth-response-projection.php', true)
    . ';';
$projectionSuite = adminSecurityChild($projectionSuiteCode);
adminSecurityTest(
    'EmployeeAuthResponseProjectionSuite',
    $projectionSuite['exit_code'] === 0
        && str_contains($projectionSuite['output'], 'EmployeeAuthResponseProjectionContract=PASS')
        && $projectionSuite['error'] === ''
);

$directBypassCode = sprintf(
    '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["SCRIPT_NAME"]="/api/employee/login.php";'
    . '$_POST=["employeeId"=>"test-employee","password"=>"not-a-real-password"]; require %s;',
    var_export($root . '/api/employee/login.php', true)
);
$directBypass = adminSecurityChild($directBypassCode);
adminSecurityTest(
    'DirectLoginApiCannotOmitCaptcha',
    $directBypass['exit_code'] === 0
        && str_contains($directBypass['output'], '"code":"CAPTCHA_REQUIRED"')
        && !str_contains($directBypass['output'], 'not-a-real-password')
        && !str_contains($directBypass['error'], 'not-a-real-password')
);

$getBypassCode = sprintf(
    '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["SCRIPT_NAME"]="/api/employee/login.php"; require %s;',
    var_export($root . '/api/employee/login.php', true)
);
$getBypass = adminSecurityChild($getBypassCode);
adminSecurityTest(
    'LoginGetCannotBypassPost',
    str_contains($getBypass['output'], 'Method Not Allowed.')
        && !str_contains($getBypass['output'], 'CAPTCHA_REQUIRED')
);

// Exercise the real local session-establishment and logout primitives.
$_SERVER['HTTPS'] = 'on';
AdminSessionManager::start();
require_once $root . '/config/proxy.php';
$preLoginSessionId = session_id();
$established = establishRemoteEmployeeSession(
    ['user_id' => 'security-test-user'],
    ['employee_id' => 'security-test-employee', 'role_id' => 'security-test-role']
);
$authenticatedSessionId = session_id();
$cookieParameters = session_get_cookie_params();
$liveSessions = new AdminSessionManager();
adminSecurityTest(
    'SuccessfulLoginRegeneratesSessionId',
    $established && $preLoginSessionId !== '' && $authenticatedSessionId !== $preLoginSessionId
);
adminSecurityTest(
    'AdminCookieIsHardenedBrowserSession',
    ($cookieParameters['lifetime'] ?? null) === 0
        && ($cookieParameters['httponly'] ?? null) === true
        && ($cookieParameters['secure'] ?? null) === true
        && strcasecmp((string) ($cookieParameters['samesite'] ?? ''), 'Lax') === 0
        && ini_get('session.use_strict_mode') === '1'
        && ini_get('session.use_only_cookies') === '1'
);
adminSecurityTest(
    'EstablishedAdminSessionIsActive',
    $liveSessions->validateAndTouch(false) === AdminSessionManager::STATUS_ACTIVE
);

$liveSessions->invalidate();
adminSecurityTest('LogoutDestroysActiveSession', session_status() === PHP_SESSION_NONE && $_SESSION === []);

ini_set('session.use_strict_mode', '0');
session_id($authenticatedSessionId);
session_start();
$destroyedSessionData = $_SESSION;
$_SESSION = [];
session_destroy();
adminSecurityTest('DestroyedSessionIdCannotRecoverIdentity', $destroyedSessionData === []);

ob_end_clean();
$failed = [];
foreach ($results as $name => $passed) {
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== []) {
    fwrite(STDERR, 'Admin authentication security failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'AdminAuthenticationSecurityContract=PASS' . PHP_EOL;
