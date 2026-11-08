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
$filterOptions = activityFilterOptions($pdo);
$actionChoices = [];   // for the Action box: [code, label, group]
foreach (activityActionMap() as $code => [$label, $cat]) {
    $actionChoices[] = [$code, $label, $cat];
}
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

  .act-filters { display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px; }
  .act-row { display: flex; align-items: center; gap: 10px 12px; flex-wrap: wrap; }
  .act-label { font-size: 0.72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; opacity: 0.55; margin-right: 2px; }
  .act-search {
    flex: 1 1 260px; max-width: 360px; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(127,127,127,0.25); background: transparent; font-size: 0.85rem;
    font-family: inherit; color: var(--ink);
  }
  .act-sel, .act-date {
    padding: 9px 12px; border-radius: 8px; border: 1px solid rgba(127,127,127,0.25);
    background: transparent; font-size: 0.85rem; font-family: inherit; color: var(--ink); max-width: 220px;
  }
  .act-sel option, .act-sel optgroup { color: #1f2937; background: #fff; }
  .act-chip {
    padding: 6px 13px; border-radius: 999px; border: 1px solid rgba(127,127,127,0.3); background: transparent;
    font-family: inherit; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--ink); white-space: nowrap;
  }
  .act-chip:hover { background: rgba(127,127,127,0.1); }
  .act-chip.on { background: var(--g, #2f6b3a); border-color: var(--g, #2f6b3a); color: #fff; }
  .act-chip.warn.on { background: #b91c1c; border-color: #b91c1c; }
  .act-clear { padding: 6px 12px; border-radius: 8px; border: 0; background: none; font-family: inherit; font-size: 0.8rem; font-weight: 700; color: #b91c1c; cursor: pointer; }
  .act-clear[hidden] { display: none; }
  .act-filtercount { margin-left: auto; font-size: 0.8rem; opacity: 0.7; white-space: nowrap; }
  .act-note { font-size: 0.78rem; opacity: 0.65; margin: -4px 0 10px; }
  .act-pick { cursor: pointer; }
  .act-pick:hover { text-decoration: underline; }
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
      <div class="act-filters">
        <div class="act-row">
          <span class="act-label">When</span>
          <button type="button" class="act-chip" data-range="all">All time</button>
          <button type="button" class="act-chip" data-range="today">Today</button>
          <button type="button" class="act-chip" data-range="yesterday">Yesterday</button>
          <button type="button" class="act-chip" data-range="7">Last 7 days</button>
          <button type="button" class="act-chip" data-range="30">Last 30 days</button>
          <input type="date" id="fFrom" class="act-date" aria-label="From date">
          <span class="act-muted">to</span>
          <input type="date" id="fTo" class="act-date" aria-label="To date">
        </div>
        <div class="act-row">
          <span class="act-label">Quick views</span>
          <button type="button" class="act-chip" data-quick="failed">Failed sign-ins</button>
          <button type="button" class="act-chip warn" data-quick="bad">Deletions and failures</button>
          <button type="button" class="act-chip" data-quick="passwords">Password changes</button>
          <button type="button" class="act-chip" data-quick="downloads">Downloads</button>
        </div>
        <div class="act-row">
          <input type="text" id="actSearch" class="act-search" placeholder="Search names, projects, roads, details, IP" aria-label="Search">
          <select id="fUser" class="act-sel" aria-label="Person">
            <option value="">Anyone</option>
            <option value="none">Not signed in</option>
<?php foreach ($filterOptions['people'] as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name'] . ($p['role'] !== '' ? ' (' . $p['role'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
          </select>
          <select id="fRole" class="act-sel" aria-label="Role">
            <option value="">Any role</option>
            <option value="admin">Admin</option>
            <option value="city_leader">City Leader</option>
            <option value="surveyor">Surveyor</option>
          </select>
          <select id="fCategory" class="act-sel" aria-label="Group">
            <option value="">Everything</option>
<?php foreach (activityCategories() as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
          </select>
          <select id="fAction" class="act-sel" aria-label="Action"></select>
          <select id="fAudit" class="act-sel" aria-label="Project">
            <option value="">Any project</option>
<?php foreach ($filterOptions['projects'] as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>"><?= htmlspecialchars($pr['name'], ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
          </select>
          <select id="fOrder" class="act-sel" aria-label="Order">
            <option value="desc">Newest first</option>
            <option value="asc">Oldest first</option>
          </select>
          <button type="button" class="act-clear" id="fClear" hidden>Clear filters</button>
        </div>
        <div class="act-row"><span id="filterCount" class="act-filtercount" style="margin-left:0"></span></div>
      </div>
      <div id="cappedNote" class="act-note" style="display:none;">Search looked through the latest 2,000 matching records. Narrow the dates to search further back.</div>

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
        <div id="noResults" class="act-empty" style="display:none;">No activity matches these filters.</div>
        <div class="act-more" id="moreWrap" style="display:none;"><button type="button" class="act-morebtn" id="moreBtn">Load more</button></div>
      </div>
    </div>
  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
  'use strict';

  var PAGE = 200;
  var ACTIONS = <?= json_encode($actionChoices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var KEYS = ['q', 'user', 'role', 'category', 'action', 'audit', 'from', 'to', 'tone', 'order'];
  var f = { q: '', user: '', role: '', category: '', action: '', audit: '', from: '', to: '', tone: '', order: 'desc' };
  var entries = [];
  var hasMore = false;
  var total = 0;
  var token = 0;       // a newer request makes older answers stale
  var timer = null;

  var $ = function (id) { return document.getElementById(id); };

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  // ── days in India time ──────────────────────────────────────
  function istDay(offsetDays) {
    var d = new Date(Date.now() - offsetDays * 86400000);
    return d.toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });   // YYYY-MM-DD
  }
  var RANGES = {
    all:       function () { return ['', '']; },
    today:     function () { return [istDay(0), istDay(0)]; },
    yesterday: function () { return [istDay(1), istDay(1)]; },
    '7':       function () { return [istDay(6), istDay(0)]; },
    '30':      function () { return [istDay(29), istDay(0)]; }
  };
  var QUICK = {
    failed:    { action: 'user_login_failed' },
    bad:       { tone: 'bad' },
    passwords: { category: 'passwords' },
    downloads: { category: 'exports' }
  };

  // ── the Action box follows the Group box ────────────────────
  function fillActions() {
    var sel = $('fAction');
    var cat = f.category;
    sel.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = 'Any action';
    sel.appendChild(first);
    ACTIONS.forEach(function (a) {
      if (cat !== '' && a[2] !== cat) return;
      var o = document.createElement('option');
      o.value = a[0];
      o.textContent = a[1];
      sel.appendChild(o);
    });
    if (f.action && !ACTIONS.some(function (a) { return a[0] === f.action && (cat === '' || a[2] === cat); })) f.action = '';
    sel.value = f.action;
  }

  // ── form <-> filters <-> address bar ────────────────────────
  function showFilters() {
    $('actSearch').value = f.q;
    $('fUser').value = f.user;
    $('fRole').value = f.role;
    $('fCategory').value = f.category;
    fillActions();
    $('fAudit').value = f.audit;
    $('fFrom').value = f.from;
    $('fTo').value = f.to;
    $('fOrder').value = f.order;
    Array.prototype.forEach.call(document.querySelectorAll('[data-range]'), function (b) {
      var r = RANGES[b.getAttribute('data-range')]();
      b.classList.toggle('on', r[0] === f.from && r[1] === f.to);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-quick]'), function (b) {
      var q = QUICK[b.getAttribute('data-quick')];
      var on = Object.keys(q).every(function (k) { return f[k] === q[k]; });
      b.classList.toggle('on', on);
    });
    $('fClear').hidden = !active();
  }

  function active() {
    return KEYS.some(function (k) { return k !== 'order' && f[k] !== ''; }) || f.order !== 'desc';
  }

  function query() {
    var p = new URLSearchParams();
    KEYS.forEach(function (k) { if (f[k] !== '' && !(k === 'order' && f[k] === 'desc')) p.set(k, f[k]); });
    return p;
  }

  function readUrl() {
    var p = new URLSearchParams(window.location.search);
    KEYS.forEach(function (k) { if (p.has(k)) f[k] = p.get(k) || ''; });
    if (f.order !== 'asc') f.order = 'desc';
  }

  function saveUrl() {
    var qs = query().toString();
    try { history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* ignore */ }
  }

  // ── table ───────────────────────────────────────────────────
  function buildRow(e) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="act-when">' + escapeHtml(e.when) + '</td>' +
      '<td class="act-actor">' +
        (e.actor_id ? '<span class="act-pick" data-user="' + escapeHtml(e.actor_id) + '" title="Show only this person">' + escapeHtml(e.actor_name) + '</span>' : escapeHtml(e.actor_name)) +
        (e.actor_role ? '<span class="act-role">' + escapeHtml(e.actor_role) + '</span>' : '') + '</td>' +
      '<td><span class="action-pill act-pick ' + escapeHtml(e.tone) + '" data-action="' + escapeHtml(e.action) + '" title="Show only this action">' + escapeHtml(e.label) + '</span></td>' +
      '<td class="act-details">' + escapeHtml(e.details) + '</td>' +
      '<td class="act-ip">' + escapeHtml(e.ip) + '</td>';
    return tr;
  }

  function render() {
    var tbody = $('actTbody');
    tbody.innerHTML = '';
    $('noResults').style.display = entries.length === 0 ? 'block' : 'none';
    entries.forEach(function (e) { tbody.appendChild(buildRow(e)); });
    $('filterCount').textContent = entries.length === 0
      ? '0 matching records'
      : 'Showing ' + entries.length + ' of ' + total + ' matching record' + (total === 1 ? '' : 's');
    $('moreWrap').style.display = hasMore ? 'block' : 'none';
  }

  function showError(msg) {
    $('loadingMsg').style.display = 'none';
    var el = $('errorMsg');
    el.textContent = msg;
    el.style.display = 'block';
  }

  // append = true loads the next page of the same filters
  function load(append) {
    var mine = ++token;
    var btn = $('moreBtn');
    btn.disabled = true;
    if (!append) {
      entries = [];
      $('loadingMsg').style.display = 'block';
      $('loadingMsg').textContent = 'Loading activity…';
      $('tableWrap').style.opacity = '.5';
    }
    var p = query();
    p.set('limit', PAGE);
    p.set('offset', append ? entries.length : 0);
    fetch('../api/admin/activity_log.php?' + p.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (mine !== token) return;
        $('loadingMsg').style.display = 'none';
        $('errorMsg').style.display = 'none';
        $('tableWrap').style.opacity = '1';
        btn.disabled = false;
        if (!data.success) { showError(data.error || 'Could not load activity log.'); return; }
        entries = append ? entries.concat(data.entries) : data.entries;
        hasMore = !!data.has_more;
        total = data.total || 0;
        $('cappedNote').style.display = data.capped ? 'block' : 'none';
        $('tableWrap').style.display = 'block';
        render();
      })
      .catch(function () {
        if (mine !== token) return;
        btn.disabled = false;
        $('tableWrap').style.opacity = '1';
        showError('Network error — could not load activity log.');
      });
  }

  function changed() {
    showFilters();
    saveUrl();
    load(false);
  }

  // ── wiring ──────────────────────────────────────────────────
  $('actSearch').addEventListener('input', function () {
    f.q = this.value.trim();
    $('fClear').hidden = !active();
    clearTimeout(timer);
    timer = setTimeout(function () { saveUrl(); load(false); }, 300);
  });
  [['fUser', 'user'], ['fRole', 'role'], ['fAudit', 'audit'], ['fOrder', 'order']].forEach(function (pair) {
    $(pair[0]).addEventListener('change', function () { f[pair[1]] = this.value; changed(); });
  });
  $('fAction').addEventListener('change', function () { f.action = this.value; f.tone = ''; changed(); });
  $('fCategory').addEventListener('change', function () { f.category = this.value; f.action = ''; f.tone = ''; changed(); });
  $('fFrom').addEventListener('change', function () { f.from = this.value; if (f.to && f.from > f.to) f.to = f.from; changed(); });
  $('fTo').addEventListener('change', function () { f.to = this.value; if (f.from && f.to < f.from) f.from = f.to; changed(); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-range]'), function (b) {
    b.addEventListener('click', function () {
      var r = RANGES[b.getAttribute('data-range')]();
      f.from = r[0]; f.to = r[1];
      changed();
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-quick]'), function (b) {
    b.addEventListener('click', function () {
      var q = QUICK[b.getAttribute('data-quick')];
      var on = Object.keys(q).every(function (k) { return f[k] === q[k]; });
      f.category = ''; f.action = ''; f.tone = '';
      if (!on) Object.keys(q).forEach(function (k) { f[k] = q[k]; });
      changed();
    });
  });
  $('fClear').addEventListener('click', function () {
    KEYS.forEach(function (k) { f[k] = ''; });
    f.order = 'desc';
    changed();
  });
  $('actTbody').addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-user],[data-action]');
    if (!t) return;
    if (t.hasAttribute('data-user')) { f.user = t.getAttribute('data-user'); }
    else { f.action = t.getAttribute('data-action'); f.category = ''; f.tone = ''; }
    changed();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $('moreBtn').addEventListener('click', function () { load(true); });

  readUrl();
  showFilters();
  load(false);
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
