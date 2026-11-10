<?php
declare(strict_types=1);

// ---------------------------------------------------------------
//  auth/force_password.php - choose a new password
//  Shown after an Admin or City Leader has reset the account (the person
//  signs in with a temporary password and must pick their own before using
//  the site). config/auth_guard.php sends them here.
//
//  This page does not use auth_guard.php on purpose: the guard redirects
//  here, and City Leaders are limited to a fixed page list. It checks the
//  session and the database itself.
// ---------------------------------------------------------------

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';

startSecureSession();
sendCspHeader();
enforceSessionTimeout();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/PasswordReset.php';
require_once __DIR__ . '/../helpers/StaffPasswordReset.php';

header('Cache-Control: no-store');

$userId = (int)$_SESSION['user_id'];

$st = $pdo->prepare('SELECT email, password, is_active FROM users WHERE id = ? LIMIT 1');
$st->execute([$userId]);
$account = $st->fetch(PDO::FETCH_ASSOC);

if (!$account || (int)$account['is_active'] === 0) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?deactivated=1');
    exit;
}

// Nothing owed: go on to the dashboard.
if (!userMustChangePassword($pdo, $userId)) {
    header('Location: ../pages/dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $rule = passwordRuleError($password, $confirm);
        if ($rule !== null) {
            $error = $rule;
        } elseif (password_verify($password, (string)$account['password'])) {
            $error = 'Choose a different password from the temporary one.';
        } else {
            try {
                if (staffPasswordSetOwn($pdo, $userId, $password)) {
                    ActivityLogger::log($pdo, ActivityLogger::PASSWORD_CHANGED, $userId, ['source' => 'first_login']);
                    header('Location: ../pages/dashboard.php');
                    exit;
                }
                $error = 'Something went wrong. Please try again.';
            } catch (Throwable $e) {
                error_log('force_password error: ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Choose a new password';
$cardTitle = 'New password';
require __DIR__ . '/partials/shell_top.php';
?>

      <p class="card-text">Your password was reset, so you are using a temporary one. Choose a new password for <strong><?= htmlspecialchars((string)$account['email'], ENT_QUOTES, 'UTF-8') ?></strong> to continue. Use at least 12 characters, with a letter and a number.</p>

      <?php if ($error !== ''): ?>
      <div class="alert" id="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" id="force-form" class="card-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
            <label class="sr-only" for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="12"
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
            <input type="password" id="confirm_password" name="confirm_password" required minlength="12"
                   placeholder="Confirm new password" autocomplete="new-password">
          </div>
        </div>

        <button type="submit" class="btn-submit"><span class="btn-label">Save and continue</span><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l14 0" /> <path d="M13 18l6 -6" /> <path d="M13 6l6 6" /></svg></button>
      </form>

      <?php if (is_file(__DIR__ . '/logout.php')): ?>
      <div class="card-links"><a href="logout.php">Sign out</a></div>
      <?php endif; ?>

<?php require __DIR__ . '/partials/shell_bottom.php'; ?>
