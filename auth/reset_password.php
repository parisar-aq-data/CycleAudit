<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/reset_password.php — choose a new password
//  Opened from the emailed link (auth/forgot_password.php).
//  The link works once and expires; after a successful reset the person
//  is sent to the sign-in page. Wrong or used links get one plain message.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';

startSecureSession();

if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../helpers/PasswordReset.php';

header('Cache-Control: no-store');

$clientIp = getClientIp();
$token    = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$error    = '';
$invalid  = false;
$user     = null;

try {
    $user = passwordResetLookup($pdo, $token);
} catch (Throwable $e) {
    error_log('reset_password lookup error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rl = checkRateLimit($pdo, $clientIp, 'reset_token');
    if (!$rl['allowed']) {
        $error = $rl['message'];
    } elseif ($user === null) {
        recordFailedAttempt($pdo, $clientIp, 'reset_token');
        $invalid = true;
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');
        $rule     = passwordRuleError($password, $confirm);

        if ($rule !== null) {
            $error = $rule;
        } else {
            try {
                $done = passwordResetComplete($pdo, $token, $password);
            } catch (Throwable $e) {
                error_log('reset_password error: ' . $e->getMessage());
                $done  = false;
                $error = 'Something went wrong. Please try again.';
            }
            if ($done) {
                clearRateLimitAttempts($pdo, $clientIp, 'reset_token');
                ActivityLogger::log($pdo, ActivityLogger::PASSWORD_RESET_COMPLETED, (int)$user['user_id']);
                clearRateLimitAttempts($pdo, $clientIp, 'login');
                header('Location: login.php?reset=1');
                exit;
            }
            if ($error === '') {
                $invalid = true;
            }
        }
    }
} elseif ($user === null) {
    $invalid = true;
}

$invalid = $invalid || $user === null;

$pageTitle = 'Choose a new password';
$cardTitle = 'New password';
require __DIR__ . '/partials/shell_top.php';
?>

      <?php if ($invalid): ?>
      <div class="alert" role="alert">This reset link is not valid. It may have expired or already been used.</div>
      <p class="card-text">Reset links work once and expire after <?= PASSWORD_RESET_TTL_MINUTES ?> minutes. You can ask for a new one.</p>
      <div class="card-links"><a href="forgot_password.php">Send me a new link</a><a href="login.php">Sign in</a></div>

      <?php else: ?>
      <p class="card-text">Choose a new password for <strong><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>. Use at least 8 characters, with a letter and a number.</p>

      <?php if ($error !== ''): ?>
      <div class="alert" id="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" id="reset-form" class="card-form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
            <label class="sr-only" for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="8"
                   placeholder="New password" autocomplete="new-password" autofocus
                   <?= $error !== '' ? 'aria-describedby="auth-error"' : '' ?>>
            <button type="button" class="eye" data-toggle-pass="password" aria-controls="password" aria-pressed="false" aria-label="Show password">
              <span class="eye-on"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg></span><span class="eye-off"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /> <path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /> <path d="M3 3l18 18" /></svg></span>
            </button>
          </div>
        </div>

        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
            <label class="sr-only" for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                   placeholder="Confirm new password" autocomplete="new-password">
          </div>
        </div>

        <button type="submit" class="btn-submit"><span class="btn-label">Change password</span><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l14 0" /> <path d="M13 18l6 -6" /> <path d="M13 6l6 6" /></svg></button>
      </form>
      <?php endif; ?>

<?php require __DIR__ . '/partials/shell_bottom.php'; ?>
