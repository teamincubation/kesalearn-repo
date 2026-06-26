<?php
/**
 * KESA Learn - Logout Handler
 */
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    logActivity('user_logout', 'User logged out: ' . ($_SESSION['user_email'] ?? ''));
}

// Clear remember token for persistent login
clearRememberToken();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

setFlash('success', 'You have been logged out successfully.');
redirect('/');
