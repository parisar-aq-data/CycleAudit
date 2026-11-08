<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/auth_guard.php
//  require_once this at the TOP of EVERY protected page or API.
//  • Browser requests  → redirect to login page
//  • JSON/XHR requests → 401 JSON response
//
//  Also exposes:
//    $CURRENT_USER_ID   (int)
//    $CURRENT_USER_NAME (string)
//    $CURRENT_USER_ROLE (string)
//    $CURRENT_USER_PIC  (string|null)
//    $_SESSION['csrf_token'] — generated on first use
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/db.php';

startSecureSession();
enforceSessionTimeout();

// ── Content-Security-Policy ────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
$_SESSION['csp_nonce'] = $nonce;
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline'; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self';"
);

if (!isset($_SESSION['user_id'])) {
    $wantsJson = (
        str_contains($_SERVER['HTTP_ACCEPT']        ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['CONTENT_TYPE']    ?? '', 'application/json')
    );

    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    } else {
        // Compute redirect depth from the calling script's location
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
        $docRoot   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $relPath   = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $depth     = ($relPath !== '') ? count(explode('/', $relPath)) : 0;
        $prefix    = $depth > 0 ? str_repeat('../', $depth) : './';
        header("Location: {$prefix}auth/login.php");
    }
    exit;
}

// ── Ensure a CSRF token exists for this session ────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Convenience variables available in every protected file ───
$CURRENT_USER_ID   = (int)$_SESSION['user_id'];
$CURRENT_USER_NAME = (string)($_SESSION['user_name']       ?? '');

// Always verify role + active status fresh from the DB on every request —
// don't trust the session cache for these. This makes role changes
// (promote/demote) and account deactivation take effect immediately,
// instead of waiting for the affected user to log out and back in.
$stmt = $pdo->prepare('SELECT role, is_active, profile_picture, city_id FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$CURRENT_USER_ID]);
$freshUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$freshUser || (int)$freshUser['is_active'] === 0) {
    // Account deactivated (or deleted) since this session began — force logout.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    $wantsJson = (
        str_contains($_SERVER['HTTP_ACCEPT']         ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['CONTENT_TYPE']     ?? '', 'application/json')
    );

    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Your account has been deactivated. Please contact an admin.']);
    } else {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
        $docRoot   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $relPath   = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $depth     = ($relPath !== '') ? count(explode('/', $relPath)) : 0;
        $prefix    = $depth > 0 ? str_repeat('../', $depth) : './';
        header("Location: {$prefix}auth/login.php?deactivated=1");
    }
    exit;
}

// A password changed by an approved "forgot password" request ends every
// sign-in that started before it (helpers/PasswordRequest.php).
require_once __DIR__ . '/../helpers/PasswordRequest.php';
if (passwordChangedSinceLogin($pdo, $CURRENT_USER_ID, (int)($_SESSION['auth_at'] ?? 0))) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    $wantsJson = (
        str_contains($_SERVER['HTTP_ACCEPT']         ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['CONTENT_TYPE']     ?? '', 'application/json')
    );
    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Your password was changed. Please sign in again.']);
    } else {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
        $docRoot   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $relPath   = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $depth     = ($relPath !== '') ? count(explode('/', $relPath)) : 0;
        $prefix    = $depth > 0 ? str_repeat('../', $depth) : './';
        header("Location: {$prefix}auth/login.php");
    }
    exit;
}

$CURRENT_USER_ROLE     = (string)$freshUser['role'];
$_SESSION['user_role'] = $CURRENT_USER_ROLE; // keep session cache in sync

// A person whose password was reset by staff must choose their own before using
// anything else (helpers/StaffPasswordReset.php, auth/force_password.php).
require_once __DIR__ . '/../helpers/StaffPasswordReset.php';
if (!mustChangePasswordExempt((string)($_SERVER['SCRIPT_NAME'] ?? '')) && userMustChangePassword($pdo, $CURRENT_USER_ID)) {
    $mustChangeWantsJson = (
        str_contains($_SERVER['HTTP_ACCEPT']         ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['CONTENT_TYPE']     ?? '', 'application/json')
    );

    if ($mustChangeWantsJson) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Choose a new password first.']);
    } else {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
        $docRoot   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $relPath   = ltrim(str_replace($docRoot, '', $scriptDir), '/');
        $depth     = ($relPath !== '') ? count(explode('/', $relPath)) : 0;
        $prefix    = $depth > 0 ? str_repeat('../', $depth) : './';
        header("Location: {$prefix}auth/force_password.php");
    }
    exit;
}

// City Leaders only get the screens built for them (helpers/CityLeaderGate.php).
// Every other page and API is closed to them here, not just hidden from the menu.
require_once __DIR__ . '/../helpers/CityLeaderGate.php';
if ($CURRENT_USER_ROLE === 'city_leader' && !cityLeaderMayAccess((string)($_SERVER['SCRIPT_NAME'] ?? ''))) {
    $clRedirect = cityLeaderRedirectTarget((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($clRedirect !== null) {
        header('Location: ' . $clRedirect);
    } else {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This area is not available to City Leaders.']);
    }
    exit;
}

// city_id is nullable — NULL for admin (national scope) and for
// any user not yet assigned a city. Kept fresh from the DB for the same
// reason role is: a city reassignment should take effect immediately.
$CURRENT_USER_CITY_ID     = $freshUser['city_id'] !== null ? (int)$freshUser['city_id'] : null;
$_SESSION['user_city_id'] = $CURRENT_USER_CITY_ID;

// Belt-and-suspenders: if the session profile_picture is missing, always
// re-fetch it from the DB (covers session loss after session_regenerate_id,
// etc.) and restore for next request.
$CURRENT_USER_PIC = $_SESSION['profile_picture'] ?? null;
if ($CURRENT_USER_PIC === null && !empty($freshUser['profile_picture'])) {
    $CURRENT_USER_PIC            = $freshUser['profile_picture'];
    $_SESSION['profile_picture'] = $CURRENT_USER_PIC; // restore for next request
}
