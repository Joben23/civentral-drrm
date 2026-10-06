<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AdminSessionManager;

require_once __DIR__ . '/../Services/AdminSessionManager.php';

final class AdminSessionGuard
{
    public function __construct(private readonly ?AdminSessionManager $sessions = null)
    {
    }

    public function requirePage(string $basePath = '../'): void
    {
        $status = $this->manager()->validateAndTouch();
        if ($status === AdminSessionManager::STATUS_ACTIVE) {
            return;
        }

        $reason = $status === AdminSessionManager::STATUS_EXPIRED
            ? '?reason=session_expired'
            : '';
        header('Location: ' . $basePath . 'login.php' . $reason);
        exit;
    }

    public function requireApi(bool $touch = true): void
    {
        $status = $this->manager()->validateAndTouch($touch);
        if ($status === AdminSessionManager::STATUS_ACTIVE) {
            $this->sendExpiryHeader();
            return;
        }

        self::respondApiFailure($status);
    }

    public function status(bool $touch = true): string
    {
        $status = $this->manager()->validateAndTouch($touch);
        if ($status === AdminSessionManager::STATUS_ACTIVE) {
            $this->sendExpiryHeader();
        }

        return $status;
    }

    public function sendExpiryHeader(): void
    {
        $expiresAt = $this->manager()->expiresAt();
        if ($expiresAt !== null && !headers_sent()) {
            header('X-Civentral-Session-Expires-At: ' . $expiresAt);
        }
    }

    public static function respondApiFailure(string $status): never
    {
        $expired = $status === AdminSessionManager::STATUS_EXPIRED;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'status' => 'error',
            'code' => $expired ? 'SESSION_EXPIRED' : 'AUTHENTICATION_REQUIRED',
            'message' => $expired
                ? 'Your session expired due to inactivity.'
                : 'Authentication required.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function isApiRequest(): bool
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $uri = str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? ''));

        return str_contains($script, '/api/') || str_contains($uri, '/api/');
    }

    private function manager(): AdminSessionManager
    {
        return $this->sessions ?? new AdminSessionManager();
    }
}
