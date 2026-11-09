<?php
declare(strict_types=1);

// ── Load phpdotenv for local dev (vendor/ only exists locally) ──
$_vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($_vendorAutoload)) {
    require_once $_vendorAutoload;
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();
    }
}
unset($_vendorAutoload);

// ── Global error handler ──────────────────────────────────────
require_once __DIR__ . '/error_handler.php';
registerErrorHandlers();

// ═══════════════════════════════════════════════════════════════
//  config/constants.php
//  All application-wide constants in one place.
//  Uses environment variables for production (Railway).
//  Falls back to localhost defaults for local XAMPP dev.
// ═══════════════════════════════════════════════════════════════

// ── Env var helper — reads $_ENV, $_SERVER, then getenv() ─────
// Railway injects vars into $_SERVER (not getenv()) inside Docker.
// This fallback chain covers all three sources.
function _env(string $key, mixed $default = ''): mixed {
    return $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) ?: $default);
}

// ── Database ──────────────────────────────────────────────────
define('DB_HOST', _env('MYSQLHOST',     _env('DB_HOST',     'localhost')));
define('DB_PORT', (int)_env('MYSQLPORT', _env('DB_PORT',    3306)));
define('DB_NAME', _env('MYSQLDATABASE', _env('DB_NAME',     'parisar_db')));
// No silent fallback to root on a real server (security review H5).
require_once __DIR__ . '/../helpers/DbConfig.php';
try {
    [$_dbUser, $_dbPass] = resolveDbCredentials(
        (string)DB_HOST,
        (string)_env('MYSQLUSER',     _env('DB_USER', '')),
        (string)_env('MYSQLPASSWORD', _env('DB_PASS', ''))
    );
} catch (RuntimeException $e) {
    error_log('config/constants.php: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        throw $e;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(503);
    }
    echo json_encode(['success' => false, 'error' => 'Database unavailable. Please try again later.']);
    exit;
}
define('DB_USER', $_dbUser);
define('DB_PASS', $_dbPass);
unset($_dbUser, $_dbPass);

// ── Application ───────────────────────────────────────────────
define('BASE_URL',  _env('BASE_URL', 'http://localhost/Parisar'));
define('APP_NAME',  'CycleAudit');
define('APP_ORG',   'Parisar');

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 60 * 60 * 8);   // 8 hours in seconds
define('SESSION_NAME',     'ca_session');

// ── Scoring thresholds (SCORE: 0=best, 100=worst — matches PDF & Excel) ──
//  Condition bands per the Cycle Track Audit Report 2025:
//    0–20   → Good
//    20–40  → OK
//    40–60  → Poor
//    60–80  → Bad
//    80–100 → Very Bad
//
//  These constants are the score UPPER-BOUND for each band:
define('SCORE_GOOD',      20);   // score ≤ 20 → Good
define('SCORE_OK',        40);   // score ≤ 40 → OK
define('SCORE_POOR',      60);   // score ≤ 60 → Poor
define('SCORE_BAD',       80);   // score ≤ 80 → Bad
// score > 80 → Very Bad

// ── Legacy alias ──────────────────────────────────────────────
define('SCORE_MODERATE', 40);

// ── Pagination ────────────────────────────────────────────────
define('PER_PAGE', 20);

// ── File paths ────────────────────────────────────────────────
define('ROOT_PATH',     dirname(__DIR__));
define('SERVICES_PATH', ROOT_PATH . '/services');
define('REPORTS_PATH',  ROOT_PATH . '/reports');

// ── Secure session bootstrap ───────────────────────────────────
/**
 * Detects whether the current request is HTTPS, accounting for
 * Railway (or any reverse proxy) terminating TLS and forwarding
 * the scheme via X-Forwarded-Proto. Used by startSecureSession()
 * to decide whether cookies should be marked HTTPS-only.
 */
function isRequestHttps(): bool
{
    return (
        ($_SERVER['HTTPS']                    ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    );
}

/**
 * Call this instead of bare session_start() in every auth file
 * and auth_guard.php. Enforces cookie security in PHP itself,
 * giving defence-in-depth on top of the Dockerfile php.ini flags.
 * Safe to call multiple times — exits early if already active.
 */
function startSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Railway terminates TLS at the proxy and forwards the scheme
    // via X-Forwarded-Proto. Check both to detect HTTPS correctly.
    $isHttps = isRequestHttps();

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',        // current domain only
        'secure'   => $isHttps,  // HTTPS-only cookie on Railway
        'httponly' => true,      // JS cannot read session cookie
        'samesite' => 'Lax',  // blocks cross-site cookie sending
    ]);

    session_start();
}

/**
 * Send the Content-Security-Policy header.
 * Used by config/auth_guard.php (protected pages) and by the sign-in pages in
 * auth/, so every HTML page gets the same policy. Call before any output.
 */
function sendCspHeader(): void
{
    if (headers_sent()) {
        return;
    }

    // Fresh per-request nonce for inline <script> blocks (pages read it as $_SESSION['csp_nonce'])
    $nonce = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $nonce = base64_encode(random_bytes(16));
        $_SESSION['csp_nonce'] = $nonce;
    }
    // Scripts run only from this site or with this request's nonce ('unsafe-inline' is gone).
    $scriptSrc = "'self'" . ($nonce !== '' ? " 'nonce-" . $nonce . "'" : '');
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src " . $scriptSrc . "; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data:; " .
        "connect-src 'self'; " .
        "object-src 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "frame-ancestors 'self';"
    );
}

/**
 * Call after startSecureSession() on every protected page/API.
 * Destroys the session and sends the user back to login if they
 * have been inactive longer than SESSION_LIFETIME seconds.
 */
function enforceSessionTimeout(): void
{
    if (empty($_SESSION['user_id'])) {
        return; // not logged in — auth_guard.php handles the redirect
    }

    $lastActivity = (int)($_SESSION['_last_activity'] ?? 0);

    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();

        $isApi = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
              || str_contains($_SERVER['REQUEST_URI']  ?? '', '/api/');

        if ($isApi) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        } else {
            header('Location: /auth/login.php?expired=1');
        }
        exit;
    }

    $_SESSION['_last_activity'] = time();
}