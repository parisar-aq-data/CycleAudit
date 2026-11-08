<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/admin_activity.php
//  Activity Log — admin-only. Read-only record of who did what: sign-ins, passwords,
//  users, projects, roads, segments, downloads and home page edits. The list comes
//  from api/admin/activity_log.php (activity_log, plus the older road catalogue trail).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/ActivityLogView.php';

$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="stylesheet" href="../css/theme.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Log — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css">
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  .act-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  .act-table th {
    text-align: left; font-size: 0.72rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; opacity: 0.55; padding: 0 12px 10px; white-space: nowrap;
  }
  .act-table td { padding: 12px; border-top: 1px solid rgba(127,127,127,0.12); vertical-align: middle; }
  .act-table tbody tr:hover { background: rgba(127,127,127,0.05); }
  .act-actor { font-weight: 600; color: var(--ink); }
  .act-road { color: var(--ink); }
  .act-muted { opacity: 0.55; }
  .act-when { white-space: nowrap; color: var(--gray); font-size: 0.82rem; }

  .action-pill {
    display: inline-flex; align-items: center; font-size: 0.72rem; font-weight: 700;
    padding: 4px 11px; border-radius: 999px; white-space: nowrap; text-transform: capitalize;
  }
  .action-pill.good    { background: var(--tsuccess-bg); color: var(--tsuccess-txt); }
  .action-pill.bad     { background: var(--tdanger-bg); color: var(--tdanger-txt); }
  .action-pill.neutral { background: var(--tseg-bg); color: var(--gray); }
  .act-role { display: block; font-size: 0.72rem; font-weight: 500; opacity: 0.55; }
  .act-details { color: var(--ink); line-height: 1.45; min-width: 260px; }
  .act-ip { font-size: 0.78rem; opacity: 0.55; white-space: nowrap; }
  .act-more { text-align: center; padding: 16px 0 4px; }
  .act-morebtn { padding: 9px 18px; border-radius: 8px; border: 1px solid rgba(127,127,127,0.35); background: transparent; font-family: inherit; font-size: 0.85rem; font-weight: 600; cursor: pointer; color: var(--ink); }
  .act-morebtn:disabled { opacity: 0.5; cursor: default; }

  .act-filterbar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
  .act-search {
    width: 100%; max-width: 320px; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(127,127,127,0.25); background: transparent; font-size: 0.85rem;
    font-family: inherit;
  }
  .act-action-filter {
    padding: 9px 12px; border-radius: 8px; border: 1px solid rgba(127,127,127,0.25);
    background: transparent; font-size: 0.85rem; font-family: inherit;
  }
  .act-filtercount { margin-left: auto; font-size: 0.8rem; opacity: 0.6; white-space: nowrap; }
  .act-empty { text-align: center; padding: 30px; opacity: 0.6; }
</style>
</head>
<body>

<?php $activeNav = 'activity'; require __DIR__ . '/partials/role_sidebar.php'; ?>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
function toggleUserMenu() {
  const popup = document.getElementById('sbPopup');
  const btn   = document.getElementById('sbUserBtn');
  const open  = popup.classList.toggle('show');
  btn.classList.toggle('open', open);
}
document.addEventListener('click', e => {
  const popup = document.getElementById('sbPopup');
  if (!popup.classList.contains('show')) return;
  if (!document.getElementById('sbUserBtn').contains(e.target) &&
      !popup.contains(e.target)) {
    popup.classList.remove('show');
    document.getElementById('sbUserBtn').classList.remove('open');
  }
});
</script>

<div class="sb-overlay" id="sb-overlay"></div>

<main>
  <div class="topbar">
    <button id="sb-toggle" aria-label="Menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
    </button>
    <div class="topbar-left">
      <h2>Activity Log</h2>
      <p style="margin-top:2px;">Who did what, newest first: sign-ins, passwords, users, projects, roads, segments, downloads and home page edits.</p>
    </div>
  </div>

  <div style="padding: 0 4px 4px;">
    <div class="card">
      <div class="act-filterbar">
        <input type="text" id="actSearch" class="act-search" placeholder="Search by person or what was done">
        <select id="actionFilter" class="act-action-filter">
          <option value="all">Everything</option>
<?php foreach (activityCategories() as $key => $label): ?>
          <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
        </select>
        <span id="filterCount" class="act-filtercount"></span>
      </div>

      <div id="loadingMsg" style="text-align:center;padding:30px;opacity:.6;">Loading activity…</div>
      <div id="errorMsg" style="display:none;text-align:center;padding:30px;color:var(--tdanger-txt);"></div>
      <div id="tableWrap" style="display:none;overflow-x:auto;">
        <table class="act-table">
          <thead>
            <tr>
              <th>When</th>
              <th>Who</th>
              <th>Action</th>
              <th>Details</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody id="actTbody"></tbody>
        </table>
        <div id="noResults" class="act-empty" style="display:none;">No activity matches your search.</div>
        <div class="act-more" id="moreWrap" style="display:none;"><button type="button" class="act-morebtn" id="moreBtn">Load older activity</button></div>
      </div>
    </div>
  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
  'use strict';

  var PAGE = 200;
  var allEntries = [];
  var hasMore = false;
  var total = 0;

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  function buildRow(e) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="act-when">' + escapeHtml(e.when) + '</td>' +
      '<td class="act-actor">' + escapeHtml(e.actor_name) +
        (e.actor_role ? '<span class="act-role">' + escapeHtml(e.actor_role) + '</span>' : '') + '</td>' +
      '<td><span class="action-pill ' + escapeHtml(e.tone) + '">' + escapeHtml(e.label) + '</span></td>' +
      '<td class="act-details">' + escapeHtml(e.details) + '</td>' +
      '<td class="act-ip">' + escapeHtml(e.ip) + '</td>';
    return tr;
  }

  function render(list) {
    var tbody = document.getElementById('actTbody');
    tbody.innerHTML = '';
    document.getElementById('noResults').style.display = list.length === 0 ? 'block' : 'none';
    list.forEach(function (e) { tbody.appendChild(buildRow(e)); });
  }

  function applyFilter() {
    var q = document.getElementById('actSearch').value.trim().toLowerCase();
    var category = document.getElementById('actionFilter').value;
    var filtered = allEntries.filter(function (e) {
      if (category !== 'all' && e.category !== category) return false;
      if (q === '') return true;
      return (e.actor_name + ' ' + e.label + ' ' + e.details + ' ' + e.ip).toLowerCase().indexOf(q) !== -1;
    });
    render(filtered);
    document.getElementById('filterCount').textContent =
      'Showing ' + filtered.length + ' of ' + allEntries.length + ' loaded (' + total + ' in the log)';
    document.getElementById('moreWrap').style.display = hasMore ? 'block' : 'none';
  }

  function showError(msg) {
    document.getElementById('loadingMsg').style.display = 'none';
    var el = document.getElementById('errorMsg');
    el.textContent = msg;
    el.style.display = 'block';
  }

  function load() {
    var btn = document.getElementById('moreBtn');
    btn.disabled = true;
    fetch('../api/admin/activity_log.php?limit=' + PAGE + '&offset=' + allEntries.length,
          { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        document.getElementById('loadingMsg').style.display = 'none';
        btn.disabled = false;
        if (!data.success) { showError(data.error || 'Could not load activity log.'); return; }
        allEntries = allEntries.concat(data.entries);
        hasMore = !!data.has_more;
        total = data.total || allEntries.length;
        document.getElementById('tableWrap').style.display = 'block';
        applyFilter();
      })
      .catch(function () {
        btn.disabled = false;
        showError('Network error — could not load activity log.');
      });
  }

  document.getElementById('actSearch').addEventListener('input', applyFilter);
  document.getElementById('actionFilter').addEventListener('change', applyFilter);
  document.getElementById('moreBtn').addEventListener('click', load);
  load();
})();
</script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
var tog2 = document.getElementById("sb-toggle");
var ovl2 = document.getElementById("sb-overlay");
var aside2 = document.querySelector("aside");
function openSb2(){ aside2.classList.add("open"); ovl2.classList.add("show"); }
function closeSb2(){ aside2.classList.remove("open"); ovl2.classList.remove("show"); }
tog2.addEventListener("click", openSb2);
ovl2.addEventListener("click", closeSb2);
</script>
</body>
</html>
