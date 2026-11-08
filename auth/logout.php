<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/logout.php
//  Destroys the session cleanly.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';

startSecureSession();

// Who is signing out, so it can be recorded once the session is gone.
$loggedOutUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Clear all session variables
$_SESSION = [];

// Expire the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

if ($loggedOutUserId !== null) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../helpers/ActivityLogger.php';
    ActivityLogger::log($pdo, ActivityLogger::USER_LOGOUT, $loggedOutUserId);
}

header('Location: ../auth/login.php');
exit;