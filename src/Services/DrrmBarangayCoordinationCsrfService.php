<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DrrmBarangayCoordinationCsrfException extends RuntimeException {}

final class DrrmBarangayCoordinationCsrfService
{
    private const SESSION_KEY = 'drrm_barangay_coordination_csrf';
    private const TTL = 3600;

    public function token(): string
    {
        $this->requireSession();
        $record = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($record) && is_string($record['token'] ?? null) && is_int($record['issued_at'] ?? null) && time() - $record['issued_at'] < self::TTL) {
            return $record['token'];
        }
        return $this->regenerate();
    }

    public function requireValidHeader(?array $server = null): void
    {
        $this->requireSession();
        $record = $_SESSION[self::SESSION_KEY] ?? null;
        $submitted = is_string(($server ?? $_SERVER)['HTTP_X_CSRF_TOKEN'] ?? null) ? trim(($server ?? $_SERVER)['HTTP_X_CSRF_TOKEN']) : '';
        if (!is_array($record) || !is_string($record['token'] ?? null) || $submitted === '' || !hash_equals($record['token'], $submitted) || !is_int($record['issued_at'] ?? null) || time() - $record['issued_at'] >= self::TTL) {
            throw new DrrmBarangayCoordinationCsrfException('Invalid Module 5 CSRF token.');
        }
    }

    private function regenerate(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION[self::SESSION_KEY] = ['token' => $token, 'issued_at' => time()];
        return $token;
    }

    private function requireSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active session is required.');
        }
    }
}
