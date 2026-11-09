<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/dashboard.php
//  All data comes from api/dashboard/stats.php via a single fetch.
//  No inline scoring — ScoreService handles that via the API.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/RoleHome.php';

// Admins and City Leaders have their own dashboards; this page is the surveyor's.
$roleHome = roleHomePage($CURRENT_USER_ROLE);
if ($roleHome !== 'dashboard.php') {
    header('Location: ' . $roleHome);
    exit;
}

$hour     = (int)(new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('H');
$greet    = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="stylesheet" href="../css/theme.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
</head>
<body>

<!-- SIDEBAR -->
<aside>
  <div class="sb-brand">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:22px;width:auto;filter:brightness(0) invert(1);opacity:.85;flex-shrink:0;">
    <span style="width:1px;height:18px;background:rgba(255,255,255,.15);display:inline-block;flex-shrink:0;"></span>
    CycleAudit
  </div>

  <nav>
    <div class="nav-section">Main</div>
    <a class="nav-item active" href="dashboard.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <?php if (isAnyAdmin($CURRENT_USER_ROLE)): ?>
    <div class="nav-section">Admin</div>
    <a class="nav-item" href="admin_surveyors.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Users
    </a>
    <a class="nav-item" href="admin_activity.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Activity Log
    </a>
    <?php endif; ?>
  </nav>

  <div class="sb-user">
    <!-- Popup menu -->
    <div class="sb-popup" id="sbPopup">
      <div class="popup-header">
        <div class="popup-avatar" id="popupAv">
          <?php if ($CURRENT_USER_PIC): ?>
            <img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="">
          <?php else: ?>
            <?= $initials ?>
          <?php endif; ?>
        </div>
        <div style="min-width:0">
          <div class="popup-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
          <div class="popup-urole"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></div>
        </div>
      </div>
      <div class="popup-menu">
        <a class="popup-item" href="profile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          My Profile
        </a>
        <a class="popup-item" href="profile.php#tab-account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Change Password
        </a>
        <div class="popup-divider"></div>
        <a class="popup-item danger" href="../auth/logout.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign Out
        </a>
      </div>
    </div>

    <!-- Trigger button -->
    <button class="sb-user-btn" id="sbUserBtn" data-dash-action="toggle-user-menu">
      <div class="sb-avatar" id="sb-av">
        <?php if ($CURRENT_USER_PIC): ?>
          <img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="">
        <?php else: ?>
          <?= $initials ?>
        <?php endif; ?>
      </div>
      <div class="sb-uinfo">
        <div class="sb-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
        <div class="sb-urole"><?= htmlspecialchars(roleLabel($CURRENT_USER_ROLE)) ?></div>
      </div>
      <svg class="sb-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="18 15 12 9 6 15"/>
      </svg>
    </button>
  </div>
</aside>

<!-- MAIN -->
<main>
  <div class="topbar">
		<button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
		<div class="topbar-left">
      <h1><?= $greet ?>, <?= htmlspecialchars(explode(' ', $CURRENT_USER_NAME)[0]) ?>!</h1>
      <p>Here's your audit overview for today.</p>
    </div>
    <?php /* Surveyors do not create roads: the City Leader assigns their work. */ ?>
  </div>

  <div class="content">

    <?php if (($_GET['notice'] ?? '') === 'project_deleted'): ?>
    <div class="card" role="alert" style="border-left:4px solid #dc2626;background:#fef2f2">
      <p style="margin:0;font-size:.92rem;line-height:1.5"><b>That project was deleted.</b> The Admin deleted it, so work on it has stopped and it is no longer listed.</p>
    </div>
    <?php endif; ?>

    <?php if (!isAnyAdmin($CURRENT_USER_ROLE)): ?>
    <!-- Stat cards — populated by JS -->
    <div class="stat-grid" id="statGrid">
      <div class="stat-card"><div class="stat-icon" style="background:#edf7d6">🛣️</div><div><div class="stat-val" id="st-roads">—</div><div class="stat-lbl">Roads</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">📍</div><div><div class="stat-val" id="st-segs">—</div><div class="stat-lbl">Total Segments</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">✅</div><div><div class="stat-val" id="st-done">—</div><div class="stat-lbl">Completed</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef9c3">⚡</div><div><div class="stat-val" id="st-active">—</div><div class="stat-lbl">Active Sessions</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#ffe4d6">🔥</div><div><div class="stat-val" id="st-streak">—</div><div class="stat-lbl">Day Streak</div></div></div>
    </div>
    <?php endif; ?>

    <?php if (!isAnyAdmin($CURRENT_USER_ROLE)) { require __DIR__ . '/partials/notifications.php'; require __DIR__ . '/partials/surveyor_work.php'; } ?>

    <?php if (!isAnyAdmin($CURRENT_USER_ROLE)): ?>
    <!-- Roads table -->
    <div class="card">
      <div class="card-head">
        <h3>🛣️ Your Roads</h3>
      </div>
      <div id="roadsContainer">
        <!-- Skeleton loading -->
        <div style="display:flex;flex-direction:column;gap:14px;padding:8px 0">
          <div class="skeleton" style="height:18px;width:60%"></div>
          <div class="skeleton" style="height:18px;width:80%"></div>
          <div class="skeleton" style="height:18px;width:50%"></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div style="font-size:2rem;margin-bottom:10px">🗑️</div>
    <h4>Delete Road?</h4>
    <p id="deleteModalMsg">This will permanently delete the road and all its segments, sessions and audit data.</p>
    <div class="modal-btns">
      <button class="mbtn-cancel" data-dash-action="close-delete-modal">Cancel</button>
      <button class="mbtn-delete" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-wrap" id="toastWrap"></div>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">const CSRF = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';</script>
<script src="../js/notifications.js?v=<?= filemtime(__DIR__ . '/../js/notifications.js') ?>"></script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script src="../js/dashboard-handlers.js?v=<?= filemtime(__DIR__ . '/../js/dashboard-handlers.js') ?>"></script>
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
<!-- cache-bust 2026-07-01T21:34:39 -->
