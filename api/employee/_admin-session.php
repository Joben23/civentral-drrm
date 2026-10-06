<?php

declare(strict_types=1);

use App\Middleware\AdminSessionGuard;
use App\Services\AdminSessionManager;

require_once __DIR__ . '/../../src/Services/AdminSessionManager.php';
require_once __DIR__ . '/../../src/Middleware/AdminSessionGuard.php';

AdminSessionManager::start();
(new AdminSessionGuard())->requireApi();
