<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  auth/register.php — CycleAudit Registration
//  Email and password sign-up
//  Schema: parisar_db → users table
//
//  FIXES APPLIED:
//    1. Auto-login + redirect to dashboard after successful registration
//    2. Sets last_login on registration
//    3. Generates public_id (USR-NNNN) in PHP after INSERT (no trigger needed)
//    4. Rate limiting now uses its own 'register' bucket (previously the
//       check ran but was discarded, and no attempt was ever recorded)
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';
require_once __DIR__ . '/../config/rate_limit.php';

startSecureSession();
sendCspHeader();

// Already logged in — go straight to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../services/IdGenerator.php';
require_once __DIR__ . '/../helpers/Registration.php';   // security review H1

$errors      = [];
$success     = false;
$rateLimited = false;
$clientIp    = getClientIp();
$cities      = listCities($pdo);
$approvalReady  = usersHaveApprovalStatus($pdo);   // false until migration 032 has run
$failureIsOurs  = false;                           // our own refusals must not count against the visitor

// ── Rate limit registrations (own 'register' bucket — independent
//    from login attempts, so a login lockout never blocks
//    registration and vice versa) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rl_check = checkRateLimit($pdo, $clientIp, 'register');
    if (!$rl_check['allowed']) {
        $rateLimited = true;
        $errors['rate_limit'] = $rl_check['message'];
    }
}

// ── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rateLimited) {
    $name   = trim($_POST['name']           ?? '');
    $email  = trim($_POST['email']          ?? '');
    $phone  = trim($_POST['phone']          ?? '');
    $org    = cityNameClean((string)($_POST['organisation'] ?? ''));
    $cityIn = (string)($_POST['city']       ?? '');
    $stateIn = (string)($_POST['state']     ?? '');
    $gender = $_POST['gender']              ?? '';
    $age    = trim($_POST['age']            ?? '');
    $pass   = $_POST['password']            ?? '';
    $conf   = $_POST['confirm_password']    ?? '';

    // ── Validation ────────────────────────────────────────────
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (!preg_match('/^[A-Za-z\s\'\-\.]{2,80}$/', $name)) {
        $errors['name'] = 'Name may only contain letters, spaces, hyphens or apostrophes (2–80 chars).';
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($phone !== '') {
        $digits = preg_replace('/[\s\-\+\(\)]/', '', $phone);
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            $errors['phone'] = 'Enter a valid 10-digit Indian mobile number starting with 6–9.';
        }
    }

    $allowedGenders = ['Male', 'Female', 'Other'];
    if (!in_array($gender, $allowedGenders, true)) {
        $errors['gender'] = 'Please select your gender.';
    }

    if ($age === '') {
        $errors['age'] = 'Age is required.';
    } elseif (!ctype_digit($age) || (int)$age < 16 || (int)$age > 80) {
        $errors['age'] = 'Age must be between 16 and 80.';
    }

    $orgError = organisationError($org);
    if ($orgError !== null) {
        $errors['organisation'] = $orgError;
    }

    // City and State: where the person says they are. Information for the Admin;
    // access to a city's data still comes only from users.city_id, which is set
    // here only when the typed city already exists in CycleAudit (else the Admin
    // places the person later).
    [$homeCity, $homeState, $locErrors] = validateHomeLocation($cityIn, $stateIn);
    $errors = array_merge($errors, $locErrors);
    $signupCityId = $homeCity !== null ? signupCityId($cities, $homeCity) : null;

    if (strlen($pass) < 12) {
        $errors['password'] = 'Password must be at least 12 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
        $errors['password'] = 'Password must contain at least one letter and one number.';
    }

    if ($pass !== $conf) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // ── Duplicate email check ─────────────────────────────────
    // Sign-up stays closed (fails shut) until migration 032 has run.
    if (empty($errors) && !$approvalReady) {
        error_log('register: users.approval_status is missing - run migrations/032_user_approval.sql');
        $errors['rate_limit'] = 'Sign-up is temporarily unavailable. Please contact your City Leader.';
        $failureIsOurs = true;
    }

    if (empty($errors)) {
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    // ── Insert ────────────────────────────────────────────────
    // At most REGISTER_OK_MAX_PER_HOUR new accounts per network per hour: a script
    // can no longer create accounts without limit.
    if (empty($errors)) {
        $okCap = checkAndRecordApiRequest($pdo, $clientIp, 'register_ok', REGISTER_OK_MAX_PER_HOUR, 3600);
        if (!$okCap['allowed']) {
            $errors['rate_limit'] = 'Too many accounts were created from this network. Please try again in an hour, or ask your City Leader to add you.';
            $failureIsOurs = true;
        }
    }

    if (empty($errors)) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users
               (name, email, phone, organisation, gender, age, password,
                city_id, home_city, home_state, role, auth_provider, email_verified, last_login,
                is_active, approval_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'surveyor\', \'local\', 0, NULL, 0, \'pending\')'
        )->execute([
            $name,
            $email,
            $phone  ?: null,
            $org,
            $gender,
            (int)$age,
            $hash,
            $signupCityId,
            $homeCity,
            $homeState,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // ── FIX 1: Generate public_id in PHP (no trigger needed) ──
        $publicId = generatePublicId('USR', $newId);   // same format for every role
        $pdo->prepare('UPDATE users SET public_id = ? WHERE id = ?')
            ->execute([$publicId, $newId]);

        // Sign-up needs approval (security review H1): no sign-in and no session.
        // The account is inactive and 'pending' until the City Leader (or an Admin)
        // approves it on the Users page.
        ActivityLogger::log($pdo, ActivityLogger::USER_REGISTERED, (int)$newId,
            ['email' => mb_substr((string)$email, 0, 100), 'status' => 'pending']);

        clearRateLimitAttempts($pdo, $clientIp, 'register');
        $success = true;
    } else {
        // Validation or duplicate-email failure — count it as a failed attempt
        if (!$failureIsOurs) {
            recordFailedAttempt($pdo, $clientIp, 'register');
        }
    }
}

// ── Helpers ────────────────────────────────────────────────────
function fe(string $k, array $e): string
{
    return isset($e[$k])
        ? '<div class="ferr">' . htmlspecialchars($e[$k]) . '</div>'
        : '';
}
function fc(string $k, array $e): string
{
    return isset($e[$k]) ? ' err' : '';
}
// Inline icon (24x24 stroke icons, same set the sign-in page uses)
function ic(string $name): string
{
    static $p = [
        'user'     => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 11h16"/>',
        'pin'      => '<path d="M9 11a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/><path d="M17.657 16.657l-4.243 4.243a2 2 0 0 1 -2.827 0l-4.244 -4.243a8 8 0 1 1 11.314 0z"/>',
        'flag'     => '<path d="M5 5a5 5 0 0 1 7 0a5 5 0 0 0 7 0v9a5 5 0 0 1 -7 0a5 5 0 0 0 -7 0v-9z"/><path d="M5 21v-7"/>',
        'mail'     => '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z"/><path d="M3 7l9 6l9 -6"/>',
        'phone'    => '<path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2"/>',
        'building' => '<path d="M3 21h18"/><path d="M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/>',
        'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11v-4a4 4 0 0 1 8 0v4"/>',
        'check'    => '<path d="M5 12l5 5l10 -10"/>',
        'eye'      => '<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/>',
        'eyeoff'   => '<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/><path d="M3 3l18 18"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6l-6 6"/>',
        'back'     => '<path d="M5 12h14"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/>',
        'chart'    => '<path d="M3 21h18M6 21v-8M12 21v-14M18 21v-5"/>',
        'file'     => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 13h6M9 17h6"/>',
        'leaf'     => '<path d="M5 21c0 -9 5 -15 15 -16c0 10 -6 15 -15 16z"/><path d="M5 21c3 -6 6 -9 10 -11"/>',
        'alert'    => '<path d="M12 9v4"/><path d="M12 16h.01"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"/>',
    ];
    return '<svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#f6efe0">
<title>Register — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/login.css?v=<?= filemtime(__DIR__ . '/../css/login.css') ?>">
<link rel="stylesheet" href="../css/register.css?v=<?= filemtime(__DIR__ . '/../css/register.css') ?>">
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">document.documentElement.classList.add('js')</script>
</head>
<body class="login reg">

<div class="stage">

  <a class="brand rise" style="--i:0" href="../index.html" aria-label="CycleAudit home">
    <span class="brand-mark"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M19 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M12 19l0 -4l-3 -3l5 -4l2 3l3 0" /> <path d="M17 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg></span>CycleAudit
  </a>
  <a class="back-home rise" style="--i:0" href="../index.html"><?= ic('back') ?> Back to home</a>

  <div class="reg-wheel" aria-hidden="true"><?php require __DIR__ . '/partials/wheel.php'; ?></div>

  <!-- LEFT: pitch -->
  <aside class="reg-pitch">
    <h2 class="pitch-title">
      <span class="rise" style="--i:1">Join the</span>
      <span class="accent rise" style="--i:2">Audit Network.</span>
    </h2>
    <p class="pitch-sub rise" style="--i:3">Register as a surveyor and contribute to your city's cycle infrastructure dataset. Your field data drives Parisar's advocacy with municipal bodies.</p>
    <h3 class="steps-h rise" style="--i:4">What happens next</h3>
    <ol class="reg-steps">
      <li class="rise" style="--i:5"><span class="stp-n">1</span><span><strong>Create your account</strong>It takes about a minute. Your City Leader then reviews your request, and you can sign in as soon as it is approved.</span></li>
      <li class="rise" style="--i:6"><span class="stp-n">2</span><span><strong>Join your city's audit team</strong>Surveyors work with their city's team. If your city is not on CycleAudit yet, a Parisar admin will place you.</span></li>
      <li class="rise" style="--i:7"><span class="stp-n">3</span><span><strong>Audit in the field</strong>Walk or ride a road, one segment at a time, and record what you find.</span></li>
      <li class="rise" style="--i:8"><span class="stp-n">4</span><span><strong>See it published</strong>Once an admin approves your audit, it appears on the CycleAudit home page.</span></li>
    </ol>
  </aside>

  <!-- RIGHT: form card -->
  <main class="reg-zone">
    <section class="card card-in reg-card" aria-labelledby="reg-title">
      <h1 class="card-title" id="reg-title">Create account</h1>
      <p class="reg-sub">Already have an account? <a href="login.php">Sign in</a></p>

      <?php if (isset($errors['rate_limit'])): ?>
      <div class="alert" role="alert"><?= htmlspecialchars($errors['rate_limit']) ?></div>
      <?php elseif ($errors): ?>
      <div class="alert" role="alert" id="reg-alert"><?= ic('alert') ?> Please fix the highlighted <?= count($errors) === 1 ? 'field' : 'fields' ?> below.</div>
      <?php endif; ?>

      <?php if ($success): ?>
      <div class="alert alert--ok" role="status">Thank you, <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>. Your request has been sent.</div>
      <p class="reg-note">Your City Leader (or a Parisar administrator) will check your details and switch your account on. You can sign in as soon as it is approved. If you are not sure who your City Leader is, ask the person who invited you to CycleAudit.</p>
      <p class="reg-sub"><a href="login.php">Back to sign in</a></p>
      <?php else: ?>
      <form method="POST" novalidate id="reg-form" class="reg-form card-form">

        <fieldset class="grp">
          <legend><span class="n">1</span> About you</legend>

          <div class="fg<?= fc('name', $errors) ?>">
            <label for="inp-name">Full name <b class="req" aria-hidden="true">*</b><small>letters only</small></label>
            <div class="ctl"><?= ic('user') ?>
              <input type="text" name="name" id="inp-name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                     placeholder="Enter your full name" maxlength="80" autocomplete="name" aria-required="true">
            </div>
            <?= fe('name', $errors) ?>
          </div>

          <div class="row2">
            <div class="fg<?= fc('age', $errors) ?>">
              <label for="inp-age">Age <b class="req" aria-hidden="true">*</b><small>16–80</small></label>
              <div class="ctl"><?= ic('calendar') ?>
                <input type="number" name="age" id="inp-age" value="<?= htmlspecialchars($_POST['age'] ?? '') ?>"
                       placeholder="Your age" min="16" max="80" inputmode="numeric" aria-required="true">
              </div>
              <?= fe('age', $errors) ?>
            </div>
            <div class="fg<?= fc('gender', $errors) ?>">
              <span class="lbl" id="gender-lbl">Gender <b class="req" aria-hidden="true">*</b></span>
              <div class="seg" role="radiogroup" aria-labelledby="gender-lbl">
                <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                <label><input type="radio" name="gender" value="<?= $g ?>" <?= ($_POST['gender'] ?? '') === $g ? 'checked' : '' ?>><span><?= $g ?></span></label>
                <?php endforeach; ?>
              </div>
              <?= fe('gender', $errors) ?>
            </div>
          </div>

          <div class="row2">
            <div class="fg<?= fc('city', $errors) ?>">
              <label for="inp-city">City <b class="req" aria-hidden="true">*</b></label>
              <div class="ctl"><?= ic('pin') ?>
                <input type="text" name="city" id="inp-city" list="cityList" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>"
                       placeholder="Search your city" maxlength="100" autocomplete="off" aria-required="true">
              </div>
              <?= fe('city', $errors) ?>
            </div>
            <div class="fg<?= fc('state', $errors) ?>">
              <label for="inp-state">State <b class="req" aria-hidden="true">*</b></label>
              <div class="ctl ro"><?= ic('flag') ?>
                <input type="text" name="state" id="inp-state" readonly tabindex="-1" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>"
                       placeholder="Fills in from your city" maxlength="100" autocomplete="off">
              </div>
              <?= fe('state', $errors) ?>
            </div>
          </div>
          <?= locationDatalistsHtml() ?>
        </fieldset>

        <fieldset class="grp">
          <legend><span class="n">2</span> Contact &amp; organisation</legend>

          <div class="fg<?= fc('email', $errors) ?>">
            <label for="inp-email">Email address <b class="req" aria-hidden="true">*</b></label>
            <div class="ctl"><?= ic('mail') ?>
              <input type="email" name="email" id="inp-email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                     placeholder="you@example.com" autocomplete="email" aria-required="true">
            </div>
            <?= fe('email', $errors) ?>
          </div>

          <div class="row2">
            <div class="fg<?= fc('phone', $errors) ?>">
              <label for="inp-phone">Mobile <small>optional</small></label>
              <div class="ctl"><?= ic('phone') ?>
                <input type="tel" name="phone" id="inp-phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       placeholder="10-digit number" maxlength="10" inputmode="numeric" autocomplete="tel-national">
              </div>
              <?= fe('phone', $errors) ?>
            </div>
            <div class="fg<?= fc('organisation', $errors) ?>">
              <label for="inp-org">Organisation <b class="req" aria-hidden="true">*</b></label>
              <div class="ctl"><?= ic('building') ?>
                <input type="text" name="organisation" id="inp-org" value="<?= htmlspecialchars($_POST['organisation'] ?? '') ?>"
                       placeholder="Your NGO or institute" maxlength="200" autocomplete="organization" aria-required="true">
              </div>
              <?= fe('organisation', $errors) ?>
            </div>
          </div>
        </fieldset>

        <fieldset class="grp">
          <legend><span class="n">3</span> Set a password</legend>

          <div class="fg<?= fc('password', $errors) ?>">
            <label for="inp-pass">Password <b class="req" aria-hidden="true">*</b></label>
            <div class="ctl"><?= ic('lock') ?>
              <input type="password" name="password" id="inp-pass" placeholder="Create a password"
                     autocomplete="new-password" aria-required="true" aria-describedby="pw-rules">
              <button type="button" class="eye" data-toggle-pass="inp-pass" aria-controls="inp-pass" aria-pressed="false" aria-label="Show password">
                <span class="eye-on"><?= ic('eye') ?></span><span class="eye-off"><?= ic('eyeoff') ?></span>
              </button>
            </div>
            <div class="str"><div class="str-bar"><i id="sf"></i></div><span id="sl">Enter a password</span></div>
            <ul class="rules" id="pw-rules">
              <li data-rule="len"><?= ic('check') ?>12 or more characters</li>
              <li data-rule="alpha"><?= ic('check') ?>One letter</li>
              <li data-rule="num"><?= ic('check') ?>One number</li>
            </ul>
            <?= fe('password', $errors) ?>
          </div>

          <div class="fg<?= fc('confirm_password', $errors) ?>">
            <label for="inp-conf">Confirm password <b class="req" aria-hidden="true">*</b></label>
            <div class="ctl"><?= ic('lock') ?>
              <input type="password" name="confirm_password" id="inp-conf" placeholder="Re-enter your password"
                     autocomplete="new-password" aria-required="true">
              <button type="button" class="eye" data-toggle-pass="inp-conf" aria-controls="inp-conf" aria-pressed="false" aria-label="Show password">
                <span class="eye-on"><?= ic('eye') ?></span><span class="eye-off"><?= ic('eyeoff') ?></span>
              </button>
            </div>
            <div class="match" id="match" hidden></div>
            <?= fe('confirm_password', $errors) ?>
          </div>
        </fieldset>

        <button type="submit" class="btn-submit"><span class="btn-label">Request surveyor account</span><?= ic('arrow') ?></button>
        <p class="reg-note">Your City Leader checks your request before your account is switched on. You can sign in as soon as it is approved.</p>
      </form>
      <?php endif; ?>
    </section>
  </main>

  <footer class="credit rise" style="--i:9">
    <img src="../assets/parisar-logo.png" alt="Parisar" width="60" height="20">
    <span>An initiative by Parisar, Pune, Maharashtra</span>
  </footer>

</div>

<script src="../js/city_search.js"></script>
<script src="../js/location_fields.js"></script>
<script src="../js/auth_simple.js?v=<?= filemtime(__DIR__ . '/../js/auth_simple.js') ?>"></script>
<script src="../js/register.js?v=<?= filemtime(__DIR__ . '/../js/register.js') ?>"></script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">initLocationFields('inp-city', 'inp-state');</script>
</body>
</html>
