<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AdminSessionManager
{
    public const IDLE_TIMEOUT_SECONDS = 300;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_UNAUTHENTICATED = 'UNAUTHENTICATED';

    private const AUTH_CONTEXT_KEY = 'admin_auth_context';
    private const AUTH_CONTEXT_VALUE = 'employee';
    private const LAST_ACTIVITY_KEY = 'LAST_ACTIVITY';

    /** @var callable(): int */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() === PHP_SESSION_DISABLED) {
            throw new RuntimeException('Admin sessions are unavailable.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $current = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) ($current['path'] ?? '/'),
            'domain' => (string) ($current['domain'] ?? ''),
            'secure' => self::requestUsesHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('The admin session could not be started.');
        }
    }

    public function markAuthenticated(): void
    {
        self::start();
        $_SESSION[self::AUTH_CONTEXT_KEY] = self::AUTH_CONTEXT_VALUE;
        $_SESSION[self::LAST_ACTIVITY_KEY] = $this->now();
    }

    public function validateAndTouch(bool $touch = true): string
    {
        self::start();
        $status = $this->evaluate($_SESSION, $touch);

        if ($status === self::STATUS_EXPIRED) {
            $this->invalidate();
        }

        return $status;
    }

    /**
     * Evaluate an isolated session state. Public to permit deterministic tests
     * without creating real PHP session cookies.
     *
     * @param array<string, mixed> $session
     */
    public function evaluate(array &$session, bool $touch = true): string
    {
        if (!$this->hasEmployeeIdentity($session)) {
            return self::STATUS_UNAUTHENTICATED;
        }

        $lastActivity = $session[self::LAST_ACTIVITY_KEY] ?? null;
        if (!is_int($lastActivity) && !(is_string($lastActivity) && ctype_digit($lastActivity))) {
            return self::STATUS_EXPIRED;
        }

        $lastActivity = (int) $lastActivity;
        $now = $this->now();
        if ($lastActivity < 0 || $lastActivity > $now || ($now - $lastActivity) >= self::IDLE_TIMEOUT_SECONDS) {
            return self::STATUS_EXPIRED;
        }

        if ($touch) {
            $session[self::LAST_ACTIVITY_KEY] = $now;
        }

        return self::STATUS_ACTIVE;
    }

    public function expiresAt(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
        if (!is_int($lastActivity) && !(is_string($lastActivity) && ctype_digit($lastActivity))) {
            return null;
        }

        return (int) $lastActivity + self::IDLE_TIMEOUT_SECONDS;
    }

    public function invalidate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if ((bool) ini_get('session.use_cookies') && !headers_sent()) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string) ($parameters['path'] ?? '/'),
                'domain' => (string) ($parameters['domain'] ?? ''),
                'secure' => (bool) ($parameters['secure'] ?? false),
                'httponly' => (bool) ($parameters['httponly'] ?? true),
                'samesite' => (string) ($parameters['samesite'] ?? 'Lax'),
            ]);
        }

        if (!session_destroy()) {
            throw new RuntimeException('The admin session could not be invalidated.');
        }
    }

    /** @param array<string, mixed> $session */
    private function hasEmployeeIdentity(array $session): bool
    {
        if (($session[self::AUTH_CONTEXT_KEY] ?? null) !== self::AUTH_CONTEXT_VALUE) {
            return false;
        }

        return !empty($session['user_id']) || !empty($session['employee_id']);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private static function requestUsesHttps(): bool
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));

        return ($https !== '' && $https !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
