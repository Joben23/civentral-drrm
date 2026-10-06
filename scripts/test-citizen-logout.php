<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use App\Config\CitizenApiConfig;
use App\Services\CitizenSessionManager;

require_once __DIR__ . '/../config/citizen_api.php';
require_once __DIR__ . '/../src/Services/CitizenSessionManager.php';

ob_start();
$results = [];
function logoutTest(string $name, bool $passed): void
{
    global $results;
    $results[$name] = $passed;
}
function logoutSource(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../' . $path);
    if (!is_string($source)) throw new RuntimeException('Missing ' . $path);
    return $source;
}

$endpoint = logoutSource('api/citizen/logout.php');
$manager = logoutSource('src/Services/CitizenSessionManager.php');
$proxy = logoutSource('config/proxy.php');
$login = logoutSource('api/citizen/login.php');
$otp = logoutSource('api/citizen/verify-otp.php');
$config = CitizenApiConfig::fromEnvironment(__DIR__ . '/../.env');

logoutTest('PostOnlyContract',
    str_contains($endpoint, 'if ($method !== ' . chr(39) . 'POST' . chr(39) . ')')
    && str_contains($endpoint, 'Allow: POST, OPTIONS'));
logoutTest('NoClientIdentityAccepted',
    !str_contains($endpoint, 'citizen_id') && !str_contains($endpoint, 'actor_id')
    && str_contains($endpoint, 'get_object_vars($input) !== []'));
logoutTest('ConfiguredUpstreamLogoutReused',
    str_ends_with($config->logoutUrl(), '/api/citizen/logout.php')
    && str_contains($endpoint, 'proxyRequest($config->logoutUrl()'));
logoutTest('UpstreamTimeoutBounded',
    str_contains($proxy, 'CURLOPT_CONNECTTIMEOUT, 5')
    && str_contains($proxy, 'CURLOPT_TIMEOUT, 15'));
logoutTest('LocalInvalidationIsFinallyProtected',
    str_contains($endpoint, '} finally {')
    && str_contains($endpoint, 'CitizenSessionManager::invalidate();'));
logoutTest('AuthenticatedSessionIdsRotate',
    str_contains($login, 'session_regenerate_id(true)')
    && str_contains($otp, 'session_regenerate_id(true)'));
logoutTest('CookieExpiryUsesLiveParameters',
    str_contains($manager, 'session_get_cookie_params()')
    && str_contains($manager, chr(39) . 'expires' . chr(39) . ' => time() - 42000'));
logoutTest('SessionRecordDestroyed', str_contains($manager, 'session_destroy()'));

$_SERVER['HTTPS'] = 'on';
CitizenSessionManager::start();
$sessionId = session_id();
$_SESSION['remote_phpsessid'] = str_repeat('a', 32);
$_SESSION['citizen_user_id'] = 42;
$_SESSION['citizen_email'] = 'test@example.invalid';
$_SESSION['unrelated_session_key'] = 'must also be cleared';
$params = session_get_cookie_params();
logoutTest('ModeledProtectedGateAllowedBeforeLogout',
    CitizenSessionManager::remoteSessionId() === str_repeat('a', 32));
logoutTest('LoginCookieAttributes',
    ($params['path'] ?? null) === '/' && ($params['domain'] ?? null) === ''
    && ($params['secure'] ?? null) === true && ($params['httponly'] ?? null) === true
    && strcasecmp((string) ($params['samesite'] ?? ''), 'Lax') === 0);

CitizenSessionManager::invalidate();
logoutTest('SessionInactiveAfterLogout', session_status() === PHP_SESSION_NONE);

ini_set('session.use_strict_mode', '0');
session_id($sessionId);
session_start();
$reopenedData = $_SESSION;
$_SESSION = [];
session_destroy();
logoutTest('AllLocalSessionKeysCleared', $reopenedData === []);
logoutTest('ModeledProtectedGateDeniedAfterLogout',
    !array_key_exists('remote_phpsessid', $reopenedData)
    && !array_key_exists('citizen_user_id', $reopenedData));

ob_end_clean();
$failed = [];
foreach ($results as $name => $passed) {
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
if ($failed !== []) {
    fwrite(STDERR, 'Citizen logout failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'CitizenLogoutContract=PASS' . PHP_EOL;
