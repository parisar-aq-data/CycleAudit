<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/forgot_password.php — "Forgot password?"  (no email needed)
//
//  The person types their email and chooses a NEW password. That password is
//  kept as a pending request (the old one keeps working) and a 6-digit code
//  is shown on screen. They give the code to their City Leader (or an Admin),
//  who checks it is really them and approves. See helpers/PasswordRequest.php.
//
//  The page looks the same whether or not the email belongs to an account (a
//  code is shown either way), so it cannot be used to find out who has an
//  account. It does the same work for unknown emails (the password is hashed
//  either way). Requests are rate limited per IP.
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
require_once __DIR__ . '/../helpers/PasswordReset.php';     // passwordRuleError()
require_once __DIR__ . '/../helpers/PasswordRequest.php';

header('Cache-Control: no-store');

$error    = '';
$clientIp = getClientIp();
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    $rl = checkRateLimit($pdo, $clientIp, 'password_request');
    if (!$rl['allowed']) {
        $error = $rl['message'];
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } elseif (($rule = passwordRuleError($password, $confirm)) !== null) {
        $error = $rule;
    } else {
        // Every valid request counts toward the limit, known address or not.
        recordFailedAttempt($pdo, $clientIp, 'password_request');

        $code = passwordRequestCodeGenerate();
        try {
            // Hash first, for known and unknown emails alike, so both take the same time.
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $user = passwordRequestFindUser($pdo, $email);
            passwordRequestCreate($pdo, $user, $hash, $code, $clientIp);
            passwordRequestPrune($pdo);
            ActivityLogger::log($pdo, ActivityLogger::PASSWORD_RESET_REQUESTED, $user['id'] ?? null, [
                'email' => mb_substr($email, 0, 100), 'found' => $user !== null,
            ]);
        } catch (Throwable $e) {
            // e.g. migration 026 has not been run yet
            error_log('forgot_password error: ' . $e->getMessage());
        }

        // Shown once on the next page (and again on refresh) from the session.
        $_SESSION['pw_request_code'] = $code;
        header('Location: forgot_password.php?sent=1');
        exit;
    }
}

$sentCode = null;
if (($_GET['sent'] ?? '') === '1' && isset($_SESSION['pw_request_code'])) {
    $sentCode = (string)$_SESSION['pw_request_code'];
}

$pageTitle = 'Forgot password';
$cardTitle = 'Forgot password?';
require __DIR__ . '/partials/shell_top.php';
?>

      <?php if ($sentCode !== null): ?>
      <div class="alert alert--ok" role="status">
        Request sent. Your new password starts working once it is approved.
      </div>
      <div class="code-box" aria-live="polite">
        <small>Your code</small>
        <strong><?= htmlspecialchars(substr($sentCode, 0, 3) . ' ' . substr($sentCode, 3), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
      <p class="card-text">
        <b>Next:</b> contact the person who looks after your account (your City Leader, or an Admin if you are a City Leader),
        tell them it is you, and read out this code. They will approve the request.
        Until then, you can keep signing in with your old password.
      </p>
      <p class="card-text">Keep this page open or note the code down. Do not share it with anyone else.</p>
      <div class="card-links"><a href="login.php">Back to sign in</a></div>

      <?php else: ?>
      <p class="card-text">
        Enter your email and choose a new password. You will get a code to give to your City Leader (or an Admin).
        When they approve it, the new password starts working.
      </p>

      <?php if ($error !== ''): ?>
      <div class="alert" id="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" id="forgot-form" class="card-form">
        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /> <path d="M3 7l9 6l9 -6" /></svg>
            <label class="sr-only" for="email">Email address</label>
            <input type="email" id="email" name="email" required maxlength="150"
                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="you@example.com" autocomplete="email" inputmode="email" autofocus
                   <?= $error !== '' ? 'aria-describedby="auth-error"' : '' ?>>
          </div>
        </div>

        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
            <label class="sr-only" for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="8"
                   placeholder="New password" autocomplete="new-password">
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
        <p class="card-text" style="margin:0;font-size:.85em">At least 8 characters, with a letter and a number.</p>

        <button type="submit" class="btn-submit"><span class="btn-label">Send request</span><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l14 0" /> <path d="M13 18l6 -6" /> <path d="M13 6l6 6" /></svg></button>
      </form>

      <div class="card-links"><a href="login.php">Back to sign in</a></div>
      <?php endif; ?>

<?php require __DIR__ . '/partials/shell_bottom.php'; ?>
