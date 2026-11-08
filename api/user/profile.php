<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/profile.php
//  GET  → return current user's profile data
//  POST → update profile fields (name, phone, org, gender, age,
//         address) OR change password OR update email
//  All write ops are CSRF-protected.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Cities.php';

header('Content-Type: application/json');

// ── Helper ─────────────────────────────────────────────────────
function json_out(bool $ok, array $data = [], string $error = ''): never
{
    if ($ok) {
        echo json_encode(['success' => true] + $data);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $error]);
    }
    exit;
}

// ── GET — return profile ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT public_id, name, email, phone, organisation, home_city, home_state, gender, age,
                address, role, profile_picture, email_verified,
                last_login, created_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$CURRENT_USER_ID]);
    $user = $stmt->fetch();

    if (!$user) {
        json_out(false, error: 'User not found.');
    }

    // Mask email partially for display
    json_out(true, ['user' => $user]);
}

// ── POST — update profile ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, error: 'Method not allowed.');
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? '');

// CSRF check
$csrf = $body['csrf_token'] ?? '';
if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    json_out(false, error: 'Invalid CSRF token.');
}

// ── Action: update_profile ─────────────────────────────────────
if ($action === 'update_profile') {
    $name   = trim($body['name']         ?? '');
    $phone  = trim($body['phone']        ?? '');
    $org    = cityNameClean((string)($body['organisation'] ?? ''));
    $cityIn = (string)($body['home_city']  ?? '');
    $stateIn = (string)($body['home_state'] ?? '');
    $gender = $body['gender']            ?? null;
    $age    = isset($body['age']) && $body['age'] !== '' ? (int)$body['age'] : null;
    $addr   = trim($body['address']      ?? '');

    if ($name === '') {
        json_out(false, error: 'Name is required.');
    }

    if ($phone !== '' && !preg_match('/^\d{10}$/', $phone)) {
        json_out(false, error: 'Phone number must be exactly 10 digits.');
    }

    $orgError = organisationError($org);
    if ($orgError !== null) {
        json_out(false, error: $orgError);
    }

    [$homeCity, $homeState, $locErrors] = validateHomeLocation($cityIn, $stateIn);
    if ($locErrors !== []) {
        json_out(false, error: reset($locErrors));
    }

    $allowedGenders = ['Male', 'Female', 'Other', null, ''];
    if (!in_array($gender, $allowedGenders, true)) {
        json_out(false, error: 'Invalid gender value.');
    }

    if ($age !== null && ($age < 10 || $age > 120)) {
        json_out(false, error: 'Age must be between 10 and 120.');
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE users
             SET name = ?, phone = ?, organisation = ?, home_city = ?, home_state = ?, gender = ?,
                 age = ?, address = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $phone ?: null,
            $org,
            $homeCity,
            $homeState,
            $gender ?: null,
            $age,
            $addr  ?: null,
            $CURRENT_USER_ID,
        ]);
        // Someone not yet in a city is placed in the one they named, if it exists.
        try {
            cityLinkUserToHomeCity($pdo, (int)$CURRENT_USER_ID);
        } catch (Throwable $e) {
            error_log('profile city link failed: ' . $e->getMessage());
        }
        $_SESSION['user_name'] = $name;
        ActivityLogger::log($pdo, ActivityLogger::PROFILE_UPDATED, (int)$CURRENT_USER_ID, ['field' => 'details']);
        json_out(true, ['message' => 'Profile updated successfully.']);
    } catch (Throwable $e) {
        error_log('profile update_profile error: ' . $e->getMessage());
        json_out(false, error: 'Server error updating profile.');
    }
}

// ── Action: update_email ───────────────────────────────────────
if ($action === 'update_email') {
    $newEmail = trim(filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $password = $body['password'] ?? '';

    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        json_out(false, error: 'Please enter a valid email address.');
    }

    if ($password === '') {
        json_out(false, error: 'Please confirm your current password.');
    }

    // Verify current password
    $stmt = $pdo->prepare('SELECT password, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$CURRENT_USER_ID]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string)$row['password'])) {
        json_out(false, error: 'Incorrect current password.');
    }

    if (strtolower($row['email']) === strtolower($newEmail)) {
        json_out(false, error: 'That is already your current email.');
    }

    // Check uniqueness
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $chk->execute([$newEmail, $CURRENT_USER_ID]);
    if ($chk->fetch()) {
        json_out(false, error: 'That email is already in use by another account.');
    }

    try {
        $pdo->prepare('UPDATE users SET email = ?, email_verified = 0 WHERE id = ?')
            ->execute([$newEmail, $CURRENT_USER_ID]);
        $_SESSION['user_email'] = $newEmail;
        ActivityLogger::log($pdo, ActivityLogger::PROFILE_UPDATED, (int)$CURRENT_USER_ID, ['field' => 'email', 'email' => $newEmail]);
        json_out(true, ['message' => 'Email updated. Please verify your new address.']);
    } catch (Throwable $e) {
        error_log('profile update_email error: ' . $e->getMessage());
        json_out(false, error: 'Server error updating email.');
    }
}

// ── Action: change_password ────────────────────────────────────
if ($action === 'change_password') {
    $current = $body['current_password'] ?? '';
    $new     = $body['new_password']     ?? '';
    $confirm = $body['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        json_out(false, error: 'All password fields are required.');
    }

    if (strlen($new) < 8) {
        json_out(false, error: 'New password must be at least 8 characters.');
    }

    if ($new !== $confirm) {
        json_out(false, error: 'New passwords do not match.');
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$CURRENT_USER_ID]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, (string)$row['password'])) {
        json_out(false, error: 'Current password is incorrect.');
    }

    if (password_verify($new, (string)$row['password'])) {
        json_out(false, error: 'New password must be different from your current password.');
    }

    try {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([$hash, $CURRENT_USER_ID]);
        ActivityLogger::log($pdo, ActivityLogger::PASSWORD_CHANGED, (int)$CURRENT_USER_ID, ['source' => 'profile']);
        json_out(true, ['message' => 'Password changed successfully.']);
    } catch (Throwable $e) {
        error_log('profile change_password error: ' . $e->getMessage());
        json_out(false, error: 'Server error changing password.');
    }
}

// ── Action: upload_avatar (base64 stored in DB) ───────────────
// NOTE: Railway filesystem is ephemeral — files are lost on redeploy.
// We store the image as a base64 data URL directly in the DB instead.
if ($action === 'upload_avatar') {
    $dataUrl = $body['image'] ?? '';
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!preg_match('/^data:([a-zA-Z\/]+);base64,/', $dataUrl, $m)) {
        json_out(false, error: 'Invalid image format.');
    }

    $mime = $m[1];
    if (!in_array($mime, $allowed, true)) {
        json_out(false, error: 'Only JPEG, PNG, WEBP, and GIF images are allowed.');
    }

    $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bytes  = base64_decode($base64, true);

    if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
        json_out(false, error: 'Image too large. Max size is 2 MB.');
    }

    // Store as data URL in DB — survives redeployments unlike filesystem storage
    try {
        $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?')
            ->execute([$dataUrl, $CURRENT_USER_ID]);

        $_SESSION['profile_picture'] = $dataUrl;
        ActivityLogger::log($pdo, ActivityLogger::PROFILE_UPDATED, (int)$CURRENT_USER_ID, ['field' => 'picture']);
        json_out(true, ['picture_url' => $dataUrl, 'message' => 'Profile picture updated.']);
    } catch (Throwable $e) {
        error_log('profile upload_avatar error: ' . $e->getMessage());
        json_out(false, error: 'Server error saving avatar.');
    }
}

json_out(false, error: 'Unknown action.');
