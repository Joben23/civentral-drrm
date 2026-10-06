<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Services\EmployeeAuthResponseProjector;

$root = dirname(__DIR__);
require_once $root . '/src/Services/EmployeeAuthResponseProjector.php';

$results = [];

function responseProjectionTest(string $name, bool $passed): void
{
    global $results;
    $results[$name] = $passed;
}

/** @param array<string, mixed> $response */
function responseProjectionHasSafeShape(array $response): bool
{
    return array_keys($response) === ['status_code', 'payload']
        && is_int($response['status_code'])
        && is_array($response['payload'])
        && array_keys($response['payload']) === ['status', 'message']
        && is_string($response['payload']['status'])
        && is_string($response['payload']['message']);
}

/** @param array<string, mixed> $response */
function responseProjectionContainsSentinel(array $response): bool
{
    $encoded = json_encode($response);
    if (!is_string($encoded)) {
        return true;
    }

    foreach ([
        'SENTINEL_',
        'password',
        'recaptcha_token',
        'remote_phpsessid',
        'internal_url',
        'stack_trace',
        'diagnostics',
        'permissions',
        'profile',
        '"user"',
    ] as $forbiddenValue) {
        if (str_contains($encoded, $forbiddenValue)) {
            return true;
        }
    }

    return false;
}

$sentinelBody = [
    'message' => 'SENTINEL_UPSTREAM_MESSAGE',
    'email' => 'SENTINEL_EMAIL@example.test',
    'password' => 'SENTINEL_PASSWORD',
    'recaptcha_token' => 'SENTINEL_RECAPTCHA_TOKEN',
    'secret' => 'SENTINEL_SECRET',
    'internal_url' => 'https://internal.example.test/SENTINEL_INTERNAL_URL',
    'remote_phpsessid' => 'SENTINEL_REMOTE_SESSION',
    'stack_trace' => 'SENTINEL_STACK_TRACE',
    'diagnostics' => ['trace' => 'SENTINEL_DIAGNOSTICS'],
    'user' => [
        'permissions' => ['SENTINEL_PERMISSION'],
        'profile' => ['private' => 'SENTINEL_NESTED_DATA'],
    ],
];
$sentinelHeaders = [
    'Set-Cookie' => 'PHPSESSID=SENTINEL_HEADER_SESSION',
    'X-Internal-Diagnostics' => 'SENTINEL_HEADER_DIAGNOSTICS',
];

$oldLogErrors = ini_get('log_errors');
$oldErrorLog = ini_get('error_log');
$testLog = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'civentral-auth-projection-'
    . bin2hex(random_bytes(8))
    . '.log';
@unlink($testLog);
ini_set('log_errors', '1');
ini_set('error_log', $testLog);

try {
    $loginProjection = EmployeeAuthResponseProjector::login([
        'code' => 200,
        'headers' => $sentinelHeaders,
        'body' => ['status' => 'success'] + $sentinelBody,
    ]);
    $verifyProjection = EmployeeAuthResponseProjector::verifyOtp([
        'code' => 200,
        'headers' => $sentinelHeaders,
        'body' => ['status' => 'success'] + $sentinelBody,
    ]);
    $resendProjection = EmployeeAuthResponseProjector::resendOtp([
        'code' => 200,
        'headers' => $sentinelHeaders,
        'body' => ['status' => 'success'] + $sentinelBody,
    ]);
} finally {
    $capturedLog = is_file($testLog) ? file_get_contents($testLog) : '';
    @unlink($testLog);
    if (is_string($oldLogErrors)) {
        ini_set('log_errors', $oldLogErrors);
    }
    if (is_string($oldErrorLog)) {
        ini_set('error_log', $oldErrorLog);
    }
}

responseProjectionTest(
    'CredentialLoginProjectsOnlyStatusAndMessage',
    $loginProjection === [
        'status_code' => 200,
        'payload' => ['status' => 'success', 'message' => 'Login successful.'],
    ]
        && responseProjectionHasSafeShape($loginProjection)
        && !responseProjectionContainsSentinel($loginProjection)
);
responseProjectionTest(
    'VerifyOtpProjectsOnlyStatusAndMessage',
    $verifyProjection === [
        'status_code' => 200,
        'payload' => ['status' => 'success', 'message' => 'Verification successful.'],
    ]
        && responseProjectionHasSafeShape($verifyProjection)
        && !responseProjectionContainsSentinel($verifyProjection)
);
responseProjectionTest(
    'ResendOtpProjectsOnlyStatusAndMessage',
    $resendProjection === [
        'status_code' => 200,
        'payload' => ['status' => 'success', 'message' => 'A new verification code has been sent.'],
    ]
        && responseProjectionHasSafeShape($resendProjection)
        && !responseProjectionContainsSentinel($resendProjection)
);
responseProjectionTest(
    'SentinelSecretsAbsentFromProjectionLogs',
    is_string($capturedLog)
        && $capturedLog === ''
);

$otpRequiredProjection = EmployeeAuthResponseProjector::login([
    'code' => 200,
    'body' => [
        'status' => 'otp_required',
        'message' => 'SENTINEL_UPSTREAM_MESSAGE',
        'email' => 'unmasked@example.test',
    ],
]);
responseProjectionTest(
    'OtpRequiredOmitsEmailAndUsesSafeMessage',
    $otpRequiredProjection === [
        'status_code' => 200,
        'payload' => [
            'status' => 'otp_required',
            'message' => 'A verification code was sent to your registered email.',
        ],
    ]
        && !array_key_exists('email', $otpRequiredProjection['payload'])
);

$maintenanceProjection = EmployeeAuthResponseProjector::login([
    'code' => 200,
    'body' => [
        'status' => 'maintenance',
        'message' => 'SENTINEL_UPSTREAM_MESSAGE',
        'diagnostics' => 'SENTINEL_DIAGNOSTICS',
    ],
]);
responseProjectionTest(
    'KnownMaintenanceStateIsSafelyNormalized',
    $maintenanceProjection === [
        'status_code' => 503,
        'payload' => [
            'status' => 'maintenance',
            'message' => 'Sign-in is temporarily unavailable due to maintenance. Please try again later.',
        ],
    ]
);

$unknownStatusProjection = EmployeeAuthResponseProjector::login([
    'code' => 200,
    'body' => ['status' => 'unexpected_success', 'message' => 'SENTINEL_UPSTREAM_MESSAGE'],
]);
$contradictorySuccessProjection = EmployeeAuthResponseProjector::login([
    'code' => 500,
    'body' => ['status' => 'success', 'user' => $sentinelBody['user']],
]);
$malformedBodyProjection = EmployeeAuthResponseProjector::login([
    'code' => 200,
    'body' => 'SENTINEL_NON_JSON_BODY',
]);
responseProjectionTest(
    'UnknownAndContradictoryLoginStatesFailClosed',
    $unknownStatusProjection['status_code'] === 401
        && $unknownStatusProjection['payload']['status'] === 'error'
        && !responseProjectionContainsSentinel($unknownStatusProjection)
        && $contradictorySuccessProjection['status_code'] === 502
        && $contradictorySuccessProjection['payload']['status'] === 'error'
        && !responseProjectionContainsSentinel($contradictorySuccessProjection)
        && $malformedBodyProjection['status_code'] === 502
        && $malformedBodyProjection['payload']['status'] === 'error'
        && !responseProjectionContainsSentinel($malformedBodyProjection)
);

$loginThrottleProjection = EmployeeAuthResponseProjector::login([
    'code' => 429,
    'body' => ['status' => 'error', 'message' => 'SENTINEL_UPSTREAM_MESSAGE'],
]);
$verifyThrottleProjection = EmployeeAuthResponseProjector::verifyOtp([
    'code' => 429,
    'body' => ['status' => 'error', 'message' => 'SENTINEL_UPSTREAM_MESSAGE'],
]);
$resendThrottleProjection = EmployeeAuthResponseProjector::resendOtp([
    'code' => 429,
    'body' => ['status' => 'error', 'message' => 'SENTINEL_UPSTREAM_MESSAGE'],
]);
responseProjectionTest(
    'RateLimitStatusIsPreservedWithoutUpstreamMessage',
    $loginThrottleProjection['status_code'] === 429
        && $verifyThrottleProjection['status_code'] === 429
        && $resendThrottleProjection['status_code'] === 429
        && !responseProjectionContainsSentinel($loginThrottleProjection)
        && !responseProjectionContainsSentinel($verifyThrottleProjection)
        && !responseProjectionContainsSentinel($resendThrottleProjection)
);

$loginEndpoint = file_get_contents($root . '/api/employee/login.php');
$verifyEndpoint = file_get_contents($root . '/api/employee/verify-otp.php');
$resendEndpoint = file_get_contents($root . '/api/employee/resend-otp.php');
$loginJavascript = file_get_contents($root . '/assets/js/login.js');
responseProjectionTest(
    'EndpointsCannotForwardCompleteUpstreamBodies',
    is_string($loginEndpoint)
        && is_string($verifyEndpoint)
        && is_string($resendEndpoint)
        && str_contains($loginEndpoint, 'EmployeeAuthResponseProjector::login($result)')
        && str_contains($verifyEndpoint, 'EmployeeAuthResponseProjector::verifyOtp($result)')
        && str_contains($resendEndpoint, 'EmployeeAuthResponseProjector::resendOtp($result)')
        && !str_contains($loginEndpoint, "respond(\$result['body']")
        && !str_contains($verifyEndpoint, "respond(\$result['body']")
        && !str_contains($resendEndpoint, "respond(\$result['body']")
);
responseProjectionTest(
    'FrontendConsumesOnlyProjectedContract',
    is_string($loginJavascript)
        && str_contains($loginJavascript, "data.status === 'success'")
        && str_contains($loginJavascript, "data.status === 'otp_required'")
        && str_contains($loginJavascript, "data.status === 'maintenance'")
        && str_contains($loginJavascript, 'data.message')
        && str_contains($loginJavascript, "openOtpModal(data.email || '')")
);

$failed = [];
foreach ($results as $name => $passed) {
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Employee auth response projection failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'EmployeeAuthResponseProjectionContract=PASS' . PHP_EOL;
