<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Local PHP-session lifecycle shared by citizen authentication endpoints. */
final class CitizenSessionManager
{
    /** Configure and start the credentialed local citizen session. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            throw new RuntimeException('Citizen sessions are unavailable.');
        }

        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params(self::cookieParameters());
        if (!session_start()) {
            throw new RuntimeException('The citizen session could not be started.');
        }
    }

    /** Whether the request presented the configured local session cookie. */
    public static function hasSessionCookie(): bool
    {
        $cookieName = session_name();

        return isset($_COOKIE[$cookieName])
            && is_string($_COOKIE[$cookieName])
            && trim($_COOKIE[$cookieName]) !== '';
    }

    /** Return only a syntactically safe server-held upstream PHP session ID. */
    public static function remoteSessionId(): ?string
    {
        $sessionId = $_SESSION['remote_phpsessid'] ?? null;
        if (!is_string($sessionId)) {
            return null;
        }

        $sessionId = trim($sessionId);

        return preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sessionId) === 1
            ? $sessionId
            : null;
    }

    /**
     * Clear all local identity state, expire the matching cookie, and remove
     * the server-side session record.
     */
    public static function invalidate(): void
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
            throw new RuntimeException('The citizen session could not be invalidated.');
        }
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string} */
    private static function cookieParameters(): array
    {
        $https = isset($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            && (string) $_SERVER['HTTPS'] !== '';

        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
