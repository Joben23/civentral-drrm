<?php
require_once __DIR__ . '/../../src/Services/AdminSessionManager.php';
\App\Services\AdminSessionManager::start();

header('Content-Type: application/json; charset=utf-8');

// 1. Dynamic CORS Configuration
$allowedOrigins = [
    'http://localhost',
    'http://localhost:80',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://127.0.0.1:80'
];

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (in_array($origin, $allowedOrigins) || preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
    }
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Preflight Handling
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Response Helper
function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    respond([
        'status' => 'error',
        'message' => 'Method Not Allowed.'
    ], 405);
}

$decodedInput = json_decode(file_get_contents('php://input'), true);
$input = is_array($decodedInput) ? $decodedInput : $_POST;
if (!is_array($input) || array_is_list($input)) {
    respond([
        'status' => 'error',
        'code' => 'INVALID_LOGIN_REQUEST',
        'message' => 'Invalid login request.'
    ], 400);
}

$allowedFields = ['employeeId', 'email', 'username', 'password', 'recaptcha_token'];
foreach (array_keys($input) as $field) {
    if (!is_string($field) || !in_array($field, $allowedFields, true)) {
        respond([
            'status' => 'error',
            'code' => 'INVALID_LOGIN_REQUEST',
            'message' => 'Invalid login request.'
        ], 400);
    }
}

$rawEmployeeIdOrEmail = $input['employeeId'] ?? $input['email'] ?? $input['username'] ?? '';
$employeeIdOrEmail = is_string($rawEmployeeIdOrEmail) ? trim($rawEmployeeIdOrEmail) : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$recaptchaToken = is_string($input['recaptcha_token'] ?? null)
    ? trim($input['recaptcha_token'])
    : '';

if (empty($employeeIdOrEmail) || empty($password)) {
    respond([
        'status' => 'error',
        'message' => 'Please provide both Employee ID / Email and Password.'
    ], 400);
}

if ($recaptchaToken === '') {
    respond([
        'status' => 'error',
        'code' => 'CAPTCHA_REQUIRED',
        'message' => 'Complete the security check.'
    ], 400);
}

require_once __DIR__ . '/../../config/recaptcha.php';
require_once __DIR__ . '/../../src/Services/RecaptchaVerifier.php';

try {
    $recaptchaConfig = \App\Config\RecaptchaConfig::fromEnvironment(__DIR__ . '/../../.env');
    $verification = (new \App\Services\RecaptchaVerifier($recaptchaConfig))->verify($recaptchaToken);
} catch (Throwable) {
    error_log('Admin login reCAPTCHA configuration or verification initialization failed.');
    respond([
        'status' => 'error',
        'code' => 'CAPTCHA_UNAVAILABLE',
        'message' => 'Unable to verify the security check right now. Please try again.'
    ], 503);
}

if ($verification->status === \App\Services\RecaptchaVerificationResult::UNAVAILABLE) {
    respond([
        'status' => 'error',
        'code' => 'CAPTCHA_UNAVAILABLE',
        'message' => 'Unable to verify the security check right now. Please try again.'
    ], 503);
}
if (!$verification->isVerified()) {
    respond([
        'status' => 'error',
        'code' => 'CAPTCHA_INVALID',
        'message' => 'Security check failed or expired. Please try again.'
    ], 422);
}

// System Maintenance Check
if (strtolower($employeeIdOrEmail) === 'maintenance') {
    respond([
        'status' => 'maintenance',
        'message' => 'System maintenance is scheduled for Sunday, 11:00 PM–1:00 AM. Save drafts before then.'
    ], 503);
}

require_once __DIR__ . '/../../config/proxy.php';
require_once __DIR__ . '/../../src/Services/EmployeeAuthResponseProjector.php';

$apiBaseUrl = getenv('EXPO_PUBLIC_API_BASE_URL') ?: 'https://civentral.tech/api/employee';
$remoteUrl = rtrim($apiBaseUrl, '/') . '/login.php';

$result = proxyRequest($remoteUrl, 'POST', [
    'employeeId' => $employeeIdOrEmail,
    'password' => $password
]);

$response = \App\Services\EmployeeAuthResponseProjector::login($result);
$upstreamBody = is_array($result['body'] ?? null) ? $result['body'] : [];
if ($response['payload']['status'] === 'success') {
    $authenticatedUser = is_array($upstreamBody['user'] ?? null)
        ? $upstreamBody['user']
        : [];

    if (!hydrateRemoteEmployeeSession($apiBaseUrl, $authenticatedUser)) {
        clearRemoteEmployeeAuthentication();
        respond([
            'status' => 'error',
            'message' => 'Authentication succeeded, but the server could not establish a verified local session.'
        ], 502);
    }
}

respond($response['payload'], $response['status_code']);
?>
