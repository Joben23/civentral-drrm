<?php
session_start();

// Record logout time in login_history & delete active session from user_sessions
if (isset($_SESSION['login_id']) || isset($_SESSION['session_id'])) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = legacyDatabaseIfEnabled();
        if ($db !== null) {
            if (isset($_SESSION['login_id'])) {
                $db->update('login_history', ['logout_time' => date('Y-m-d H:i:s')], ['login_id' => $_SESSION['login_id']]);
            }
            if (isset($_SESSION['session_id'])) {
                $db->delete('user_sessions', ['session_id' => $_SESSION['session_id']]);
            }
        }
    } catch (Exception $e) {
        // Ignore error and proceed to logout
    }
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

// Redirect to login page
header("Location: ../login.php");
exit;
?>
