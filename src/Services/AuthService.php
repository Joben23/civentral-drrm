<?php

declare(strict_types=1);

namespace App\Services;

use App\Middleware\AdminSessionGuard;

require_once __DIR__ . '/AdminSessionManager.php';
require_once __DIR__ . '/../Middleware/AdminSessionGuard.php';

class AuthService
{
    private AdminSessionManager $sessions;
    private AdminSessionGuard $guard;

    public function __construct(?AdminSessionManager $sessions = null)
    {
        $this->sessions = $sessions ?? new AdminSessionManager();
        AdminSessionManager::start();
        $this->guard = new AdminSessionGuard($this->sessions);
    }

    public function isLoggedIn(): bool
    {
        $status = $this->guard->status();
        if ($status === AdminSessionManager::STATUS_EXPIRED && AdminSessionGuard::isApiRequest()) {
            AdminSessionGuard::respondApiFailure($status);
        }

        return $status === AdminSessionManager::STATUS_ACTIVE;
    }

    public function currentUserId(): mixed
    {
        return $this->isLoggedIn()
            ? ($_SESSION['user_id'] ?? ($_SESSION['employee_id'] ?? null))
            : null;
    }

    public function login(mixed $userId): void
    {
        AdminSessionManager::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $this->sessions->markAuthenticated();
    }

    public function logout(): void
    {
        $this->sessions->invalidate();
    }

    public function requireAuth(string $basePath = '../'): void
    {
        $this->guard->requirePage($basePath);
    }
}
