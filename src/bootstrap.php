<?php

if (!ob_get_level()) {
    ob_start();
}

// The legacy MySQL fallback is loaded only if a remote profile is absent and
// an explicit server-side opt-in enables it.
$configPath = __DIR__ . '/../config/database.php';
$legacyDatabaseProvider = static function () use ($configPath) {
    if (!is_file($configPath)) {
        return null;
    }

    require_once $configPath;
    return legacyDatabaseIfEnabled();
};

// Load Repositories
require_once __DIR__ . '/Repositories/UserRepository.php';
require_once __DIR__ . '/Repositories/PermissionRepository.php';

// Load Services
require_once __DIR__ . '/Services/AuthService.php';
require_once __DIR__ . '/Services/AdminSessionManager.php';
require_once __DIR__ . '/Services/UserService.php';
require_once __DIR__ . '/Services/PermissionService.php';
require_once __DIR__ . '/Services/HeaderService.php';

// Load Middleware
require_once __DIR__ . '/Middleware/SessionTimeout.php';
require_once __DIR__ . '/Middleware/AdminSessionGuard.php';

// Support dynamic basePath if defined before requiring bootstrap.php
$currentBasePath = $basePath ?? '../';
$adminSessionManager = new \App\Services\AdminSessionManager();
$adminSessionGuard = new \App\Middleware\AdminSessionGuard($adminSessionManager);
$adminSessionGuard->requirePage($currentBasePath);
$adminSessionExpiresAt = $adminSessionManager->expiresAt();
$authService = new \App\Services\AuthService($adminSessionManager);

// Repositories resolve the provider only if a legacy query is actually made.
$userRepo = new \App\Repositories\UserRepository($legacyDatabaseProvider);
$permRepo = new \App\Repositories\PermissionRepository($legacyDatabaseProvider);

// Initialize Services
$userService = new \App\Services\UserService($userRepo);
$permService = new \App\Services\PermissionService($permRepo);

// Initialize Header Service (and build user)
$headerService = new \App\Services\HeaderService($userService, $permService, $authService);
$headerUser = $headerService->buildHeaderUser();
