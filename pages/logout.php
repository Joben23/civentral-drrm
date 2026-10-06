<?php

require_once __DIR__ . '/../src/Services/AdminSessionManager.php';

\App\Services\AdminSessionManager::start();
$logoutReason = ($_GET['reason'] ?? '') === 'session_expired'
    ? 'session_expired'
    : '';

// Preserve the optional legacy audit cleanup before local invalidation.
if (isset($_SESSION['login_id']) || isset($_SESSION['session_id'])) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = legacyDatabaseIfEnabled();
        if ($db !== null) {
            if (isset($_SESSION['login_id'])) {
                $db->update(
                    'login_history',
                    ['logout_time' => date('Y-m-d H:i:s')],
                    ['login_id' => $_SESSION['login_id']]
                );
            }
            if (isset($_SESSION['session_id'])) {
                $db->delete('user_sessions', ['session_id' => $_SESSION['session_id']]);
            }
        }
    } catch (Throwable) {
        // Local invalidation must still complete if optional legacy cleanup fails.
    }
}

(new \App\Services\AdminSessionManager())->invalidate();

header('Location: ../login.php' . ($logoutReason !== '' ? '?reason=session_expired' : ''));
exit;
