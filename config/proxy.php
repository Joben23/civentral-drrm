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
