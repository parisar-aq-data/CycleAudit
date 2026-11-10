<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/profile.php — User Profile & Settings
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../config/constants.php';

$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/profile.css">
</head>
<body>

<!-- ═══════════ SIDEBAR ═══════════ -->
<aside>
  <div class="sb-brand">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:22px;width:auto;filter:brightness(0) invert(1);opacity:.85;flex-shrink:0;">
    <span style="width:1px;height:18px;background:rgba(255,255,255,.15);display:inline-block;flex-shrink:0;"></span>
    CycleAudit
  </div>

  <nav>
    <div class="nav-section">Main</div>
    <?php if ($CURRENT_USER_ROLE === 'city_leader'): ?>
    <a href="<?= htmlspecialchars(roleHomePage($CURRENT_USER_ROLE)) ?>" class="nav-item">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      City Dashboard
    </a>
    <?php else: ?>
    <a href="dashboard.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      Dashboard
    </a>
    <?php endif; ?>
    <div class="nav-section">Account</div>
    <a href="profile.php" class="nav-item active">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
      My Profile
    </a>
  </nav>

  <div class="sb-user">
    <div class="sb-avatar" id="sb-av">
      <?php if ($CURRENT_USER_PIC): ?>
        <img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="">
      <?php else: ?>
        <?= htmlspecialchars($initials) ?>
      <?php endif; ?>
    </div>
    <div class="sb-uinfo">
      <div class="sb-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
      <div class="sb-urole"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></div>
    </div>
    <a href="../auth/logout.php" title="Logout">
      <svg viewBox="0 0 24 24" fill="rgba(255,255,255,.5)" width="16" height="16"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zm-5 12H5V5h7V3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h7v-2z"/></svg>
    </a>
  </div>
</aside>

<!-- ═══════════ MAIN ═══════════ -->
<main>
  <div class="topbar">
    <h1>My Profile</h1>
  </div>

  <div class="content">

    <!-- Profile Hero -->
    <div class="profile-hero">
      <div class="avatar-wrap">
        <div class="avatar-lg" id="avatar-display" data-profile-action="pick-avatar">
          <?php if ($CURRENT_USER_PIC): ?>
            <img id="avatar-img" src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="Profile Picture">
          <?php else: ?>
            <span id="avatar-initials"><?= htmlspecialchars($initials) ?></span>
          <?php endif; ?>
        </div>
        <div class="avatar-edit-btn" data-profile-action="pick-avatar" title="Change photo">
          <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
        </div>
        <input type="file" id="avatar-file" accept="image/*" data-profile-action="upload-avatar">
      </div>

      <div class="hero-info">
        <h2 id="hero-name"><?= htmlspecialchars($CURRENT_USER_NAME) ?></h2>
        <div class="hero-email" id="hero-email">Loading…</div>
        <div class="hero-badges">
          <span class="badge badge-role" id="hero-role"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></span>
          <span class="badge" id="hero-verified">—</span>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" data-tab="personal">Personal Info</button>
      <button class="tab-btn" data-tab="account">Email & Password</button>
    </div>

    <!-- ── Tab: Personal Info ── -->
    <div class="tab-panel active" id="tab-personal">
      <div id="alert-personal" class="alert"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span id="alert-personal-msg"></span></div>

      <div class="card">
        <div class="card-title">
          <svg viewBox="0 0 24 24" fill="var(--g)"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
          Basic Information
        </div>

        <div class="form-grid">
          <div class="field">
            <label>Full Name *</label>
            <input type="text" id="f-name" placeholder="Your full name" maxlength="150" required>
          </div>
          <div class="field">
            <label>Phone Number</label>
            <input type="tel" inputmode="numeric" id="f-phone" placeholder="e.g. 9876543210" maxlength="10" data-profile-action="sanitize-phone">
          </div>
          <div class="field">
            <label>Organisation *</label>
            <input type="text" id="f-org" placeholder="e.g. Parisar, KSE" maxlength="200" required>
          </div>
          <div class="field">
            <label>City *</label>
            <input type="text" id="f-city" list="cityList" placeholder="Search and pick your city" maxlength="100" autocomplete="off" required>
          </div>
          <div class="field">
            <label>State *</label>
            <input type="text" id="f-state" readonly tabindex="-1" placeholder="Fills in from your city" maxlength="100" autocomplete="off">
            <?= locationDatalistsHtml() ?>
          </div>
          <div class="field">
            <label>Gender</label>
            <select id="f-gender">
              <option value="">Prefer not to say</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="field">
            <label>Age</label>
            <input type="number" id="f-age" placeholder="e.g. 25" min="10" max="120">
          </div>
          <div class="field">
            <label>Public ID</label>
            <input type="text" id="f-pid" readonly>
            <span class="field-hint">Assigned automatically — cannot be changed.</span>
          </div>
        </div>

        <div class="form-grid one-col" style="margin-top:16px">
          <div class="field">
            <label>Address</label>
            <textarea id="f-address" placeholder="Street, city, state, PIN…" rows="3"></textarea>
          </div>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" id="btn-save-profile" data-profile-action="save-profile">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
            Save Changes
          </button>
          <button class="btn btn-outline" data-profile-action="reset-profile">Reset</button>
        </div>
      </div>
    </div>

    <!-- ── Tab: Email & Password ── -->
    <div class="tab-panel" id="tab-account">
      <div id="alert-account" class="alert"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span id="alert-account-msg"></span></div>

      <!-- Update Email -->
      <div class="card" id="card-email">
        <div class="card-title">
          <svg viewBox="0 0 24 24" fill="var(--g)"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
          Update Email Address
        </div>
        <div class="form-grid one-col">
          <div class="field">
            <label>New Email Address</label>
            <input type="email" id="f-new-email" placeholder="new@example.com" maxlength="255">
          </div>
          <div class="field">
            <label>Confirm Current Password</label>
            <input type="password" id="f-email-pw" placeholder="Your current password">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" data-profile-action="update-email">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
            Update Email
          </button>
        </div>
      </div>

      <!-- Change Password -->
      <div class="card" id="card-password">
        <div class="card-title">
          <svg viewBox="0 0 24 24" fill="var(--g)"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 17c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
          Change Password
        </div>
        <div class="form-grid one-col">
          <div class="field">
            <label>Current Password</label>
            <input type="password" id="f-cur-pw" placeholder="Your current password">
          </div>
          <div class="field">
            <label>New Password</label>
            <input type="password" id="f-new-pw" placeholder="At least 12 characters" data-profile-action="check-strength">
            <div class="pw-strength"><div class="pw-strength-bar" id="pw-bar"></div></div>
            <span class="field-hint" id="pw-hint">Enter a new password</span>
          </div>
          <div class="field">
            <label>Confirm New Password</label>
            <input type="password" id="f-confirm-pw" placeholder="Repeat new password">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" data-profile-action="change-password">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2z"/></svg>
            Change Password
          </button>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">const CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/city_search.js"></script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/location_fields.js"></script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/profile.js"></script>
<button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
<div class="sb-overlay" id="sb-overlay"></div>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
const tog = document.getElementById("sb-toggle");
const ovl = document.getElementById("sb-overlay");
const aside = document.querySelector("aside");
function openSb(){ aside.classList.add("open"); ovl.classList.add("show"); }
function closeSb(){ aside.classList.remove("open"); ovl.classList.remove("show"); }
tog.addEventListener("click", openSb);
ovl.addEventListener("click", closeSb);
</script>
</body>
</html>
