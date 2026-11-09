<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/login.php — CycleAudit Login
//  Email and password sign-in
//  Schema: parisar_db → users table
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';

startSecureSession();

// Already logged in → dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit.php';

$error     = '';
$clientIp  = getClientIp();

// ── Handle POST (local login) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {

        // ── Rate limit check ───────────────────────────────────
        $rl = checkRateLimit($pdo, $clientIp, 'login');
        if (!$rl['allowed']) {
            $error = $rl['message'];
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check a password hash even when the email is unknown, so a reply
            // takes the same time whether or not the account exists.
            $hashToCheck = $user ? (string)$user['password'] : '$2y$10$wHAeNL374pUQ6RTlNo/gg.8xwy.lq4.rb/tKQb/30FHv2Iqjmm/3K';
            $passwordOk  = password_verify($password, $hashToCheck);

            if ($user && $passwordOk) {
                if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                    // Waiting for approval (security review H1) reads differently from a deactivation.
                    $approval = (string)($user['approval_status'] ?? 'approved');
                    if ($approval === 'pending') {
                        $error = 'Your account is waiting for approval by your City Leader. You can sign in as soon as it is approved.';
                    } elseif ($approval === 'rejected') {
                        $error = 'Your sign-up request was not approved. Please contact your City Leader or an administrator.';
                    } else {
                        $error = 'This account has been deactivated. Please contact an administrator.';
                    }
                    ActivityLogger::log($pdo, ActivityLogger::USER_LOGIN_FAILED, (int)$user['id'], ['email' => mb_substr($email, 0, 100), 'reason' => 'deactivated']);
                } else {
                // ── Successful local login ─────────────────────
                clearRateLimitAttempts($pdo, $clientIp, 'login');

                session_regenerate_id(true);
                $_SESSION['user_id']         = $user['id'];
                $_SESSION['user_name']       = $user['name'];
                $_SESSION['user_email']      = $user['email'];
                $_SESSION['user_role']       = $user['role'];
                $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
                $_SESSION['auth_at']         = time();   // see passwordChangedSinceLogin()

                // Generate a CSRF token for this session
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                // Update last_login timestamp
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
                    ->execute([$user['id']]);
                ActivityLogger::log($pdo, ActivityLogger::USER_LOGIN, (int)$user['id']);

                header('Location: ../pages/dashboard.php');
                exit;
                }
            } else {
                // Wrong password or unknown email — record the failure
                recordFailedAttempt($pdo, $clientIp, 'login');
                ActivityLogger::log($pdo, ActivityLogger::USER_LOGIN_FAILED, $user ? (int)$user['id'] : null, [
                    'email' => mb_substr($email, 0, 100), 'reason' => $user ? 'wrong_password' : 'unknown_email',
                ]);
                $remaining = remainingAttempts($pdo, $clientIp, 'login');

                $error = 'Invalid email or password. Please try again.';
                if ($remaining > 0 && $remaining <= 2) {
                    $error .= " ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining before lockout)";
                }
            }
        }
    }
}

// Shown after a successful password reset (auth/reset_password.php)
$notice = ($error === '' && ($_GET['reset'] ?? '') === '1')
    ? 'Your password has been changed. Please sign in with the new password.'
    : '';

// Values used by the template
$prefillEmail  = (string)($_POST['email'] ?? '');
$focusPassword = ($error !== '' && $prefillEmail !== '');
$focusEmail    = ($error !== '' && $prefillEmail === '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#f6efe0">
  <title>Sign in — CycleAudit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/login.css?v=<?= filemtime(__DIR__ . '/../css/login.css') ?>">
  <script>document.documentElement.classList.add('js')</script>
</head>
<body class="login">

<div class="stage">

  <a class="brand rise" style="--i:0" href="../index.html" aria-label="CycleAudit home">
    <span class="brand-mark"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M19 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M12 19l0 -4l-3 -3l5 -4l2 3l3 0" /> <path d="M17 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg></span>CycleAudit
  </a>

  <a class="back-home rise" style="--i:0" href="../index.html">
    <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/></svg>
    Back to home
  </a>

  <section class="pitch">
    <p class="pitch-title"><span class="rise" style="--i:1">Ride.</span> <span class="rise" style="--i:2">Measure.</span> <span class="accent rise" style="--i:3">Improve.</span></p>
    <p class="pitch-sub rise" style="--i:4">Audit every street, score every segment, and publish reports your city can act on.</p>
    <span class="tag rise" style="--i:5"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 18.5l-3 -1.5l-6 3v-13l6 -3l6 3l6 -3v7.5" /> <path d="M9 4v13" /> <path d="M15 7v5.5" /> <path d="M21.121 20.121a3 3 0 1 0 -4.242 0c.418 .419 1.125 1.045 2.121 1.879c1.051 -.89 1.759 -1.516 2.121 -1.879z" /> <path d="M19 18v.01" /></svg>Cycle infrastructure audits</span>
  </section>

  <main class="zone">

    <?php require __DIR__ . '/partials/wheel.php'; ?>

    <section class="card card-in" aria-labelledby="login-title">
      <h1 class="card-title" id="login-title">Sign in</h1>

      <?php if ($error !== ''): ?>
      <div class="alert" id="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
      <div class="alert alert--ok" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" novalidate id="login-form" class="card-form">
        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /> <path d="M3 7l9 6l9 -6" /></svg>
            <label class="sr-only" for="email">Email address</label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($prefillEmail, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="you@example.com" autocomplete="email" inputmode="email"
                   <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                   <?= $focusEmail ? 'autofocus' : '' ?>>
          </div>
          <p class="field-msg" id="email-msg" hidden></p>
        </div>

        <div class="field">
          <div class="f">
            <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
            <label class="sr-only" for="password">Password</label>
            <input type="password" id="password" name="password"
                   placeholder="Password" autocomplete="current-password"
                   <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                   <?= $focusPassword ? 'autofocus' : '' ?>>
            <button type="button" class="eye" data-toggle-pass="password" aria-controls="password" aria-pressed="false" aria-label="Show password">
              <span class="eye-on"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg></span><span class="eye-off"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" /> <path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" /> <path d="M3 3l18 18" /></svg></span>
            </button>
          </div>
          <p class="field-msg" id="password-msg" hidden></p>
        </div>

        <button type="submit" class="btn-submit"><span class="btn-label">Sign in</span><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l14 0" /> <path d="M13 18l6 -6" /> <path d="M13 6l6 6" /></svg></button>
      </form>

      <div class="card-links">
        <a href="forgot_password.php">Forgot password?</a>
        <a href="register.php">Register</a>
      </div>
    </section>
  </main>

  <footer class="credit rise" style="--i:7">
    <img src="../assets/parisar-logo.png" alt="Parisar" width="60" height="20">
    <span>An initiative by Parisar, Pune, Maharashtra</span>
  </footer>

</div>

<script src="../js/login.js?v=<?= filemtime(__DIR__ . '/../js/login.js') ?>"></script>
</body>
</html>
