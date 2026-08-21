<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DrrmEarlyWarningCsrfException extends RuntimeException
{
}

/**
 * Session-bound CSRF tokens used only by Module 4 mutation endpoints.
 */
final class DrrmEarlyWarningCsrfService
{
    public const TOKEN_TTL_SECONDS = 3600;
    private const SESSION_KEY = 'drrm_early_warning_csrf';

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
            // Replace an expired session token, but reject the current request.
            $this->regenerate();
            return false;
        }

        if (!is_string($submittedToken) || $submittedToken === '') {
            return false;
        }

        return hash_equals($record['token'], $submittedToken);
    }

    public function requireValidHeader(?array $server = null): void
    {
        $server ??= $_SERVER;
        $headerToken = $server['HTTP_X_CSRF_TOKEN'] ?? null;
        $headerToken = is_string($headerToken) ? trim($headerToken) : null;

        if (!$this->validate($headerToken)) {
            throw new DrrmEarlyWarningCsrfException('Invalid Module 4 CSRF token.');
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

        return [
            'token' => $token,
            'issued_at' => $issuedAt,
        ];
    }

    private function isExpired(int $issuedAt): bool
    {
        $age = time() - $issuedAt;
        return $age < 0 || $age >= self::TOKEN_TTL_SECONDS;
    }

    private function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active authenticated session is required for Module 4 CSRF protection.');
        }
    }
}
