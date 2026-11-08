<?php
declare(strict_types=1);

// ============================================================
//  pages/admin_surveyors.php
//  Surveyor list panel — admin-only. Shows every surveyor account
//  with roads-created / segments-audited / last-active stats.
// ============================================================

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../config/constants.php';

$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="stylesheet" href="../css/theme.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css">
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  .surv-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  .surv-table th {
    text-align: left; font-size: 0.72rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; opacity: 0.55; padding: 0 12px 10px; white-space: nowrap;
  }
  .surv-table td { padding: 12px; border-top: 1px solid rgba(127,127,127,0.12); vertical-align: middle; }
  .surv-table tbody tr:hover { background: rgba(127,127,127,0.05); }
  .surv-name-cell { display: flex; align-items: center; gap: 10px; }
  .surv-avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--g, #16a34a); color: #fff; display: flex; align-items: center;
    justify-content: center; font-weight: 700; font-size: 0.8rem;
  }
  .surv-name { font-weight: 600; }
  .surv-email { opacity: 0.6; font-size: 0.78rem; }
  .surv-stat { font-weight: 700; }
  .surv-muted { opacity: 0.55; }
  .surv-search {
    width: 100%; max-width: 320px; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(127,127,127,0.25); background: transparent; font-size: 0.85rem;
    font-family: inherit;
  }
  .surv-role-filter {
    padding: 9px 12px; border-radius: 8px; border: 1px solid rgba(127,127,127,0.25);
    background: transparent; font-size: 0.85rem; font-family: inherit;
  }
  .surv-filterbar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
  .surv-filtercount { margin-left: auto; font-size: 0.8rem; opacity: 0.6; }
  .surv-empty { text-align: center; padding: 30px; opacity: 0.6; }
  .reset-pw-btn {
    padding: 5px 10px; margin-right: 6px; border-radius: 6px; cursor: pointer;
    border: 1px solid rgba(127,127,127,0.35); background: transparent;
    font-size: 0.75rem; font-family: inherit; color: inherit;
  }
  .reset-pw-btn:hover { background: rgba(127,127,127,0.12); }
  .reset-pw-btn:disabled { opacity: 0.5; cursor: wait; }
</style>
</head>
<body>

<?php $activeNav = 'users'; require __DIR__ . '/partials/role_sidebar.php'; ?>

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
    <h2>Users</h2>
  </div>

  <div style="padding: 0 4px 4px;">
    <div class="card">
      <div class="surv-filterbar">
        <input type="text" id="survSearch" class="surv-search" placeholder="Search by name or email…">
        <select id="roleFilter" class="surv-role-filter">
          <option value="all">All roles</option>
          <option value="admin">Admins only</option>
          <option value="city_leader">City Leaders only</option>
          <option value="surveyor">Surveyors only</option>
        </select>
        <select id="cityFilter" class="surv-role-filter" aria-label="Filter by city" style="display:none;">
          <option value="all">All cities</option>
        </select>
        <select id="statusFilter" class="surv-role-filter" aria-label="Filter by status">
          <option value="active" selected>Active users</option>
          <option value="inactive">Inactive users</option>
          <option value="all">All users</option>
        </select>
        <button class="action-btn" id="exportExcelBtn" type="button">Export Excel</button>
        <span id="filterCount" class="surv-filtercount"></span>
      </div>
      <div id="exportMsg" style="font-size:0.78rem;color:var(--gray);margin:-8px 0 12px;display:none;"></div>

      <div id="loadingMsg" style="text-align:center;padding:30px;opacity:.6;">Loading surveyors…</div>
      <div id="errorMsg" style="display:none;text-align:center;padding:30px;color:#dc2626;"></div>
      <div id="tableWrap" style="display:none;overflow-x:auto;">
        <table class="surv-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Organisation</th>
              <th>Role</th>
              <?php if ($CURRENT_USER_ROLE === 'admin'): ?><th>City</th><th>Says they are in</th><?php endif; ?>
              <th>Roads Created</th>
              <th>Segments Audited</th>
              <th>Last Active</th>
              <th>Joined</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="survTbody"></tbody>
        </table>
        <div id="noResults" class="surv-empty" style="display:none;">No surveyors match your search.</div>
      </div>
    </div>
  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">const CSRF = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';const IS_NATIONAL_ADMIN = <?= $CURRENT_USER_ROLE === 'admin' ? 'true' : 'false' ?>;</script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
  'use strict';

  var allSurveyors = [];
  var cities = [];

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  function fmtDate(iso) {
    if (!iso) return '<span class="surv-muted">Never</span>';
    try {
      var d = new Date(iso.replace(' ', 'T'));
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return escapeHtml(iso);
    }
  }

  function initials(name) {
    return (name || '?').trim().charAt(0).toUpperCase();
  }

  var ROLE_LABELS = {
    admin: 'Admin',
    city_leader: 'City Leader',
    surveyor: 'Surveyor'
  };

  function buildRow(s) {
    var tr = document.createElement('tr');
    var roleBadge = s.role === 'admin'
      ? '<span class="admin-badge">Admin</span>'
      : s.role === 'city_leader'
        ? '<span class="admin-badge" style="background:#eef2ff;color:#4338ca;">City Leader</span>'
        : '<span style="color:#6b7280;font-size:.78rem;">Surveyor</span>';

    // Role selector: shown to the Admin only.
    var roleOptions = ['surveyor', 'city_leader', 'admin'];
    var roleBtn = '';
    // Only the Admin changes roles (creates City Leaders); the
    // API enforces this too. City Leaders only manage surveyors.
    if (!s.is_current_user && IS_NATIONAL_ADMIN) {
      roleBtn = ' <select class="role-select" data-id="' + s.id + '" data-role="' + s.role + '">' +
        roleOptions.map(function (r) {
          return '<option value="' + r + '"' + (r === s.role ? ' selected' : '') + '>' + ROLE_LABELS[r] + '</option>';
        }).join('') +
        '</select>';
    }
    // City column - Admins only. With 2+ cities each row gets a
    // dropdown to move the user; Admins span every city.
    var cityCell = '';
    if (IS_NATIONAL_ADMIN) {
      var cityInner;
      if (s.role === 'admin') {
        cityInner = '<span class="surv-muted">All cities</span>';
      } else if (cities.length > 1) {
        cityInner = '<select class="role-select city-select" data-id="' + s.id + '" data-city="' + (s.city_id == null ? '' : s.city_id) + '" aria-label="City for ' + escapeHtml(s.name) + '">' +
          (s.city_id == null ? '<option value="" selected disabled>&mdash; Not set &mdash;</option>' : '') +
          cities.map(function (c) {
            return '<option value="' + c.id + '"' + (c.id === s.city_id ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>';
          }).join('') + '</select>';
      } else {
        cityInner = s.city_name ? escapeHtml(s.city_name) : '<span class="surv-muted">&mdash;</span>';
      }
      cityCell = '<td>' + cityInner + '</td>';
      var said = (s.home_city && s.home_state && s.home_city.indexOf('(' + s.home_state + ')') !== -1)
        ? s.home_city : [s.home_city, s.home_state].filter(Boolean).join(', ');
      cityCell += '<td>' + (said ? escapeHtml(said) : '<span class="surv-muted">&mdash;</span>') + '</td>';
    }
    // City Leaders may only (de)activate surveyors; the API enforces this.
    var toggleBtn = (IS_NATIONAL_ADMIN || s.role === 'surveyor')
      ? '<button class="toggle-status-btn" data-id="' + s.id + '" data-active="' + (s.removal_pending ? 'false' : s.is_active) + '" data-pending="' + (s.removal_pending ? '1' : '') + '">' + (s.removal_pending ? 'Cancel removal' : (s.is_active ? 'Deactivate' : 'Reactivate')) + '</button>'
      : '';
    // Reset password: Admin on anyone but themselves, City Leader on surveyors.
    // The API enforces this too, and refuses deactivated accounts (reactivate first).
    var resetBtn = (s.is_active && !s.is_current_user && (IS_NATIONAL_ADMIN || s.role === 'surveyor'))
      ? ' <button class="reset-pw-btn" type="button" data-id="' + s.id + '">Reset password</button>'
      : '';
    tr.innerHTML =
      '<td><div class="surv-name-cell">' +
        '<div class="surv-avatar">' + escapeHtml(initials(s.name)) + '</div>' +
        '<div><div class="surv-name">' + escapeHtml(s.name) + '</div>' +
        '<div class="surv-email">' + escapeHtml(s.email) + '</div></div>' +
      '</div></td>' +
      '<td>' + (s.organisation ? escapeHtml(s.organisation) : '<span class="surv-muted">—</span>') + '</td>' +
      '<td>' + roleBadge + '</td>' +
      cityCell +
      '<td class="surv-stat">' + s.roads_created + '</td>' +
      '<td class="surv-stat">' + s.segments_audited + '</td>' +
      '<td>' + fmtDate(s.last_audit_at || s.last_login) + '</td>' +
      '<td>' + fmtDate(s.created_at) + '</td>' +
      '<td>' + (s.removal_pending ? '<span style="color:#b45309;font-weight:600;" title="Finishing started segments, then deactivated or removed from the team">Leaving</span>' : (s.is_active ? '<span style="color:#16a34a;font-weight:600;">Active</span>' : '<span style="color:#9ca3af;font-weight:600;">Inactive</span>')) + '</td>' +
      '<td style="white-space:nowrap;">' + toggleBtn + resetBtn + roleBtn + '</td>';
    return tr;
  }

  function render(list) {
    var tbody = document.getElementById('survTbody');
    tbody.innerHTML = '';
    document.getElementById('noResults').style.display = list.length === 0 ? 'block' : 'none';
    list.forEach(function (s) { tbody.appendChild(buildRow(s)); });
  }

  var lastFiltered = [];

  function applyFilter() {
    var q = document.getElementById('survSearch').value.trim().toLowerCase();
    var statusFilter = document.getElementById('statusFilter').value;
    var roleFilter = document.getElementById('roleFilter').value;
    var cityFilter = document.getElementById('cityFilter').value;
    var filtered = allSurveyors.filter(function (s) {
      if (statusFilter === 'active' && !s.is_active) return false;
      if (statusFilter === 'inactive' && s.is_active) return false;
      if (roleFilter !== 'all' && s.role !== roleFilter) return false;
      if (cityFilter === 'none' && s.city_id != null) return false;
      if (cityFilter !== 'all' && cityFilter !== 'none' && String(s.city_id) !== cityFilter) return false;
      return (s.name || '').toLowerCase().indexOf(q) !== -1 ||
             (s.email || '').toLowerCase().indexOf(q) !== -1;
    });
    lastFiltered = filtered;
    render(filtered);
    document.getElementById('filterCount').textContent =
      'Showing ' + filtered.length + ' of ' + allSurveyors.length + ' users';
  }

  document.getElementById('survSearch').addEventListener('input', applyFilter);
  document.getElementById('statusFilter').addEventListener('change', applyFilter);
  document.getElementById('roleFilter').addEventListener('change', applyFilter);
  document.getElementById('cityFilter').addEventListener('change', applyFilter);

  // The city filter only appears for Admins once there is more
  // than one city to filter by.
  function setupCityFilter() {
    var f = document.getElementById('cityFilter');
    if (!f || !IS_NATIONAL_ADMIN || cities.length < 2) return;
    cities.forEach(function (c) {
      var o = document.createElement('option');
      o.value = String(c.id);
      o.textContent = c.name;
      f.appendChild(o);
    });
    var none = document.createElement('option');
    none.value = 'none';
    none.textContent = 'No city set';
    f.appendChild(none);
    f.style.display = '';
  }

  // Staff-driven password reset: the server makes a temporary password and it is
  // shown once. Give it to the person privately; they must choose their own at
  // their next sign-in.
  function handleResetPassword(btn) {
    var rid = parseInt(btn.dataset.id, 10);
    var s = allSurveyors.find(function (x) { return x.id === rid; });
    var who = s ? s.name : 'this user';
    var msg = 'Reset the password for ' + who + '?\n\n' +
      'Their current password stops working straight away. You will get a temporary password to give them. They must choose their own password when they next sign in.';
    if (!confirm(msg)) return;
    btn.disabled = true;
    fetch('../api/admin/surveyors.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ id: rid, action: 'reset_password' })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      btn.disabled = false;
      if (!data.success) { alert(data.error || 'Reset failed.'); return; }
      var tp = String(data.temp_password || '');
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(tp).catch(function () {});
        }
      } catch (err) { /* the box below still lets you copy it */ }
      window.prompt('Temporary password for ' + who + ' (copied if your browser allowed it). Share it privately. It is shown only once.', tp);
    })
    .catch(function () { btn.disabled = false; alert('Network error.'); });
  }

  function handleCityChange(sel) {
    var rid = parseInt(sel.dataset.id, 10);
    var prev = sel.dataset.city;
    var newId = parseInt(sel.value, 10);
    if (String(newId) === prev) return;
    var s = allSurveyors.find(function (x) { return x.id === rid; });
    var newName = sel.options[sel.selectedIndex].textContent;
    var oldName = (s && s.city_name) ? s.city_name : 'no city';
    var msg = 'Move ' + (s ? s.name : 'this user') + ' from ' + oldName + ' to ' + newName + '?\n\n' +
      'They will see ' + newName + '\'s roads from now on. Audits they have already submitted are not moved.';
    if (!confirm(msg)) { sel.value = prev; return; }
    fetch('../api/admin/surveyors.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ id: rid, city_id: newId })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) { alert(data.error || 'Update failed.'); sel.value = prev; return; }
      if (s) { s.city_id = newId; s.city_name = newName; }
      applyFilter();
    })
    .catch(function () { alert('Network error.'); sel.value = prev; });
  }

  document.getElementById('survTbody').addEventListener('click', function (e) {
    if (e.target.classList.contains('reset-pw-btn')) { handleResetPassword(e.target); return; }
    if (e.target.classList.contains('toggle-status-btn')) {
      var id = parseInt(e.target.dataset.id, 10);
      var newActive = e.target.dataset.active !== 'true';
      var wasPending = e.target.dataset.pending === '1';
      if (!confirm(wasPending ? 'Cancel the removal? They stay active.'
          : (newActive ? 'Reactivate this user?' : 'Deactivate this user? Segments they have not started go back to unassigned. If they have segments in progress they finish those first, then are deactivated.'))) return;
      fetch('../api/admin/surveyors.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ id: id, is_active: newActive })
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) { alert(data.error || 'Update failed.'); return; }
        var s = allSurveyors.find(function (x) { return x.id === id; });
        if (s) {
          if (data.mode === 'wait') { s.removal_pending = true; }
          else { s.removal_pending = false; s.is_active = newActive; }
        }
        if (data.message) alert(data.message);
        applyFilter();
      })
      .catch(function () { alert('Network error.'); });
      return;
    }

  });

  document.getElementById('survTbody').addEventListener('change', function (e) {
    if (e.target.classList.contains('city-select')) { handleCityChange(e.target); return; }
    if (!e.target.classList.contains('role-select')) return;

    var rid = parseInt(e.target.dataset.id, 10);
    var previousRole = e.target.dataset.role;
    var newRole = e.target.value;
    if (newRole === previousRole) return;

    var who = allSurveyors.find(function (x) { return x.id === rid; });
    var confirmMsg = 'Change this user\'s role from ' + ROLE_LABELS[previousRole] + ' to ' + ROLE_LABELS[newRole] + '?';
    if (newRole === 'city_leader' && who && who.city_id == null && who.home_city) {
      confirmMsg += '\n\nThey have no city yet, so they will be placed in the city they chose at sign-up: ' + who.home_city + '.';
    }
    if (!confirm(confirmMsg)) {
      e.target.value = previousRole;
      return;
    }

    fetch('../api/admin/surveyors.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ id: rid, role: newRole })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) {
        alert(data.error || 'Update failed.');
        e.target.value = previousRole;
        return;
      }
      var s = allSurveyors.find(function (x) { return x.id === rid; });
      if (s) {
        s.role = newRole;
        if (data.city_id != null) {
          s.city_id = data.city_id;
          s.city_name = data.city_name || s.city_name;
          if (!cities.some(function (c) { return c.id === data.city_id; })) {
            cities.push({ id: data.city_id, name: data.city_name || '' });
          }
        }
      }
      applyFilter();
    })
    .catch(function () {
      alert('Network error.');
      e.target.value = previousRole;
    });
  });

  fetch('../api/admin/surveyors.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      document.getElementById('loadingMsg').style.display = 'none';
      if (!data.success) {
        document.getElementById('errorMsg').textContent = data.error || 'Could not load surveyors.';
        document.getElementById('errorMsg').style.display = 'block';
        return;
      }
      allSurveyors = data.surveyors;
      cities = Array.isArray(data.cities) ? data.cities : [];
      setupCityFilter();
      document.getElementById('tableWrap').style.display = 'block';
      applyFilter();
    })
    .catch(function () {
      document.getElementById('loadingMsg').style.display = 'none';
      document.getElementById('errorMsg').textContent = 'Network error — could not load surveyors.';
      document.getElementById('errorMsg').style.display = 'block';
    });

  // ── Export (CSV + Excel) ─────────────────────────────────────
  // Both formats export exactly `lastFiltered` — whatever the
  // search box, role dropdown, and status dropdown
  // currently show — never the full unfiltered `allSurveyors`.
  var exportExcelBtn = document.getElementById('exportExcelBtn');
  var exportMsg = document.getElementById('exportMsg');

  function exportRows() {
    return lastFiltered.map(function (s) {
      return {
        name: s.name,
        email: s.email,
        organisation: s.organisation || '',
        role: s.role,
        roads_created: s.roads_created,
        segments_audited: s.segments_audited,
        last_active: s.last_audit_at || s.last_login || '',
        created_at: s.created_at,
        is_active: !!s.is_active
      };
    });
  }

  function downloadBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function showExportMsg(text, isErr) {
    exportMsg.textContent = text;
    exportMsg.style.display = text ? 'block' : 'none';
    exportMsg.style.color = isErr ? '#dc2626' : 'inherit';
  }

  exportExcelBtn.addEventListener('click', function () {
    var rows = exportRows();
    if (rows.length === 0) {
      showExportMsg('Nothing to export — no users match the current filters.', true);
      return;
    }
    exportExcelBtn.disabled = true;
    showExportMsg('Generating Excel file…');
    fetch('../api/admin/export-users.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ rows: rows })
    })
      .then(function (r) {
        if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Export failed.'); });
        return r.blob();
      })
      .then(function (blob) {
        downloadBlob(blob, 'CycleAudit-Users-' + new Date().toISOString().slice(0, 10) + '.xlsx');
        showExportMsg('');
      })
      .catch(function (e) {
        showExportMsg(e.message || 'Network error — could not export.', true);
      })
      .finally(function () {
        exportExcelBtn.disabled = false;
      });
  });
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
