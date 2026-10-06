<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AdminSessionManager;

require_once __DIR__ . '/AdminSessionGuard.php';

/**
 * Backward-compatible adapter. New code should use AdminSessionGuard directly.
 */
class SessionTimeout
{
    public function __construct(
        private readonly int $timeoutDuration = AdminSessionManager::IDLE_TIMEOUT_SECONDS,
        private readonly string $basePath = '../'
    ) {
        if ($this->timeoutDuration !== AdminSessionManager::IDLE_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException('The admin idle timeout is fixed by AdminSessionManager.');
        }
    }

    public function handle(): void
    {
        (new AdminSessionGuard())->requirePage($this->basePath);
    }
}
