<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/PasswordReset.php
//  One-time "forgot password" links.
//
//  · The link carries a random 256-bit token; only its SHA-256 hash is stored.
//  · A link works once and expires after PASSWORD_RESET_TTL_MINUTES.
//  · Asking for a new link cancels the older ones.
//  · Times are passed in as Unix timestamps (default: now) and compared as
//    'Y-m-d H:i:s' strings, so the database clock and time zone never matter.
// ═══════════════════════════════════════════════════════════════

const PASSWORD_RESET_TTL_MINUTES   = 60;
const PASSWORD_RESET_COOLDOWN_SEC  = 120;   // at most one email per account per 2 minutes
const PASSWORD_RESET_KEEP_DAYS     = 7;     // old rows are deleted after this

function passwordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function passwordResetStamp(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

/**
 * The same rules as sign-up: 12+ characters with a letter and a number.
 * Returns an error message, or null when the password is acceptable.
 */
function passwordRuleError(string $password, string $confirm): ?string
{
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    if ($password !== $confirm) {
        return 'Passwords do not match.';
    }
    return null;
}

/**
 * The active account for an email address, or null. Used by the forgot page.
 *
 * @return array{id:int, name:string, email:string}|null
 */
function passwordResetFindUser(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT id, name, email, is_active FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || (isset($u['is_active']) && (int)$u['is_active'] === 0)) {
        return null;
    }
    return ['id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)$u['email']];
}

/** True when this account already asked for a link a moment ago (stops mail flooding). */
function passwordResetRecentlyRequested(PDO $pdo, int $userId, ?int $now = null): bool
{
    $now ??= time();
    $st = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?');
    $st->execute([$userId, passwordResetStamp($now - PASSWORD_RESET_COOLDOWN_SEC)]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Makes a new link for the account and cancels its older unused ones.
 * Returns the raw token (goes into the email, never stored).
 */
function passwordResetCreate(PDO $pdo, int $userId, string $ip, ?int $now = null): string
{
    $now ??= time();
    $token = bin2hex(random_bytes(32));

    $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([passwordResetStamp($now), $userId]);

    $pdo->prepare('DELETE FROM password_resets WHERE created_at < ?')
        ->execute([passwordResetStamp($now - PASSWORD_RESET_KEEP_DAYS * 86400)]);

    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, requested_ip)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        passwordResetTokenHash($token),
        passwordResetStamp($now + PASSWORD_RESET_TTL_MINUTES * 60),
        passwordResetStamp($now),
        substr($ip, 0, 45),
    ]);

    return $token;
}

/**
 * Who a link belongs to, if it is still usable (right shape, unused, not
 * expired, account active). Otherwise null.
 *
 * @return array{user_id:int, name:string, email:string}|null
 */
function passwordResetLookup(PDO $pdo, string $token, ?int $now = null): ?array
{
    $now ??= time();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT u.id AS user_id, u.name, u.email, u.is_active
         FROM password_resets r
         JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?
         LIMIT 1'
    );
    $st->execute([passwordResetTokenHash($token), passwordResetStamp($now)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (isset($row['is_active']) && (int)$row['is_active'] === 0)) {
        return null;
    }
    return ['user_id' => (int)$row['user_id'], 'name' => (string)$row['name'], 'email' => (string)$row['email']];
}

/**
 * Sets the new password and uses up the link (and any other open links of
 * that account). The link is claimed first, so two clicks at once cannot both win.
 * The caller has already checked the password with passwordRuleError().
 */
function passwordResetComplete(PDO $pdo, string $token, string $newPassword, ?int $now = null): bool
{
    $now ??= time();
    $user = passwordResetLookup($pdo, $token, $now);
    if ($user === null) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare(
            'UPDATE password_resets SET used_at = ?
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
        );
        $claim->execute([passwordResetStamp($now), passwordResetTokenHash($token), passwordResetStamp($now)]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        // password_changed_at ends every sign-in that started before this reset
        // (see passwordChangedSinceLogin() in helpers/PasswordRequest.php).
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        try {
            $pdo->prepare('UPDATE users SET password = ?, password_changed_at = ? WHERE id = ?')
                ->execute([$newHash, gmdate('Y-m-d H:i:s', $now), $user['user_id']]);   // UTC, as passwordChangedSinceLogin() reads it
        } catch (PDOException $e) {
            // Column not there yet (migration 026): still reset, but log it.
            error_log('passwordResetComplete: password_changed_at missing: ' . $e->getMessage());
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([$newHash, $user['user_id']]);
        }

        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
            ->execute([passwordResetStamp($now), $user['user_id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** The link that goes into the email. BASE_URL comes from the environment, never from the request. */
function passwordResetLink(string $baseUrl, string $token): string
{
    return rtrim($baseUrl, '/') . '/auth/reset_password.php?token=' . $token;
}

/**
 * The email text.
 *
 * @return array{subject:string, text:string, html:string}
 */
function passwordResetEmail(string $name, string $link, string $appName = 'CycleAudit'): array
{
    $minutes = PASSWORD_RESET_TTL_MINUTES;
    $first   = trim(explode(' ', trim($name))[0] ?? '');
    $hello   = $first !== '' ? "Hi {$first}," : 'Hi,';

    $text = "{$hello}\n\n"
          . "We received a request to reset your {$appName} password. Open this link to choose a new one:\n\n"
          . "{$link}\n\n"
          . "The link works once and expires in {$minutes} minutes.\n\n"
          . "If you did not ask for this, you can ignore this email. Your password stays the same.\n";

    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1c2a14">'
          . '<p>' . $e($hello) . '</p>'
          . '<p>We received a request to reset your ' . $e($appName) . ' password.</p>'
          . '<p><a href="' . $e($link) . '" style="display:inline-block;background:#3d8a24;color:#ffffff;'
          . 'text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold">Choose a new password</a></p>'
          . '<p style="font-size:13px;color:#4b5a3f">Or paste this link into your browser:<br>' . $e($link) . '</p>'
          . '<p style="font-size:13px;color:#4b5a3f">The link works once and expires in ' . $minutes . ' minutes. '
          . 'If you did not ask for this, you can ignore this email. Your password stays the same.</p>'
          . '</div>';

    return ['subject' => "Reset your {$appName} password", 'text' => $text, 'html' => $html];
}
