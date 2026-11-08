<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/rate_limit.php
//  IP + action-based rate limiting (generalized from login-only).
//
//  Rules:
//    · 5 failed attempts within 15 minutes for a given action → 15-minute lockout
//    · Lockout doubles on each subsequent block (15 → 30 → 60 min), capped at 24h
//    · Successful attempt clears the record for that IP + action
//    · Old rows pruned on every check (no cron needed)
//
//  Each protected endpoint calls these with its own $action string, e.g.
//  'login', 'register', 'password_reset', 'survey_submit'. Attempts are
//  tracked independently per (ip_address, action) pair, so hammering
//  /register doesn't lock you out of /login and vice versa.
// ═══════════════════════════════════════════════════════════════

const RL_MAX_ATTEMPTS  = 5;
const RL_WINDOW_SEC    = 15 * 60;   // 15-minute sliding window
const RL_BASE_LOCK_SEC = 15 * 60;   // first lockout = 15 min

/**
 * Call at the top of the POST handler, before doing the sensitive work
 * (auth check, account creation, sending a reset email, etc.).
 *
 * Returns an array:
 *   ['allowed' => true]
 *   ['allowed' => false, 'retry_after' => int seconds, 'message' => string]
 *
 * @param PDO    $pdo
 * @param string $ip     Pass in getClientIp(), not raw $_SERVER['REMOTE_ADDR']
 * @param string $action A short identifier for the endpoint, e.g. 'login',
 *                        'register', 'password_reset'. Keep it stable —
 *                        changing it resets that endpoint's counters.
 */
function checkRateLimit(PDO $pdo, string $ip, string $action): array
{
    _pruneOldAttempts($pdo);

    $row = _getRecord($pdo, $ip, $action);

    // ── Currently locked out? ────────────────────────────────────
    if ($row && $row['locked_until'] !== null) {
        $remaining = strtotime($row['locked_until']) - time();
        if ($remaining > 0) {
            $mins = (int)ceil($remaining / 60);
            return [
                'allowed'     => false,
                'retry_after' => $remaining,
                'message'     => "Too many attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.',
            ];
        }
        // Lockout expired — clear it so we start a fresh window
        _clearRecord($pdo, $ip, $action);
    }

    return ['allowed' => true];
}

/**
 * Call after a FAILED attempt for the given action.
 * Increments the counter; locks the IP+action if the threshold is crossed.
 */
function recordFailedAttempt(PDO $pdo, string $ip, string $action): void
{
    $row = _getRecord($pdo, $ip, $action);

    if ($row === null) {
        // First failure for this IP + action
        $pdo->prepare(
            'INSERT INTO rate_limit_attempts (ip_address, action, attempts, last_attempt_at)
             VALUES (?, ?, 1, NOW())'
        )->execute([$ip, $action]);
        return;
    }

    $newAttempts = (int)$row['attempts'] + 1;

    if ($newAttempts >= RL_MAX_ATTEMPTS) {
        // Calculate lockout duration — doubles each time they've been locked
        $lockouts    = (int)($row['lockout_count'] ?? 0) + 1;
        $lockSec     = RL_BASE_LOCK_SEC * (int)pow(2, $lockouts - 1);
        $lockSec     = min($lockSec, 60 * 60 * 24); // cap at 24 hours
        $lockedUntil = date('Y-m-d H:i:s', time() + $lockSec);

        $pdo->prepare(
            'UPDATE rate_limit_attempts
             SET attempts = ?, last_attempt_at = NOW(),
                 locked_until = ?, lockout_count = ?
             WHERE ip_address = ? AND action = ?'
        )->execute([$newAttempts, $lockedUntil, $lockouts, $ip, $action]);
    } else {
        $pdo->prepare(
            'UPDATE rate_limit_attempts
             SET attempts = ?, last_attempt_at = NOW()
             WHERE ip_address = ? AND action = ?'
        )->execute([$newAttempts, $ip, $action]);
    }
}

/**
 * Call after a SUCCESSFUL attempt (e.g. successful login, completed
 * registration). Wipes the record so a legitimate user starts fresh.
 */
function clearRateLimitAttempts(PDO $pdo, string $ip, string $action): void
{
    _clearRecord($pdo, $ip, $action);
}

/**
 * Returns the number of remaining attempts before lockout for this
 * IP + action. Use this to show a warning on a form ("2 attempts remaining").
 */
function remainingAttempts(PDO $pdo, string $ip, string $action): int
{
    $row = _getRecord($pdo, $ip, $action);
    if ($row === null) {
        return RL_MAX_ATTEMPTS;
    }
    return max(0, RL_MAX_ATTEMPTS - (int)$row['attempts']);
}

/**
 * ── General-purpose request throttle (distinct from the failed-attempt
 *    lockout logic above) ─────────────────────────────────────────────
 *
 * Use this for public GET/read endpoints where every request "succeeds"
 * (no login/register-style pass/fail) but repeated hits are still a
 * scraping or resource-exhaustion risk — e.g. a public stats endpoint
 * that recomputes scores across every row on each call.
 *
 * Unlike checkRateLimit()/recordFailedAttempt(), this has no escalating
 * lockout: it's a plain fixed-window cap. Call once per request, at the
 * top of the handler, before doing any real work:
 *
 *   $rl = checkAndRecordApiRequest($pdo, getClientIp(), 'public_stats', 20, 60);
 *   if (!$rl['allowed']) {
 *       http_response_code(429);
 *       echo json_encode(['success' => false, 'error' => $rl['message']]);
 *       exit;
 *   }
 *
 * @param PDO    $pdo
 * @param string $ip          Pass in getClientIp().
 * @param string $action      A short identifier distinct from any
 *                             login/register action, e.g. 'public_stats'.
 * @param int    $maxRequests Max requests allowed within $windowSec.
 * @param int    $windowSec   Rolling window length in seconds.
 */
function checkAndRecordApiRequest(PDO $pdo, string $ip, string $action, int $maxRequests, int $windowSec): array
{
    $row = _getRecord($pdo, $ip, $action);

    if ($row === null) {
        $pdo->prepare(
            'INSERT INTO rate_limit_attempts (ip_address, action, attempts, last_attempt_at)
             VALUES (?, ?, 1, NOW())'
        )->execute([$ip, $action]);
        return ['allowed' => true];
    }

    $secondsSinceLast = time() - strtotime($row['last_attempt_at']);

    // Window has fully elapsed since the last request — reset the counter.
    if ($secondsSinceLast > $windowSec) {
        $pdo->prepare(
            'UPDATE rate_limit_attempts
             SET attempts = 1, last_attempt_at = NOW(), locked_until = NULL
             WHERE ip_address = ? AND action = ?'
        )->execute([$ip, $action]);
        return ['allowed' => true];
    }

    $newAttempts = (int)$row['attempts'] + 1;

    $pdo->prepare(
        'UPDATE rate_limit_attempts
         SET attempts = ?, last_attempt_at = NOW()
         WHERE ip_address = ? AND action = ?'
    )->execute([$newAttempts, $ip, $action]);

    if ($newAttempts > $maxRequests) {
        $retryAfter = max(1, $windowSec - $secondsSinceLast);
        return [
            'allowed'     => false,
            'retry_after' => $retryAfter,
            'message'     => 'Too many requests. Please slow down and try again shortly.',
        ];
    }

    return ['allowed' => true];
}

// ── Private helpers ──────────────────────────────────────────────

function _getRecord(PDO $pdo, string $ip, string $action): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM rate_limit_attempts WHERE ip_address = ? AND action = ? LIMIT 1'
    );
    $stmt->execute([$ip, $action]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function _clearRecord(PDO $pdo, string $ip, string $action): void
{
    $pdo->prepare('DELETE FROM rate_limit_attempts WHERE ip_address = ? AND action = ?')
        ->execute([$ip, $action]);
}

function _pruneOldAttempts(PDO $pdo): void
{
    // Remove rows with no active lockout whose last attempt is outside the window
    $pdo->prepare(
        'DELETE FROM rate_limit_attempts
         WHERE locked_until IS NULL
           AND last_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)'
    )->execute([RL_WINDOW_SEC]);

    // Also remove rows where the lockout has fully expired
    $pdo->prepare(
        'DELETE FROM rate_limit_attempts
         WHERE locked_until IS NOT NULL
           AND locked_until < NOW()'
    )->execute([]);
}

/**
 * Resolves the real client IP.
 *
 * X-Forwarded-For is only trusted when the direct connection (REMOTE_ADDR)
 * comes from a known Railway/private proxy range — prevents attackers from
 * spoofing the header to bypass IP-based rate limiting (Issue 18).
 *
 * Railway proxy ranges: 100.64.0.0/10 (CGNAT) + RFC-1918 private ranges.
 * On local XAMPP, REMOTE_ADDR is 127.0.0.1 — also trusted for dev.
 */
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Trusted proxy subnets: Railway CGNAT + private ranges + localhost
    $trustedProxyCidrs = [
        '100.64.0.0/10',  // Railway CGNAT range
        '10.0.0.0/8',     // RFC-1918
        '172.16.0.0/12',  // RFC-1918
        '192.168.0.0/16', // RFC-1918
        '127.0.0.0/8',    // localhost / XAMPP dev
    ];

    $isTrustedProxy = false;
    $remoteIp = inet_pton($remoteAddr);
    if ($remoteIp !== false) {
        foreach ($trustedProxyCidrs as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $subnetIp  = inet_pton($subnet);
            $mask      = str_repeat("\xff", (int)($bits / 8))
                       . (($bits % 8) ? chr(0xff << (8 - ($bits % 8))) : '')
                       . str_repeat("\x00", strlen($subnetIp) - (int)ceil($bits / 8));
            if (($remoteIp & $mask) === ($subnetIp & $mask)) {
                $isTrustedProxy = true;
                break;
            }
        }
    }

    if ($isTrustedProxy) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // X-Forwarded-For is a comma-separated list — leftmost is the client
            $ips = array_map('trim', explode(',', $forwarded));
            $ip  = filter_var($ips[0], FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
    }

    return $remoteAddr;
}
