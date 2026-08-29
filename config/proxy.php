<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function proxyRequest($url, $method = 'POST', $body = null, $sendCookie = true) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    $headers = [
        'Content-Type: application/json'
    ];
    
    if ($sendCookie && !empty($_SESSION['remote_phpsessid'])) {
        $headers[] = 'Cookie: PHPSESSID=' . $_SESSION['remote_phpsessid'];
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    
    $response = curl_exec($ch);
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'code' => 500,
            'body' => [
                'status' => 'error',
                'message' => 'Proxy request failed: ' . $error
            ]
        ];
    }
    
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $headerStr = substr($response, 0, $headerSize);
    $bodyStr = substr($response, $headerSize);
    
    // Parse cookies from headers
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    foreach ($matches[1] as $cookie) {
        $parts = explode('=', $cookie, 2);
        if (count($parts) === 2 && trim($parts[0]) === 'PHPSESSID') {
            $_SESSION['remote_phpsessid'] = trim($parts[1]);
        }
    }
    
    return [
        'code' => $httpCode,
        'body' => json_decode($bodyStr, true) ?? $bodyStr
    ];
}

/**
 * Establish the local admin session only from a successful remote employee
 * profile. The profile remains the server-derived source for role and RBAC
 * hydration; no local identity is synthesized.
 */
function establishRemoteEmployeeSession(array $authenticatedUser, array $profile): bool
{
    if ($profile === []) {
        return false;
    }

    $details = array_replace($authenticatedUser, $profile);
    $userId = trim((string) ($details['user_id'] ?? ''));
    $employeeId = trim((string) ($details['employee_id'] ?? ''));
    if ($userId === '' && $employeeId === '') {
        return false;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $userId !== '' ? $userId : null;
    $_SESSION['employee_id'] = $employeeId !== '' ? $employeeId : null;
    $_SESSION['email'] = $details['email'] ?? ($employeeId !== '' ? $employeeId : null);
    $_SESSION['first_name'] = $details['first_name'] ?? null;
    $_SESSION['last_name'] = $details['last_name'] ?? null;
    $_SESSION['role_id'] = $details['role_id'] ?? null;
    $_SESSION['current_user_details'] = $details;
    $_SESSION['user_granted_actions'] = [];
    $_SESSION['user_granted_resources'] = [];
    $_SESSION['user_permissions_map'] = [];
    $_SESSION['LAST_ACTIVITY'] = time();

    return true;
}

function hydrateRemoteEmployeeSession(string $apiBaseUrl, array $authenticatedUser = []): bool
{
    $profileUrl = rtrim($apiBaseUrl, '/') . '/get-profile.php';
    $profileResult = proxyRequest($profileUrl, 'GET', null);
    $profileBody = is_array($profileResult['body'] ?? null)
        ? $profileResult['body']
        : [];

    if (($profileResult['code'] ?? 500) >= 400
        || ($profileBody['status'] ?? null) !== 'success'
        || !is_array($profileBody['data'] ?? null)) {
        return false;
    }

    return establishRemoteEmployeeSession($authenticatedUser, $profileBody['data']);
}

function clearRemoteEmployeeAuthentication(): void
{
    foreach ([
        'remote_phpsessid',
        'user_id',
        'employee_id',
        'email',
        'first_name',
        'last_name',
        'role_id',
        'current_user_details',
        'user_granted_actions',
        'user_granted_resources',
        'user_permissions_map',
        'LAST_ACTIVITY',
    ] as $key) {
        unset($_SESSION[$key]);
    }
}
