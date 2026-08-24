<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DrrmIncidentCsrfException extends RuntimeException
{
}

/**
 * Session-bound CSRF protection for Module 3 mutation endpoints.
 */
final class DrrmIncidentCsrfService
{
    public const TOKEN_TTL_SECONDS = 3600;
    private const SESSION_KEY = 'drrm_incident_csrf';

    public function token(): string
    {
        $this->requireActiveSession();
        $record = $this->record();
        if ($record !== null && !$this->isExpired($record['issued_at'])) {
            return $record['token'];
        }
        return $this->regenerate();
    }

    public function validate(?string $submittedToken): bool
    {
        $this->requireActiveSession();
        $record = $this->record();
        if ($record === null) {
            return false;
        }
        if ($this->isExpired($record['issued_at'])) {
            $this->regenerate();
            return false;
        }
        if (!is_string($submittedToken) || $submittedToken === '') {
            return false;
        }
        return hash_equals($record['token'], $submittedToken);
    }

    /** @param array<string, mixed>|null $server */
    public function requireValidHeader(?array $server = null): void
    {
        $server ??= $_SERVER;
        $token = $server['HTTP_X_CSRF_TOKEN'] ?? null;
        $token = is_string($token) ? trim($token) : null;
        if (!$this->validate($token)) {
            throw new DrrmIncidentCsrfException('Invalid Module 3 CSRF token.');
        }
    }

    public function regenerate(): string
    {
        $this->requireActiveSession();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION[self::SESSION_KEY] = [
            'token' => $token,
            'issued_at' => time(),
        ];
        return $token;
    }

    /** @return array{token: string, issued_at: int}|null */
    private function record(): ?array
    {
        $record = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($record)) {
            return null;
        }
        $token = $record['token'] ?? null;
        $issuedAt = $record['issued_at'] ?? null;
        if (!is_string($token) || $token === '' || !is_int($issuedAt) || $issuedAt <= 0) {
            return null;
        }
        return ['token' => $token, 'issued_at' => $issuedAt];
    }

    private function isExpired(int $issuedAt): bool
    {
        $age = time() - $issuedAt;
        return $age < 0 || $age >= self::TOKEN_TTL_SECONDS;
    }

    private function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active authenticated session is required for Module 3 CSRF protection.');
        }
    }
}
