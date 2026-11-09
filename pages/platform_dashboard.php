<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/platform_dashboard.php  —  Admin Dashboard (home)
//  admin only: what needs attention, every audit, every city.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/AdminDashboard.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../repositories/AdminDashboardRepository.php';
require_once __DIR__ . '/partials/cx_icons.php';

if ($CURRENT_USER_ROLE !== 'admin') {
    header('Location: ' . roleHomePage($CURRENT_USER_ROLE));
    exit;
}

$dash     = new AdminDashboardRepository($pdo);
$cities   = $dash->cities();
$audits   = adminSortAudits($dash->audits());
$awaiting = (new AuditReviewRepository($pdo))->awaitingApproval();

$segs = $done = 0;
foreach ($cities as $c) {
    $segs += $c['segs'];
    $done += $c['done'];
}
$inProgress = count(array_filter($audits, static fn(array $a): bool => in_array($a['status'], ADMIN_IN_PROGRESS_STATUSES, true)));
$attention  = adminAttentionItems(
    $awaiting,
    adminStalledAudits($audits, new DateTimeImmutable('now', new DateTimeZone('UTC'))),
    adminCitiesWithoutLeader($cities)
);
// The dashboard lists work in progress. Finished (published) audits are under "Published Audits" in the sidebar.
$openAudits     = array_values(array_filter($audits, static fn(array $a): bool => !in_array($a['status'], ['published', 'voided'], true)));
$publishedCount = count($audits) - count($openAudits);
$firstName      = trim((string)strtok(trim((string)($CURRENT_USER_NAME ?? '')), ' '));
$greeting       = cityGreeting((int)(new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('G'));
$pct = static fn(int $d, int $t): string => $t > 0 ? (string)round($d * 100 / $t) . '%' : '—';

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'home';
$csrf      = $h($_SESSION['csrf_token'] ?? '');
// Cities in the picker: every city already in the app, plus the known Indian cities.
$pickCities = [];
foreach (indianCityList() as $n => $st) {
    $pickCities[mb_strtolower($n)] = ['id' => 0, 'name' => $n, 'state' => $st, 'leaders' => -1];
}
foreach ($cities as $c) {
    $k = mb_strtolower((string)$c['name']);
    $pickCities[$k] = ['id' => (int)$c['id'], 'name' => (string)$c['name'],
                       'state' => cityStateFor((string)$c['name']) ?? ($pickCities[$k]['state'] ?? ''), 'leaders' => (int)$c['leaders'],
                       'leader_name' => (string)($c['leader_name'] ?? '')];
}
uasort($pickCities, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
// Active City Leaders a project can be assigned to (the page narrows them to the city), and
// active surveyors an Admin can promote to City Leader from the Cities table.
$leaderCandidates = $pdo->query(
    "SELECT id, name, email, city_id, home_city, home_state, role FROM users WHERE is_active = 1 AND role IN ('surveyor', 'city_leader') ORDER BY name ASC, id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$leaderCandidates = array_map(static fn(array $u): array => [
    'role' => (string)$u['role'], 'id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)$u['email'],
    'city_id' => $u['city_id'] !== null ? (int)$u['city_id'] : null,
    'home' => str_contains((string)($u['home_city'] ?? ''), '(' . (string)($u['home_state'] ?? '') . ')')
        ? (string)$u['home_city']
        : implode(', ', array_filter([(string)($u['home_city'] ?? ''), (string)($u['home_state'] ?? '')], 'strlen')),
], $leaderCandidates);
$today     = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Dashboard — CycleAudit</title>
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_audit.css?v=<?= filemtime(__DIR__ . '/../css/city_audit.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_dashboard.css?v=<?= filemtime(__DIR__ . '/../css/city_dashboard.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1>Dashboard</h1>
      <p>What needs you, and how every city is doing.</p>
    </div>
    <button class="btn-new" id="npNewBtn" type="button">+ New Project</button>
  </div>
  <div class="content cx-page" id="npApp" data-csrf="<?= $csrf ?>" data-candidates="<?= $h(json_encode($leaderCandidates, JSON_UNESCAPED_UNICODE)) ?>" data-assign-city="<?= (int)($_GET['assign_leader'] ?? 0) ?>">

    <section class="cx-hero">
      <div class="cx-hero-row">
        <div>
          <h2 class="cx-hello"><?= $h($greeting) ?><?= $firstName !== '' ? ', ' . $h($firstName) : '' ?></h2>
          <p class="cx-hero-sub">
            <?php if ($attention): ?>
              <b><?= count($attention) ?></b> <?= count($attention) === 1 ? 'thing needs' : 'things need' ?> your attention.
            <?php else: ?>
              All clear. Nothing needs your attention right now.
            <?php endif; ?>
          </p>
        </div>
        <div class="cx-metas">
          <span class="cx-meta"><?= cxIcon('route') ?> <b><?= (int)$inProgress ?></b> in progress</span>
          <span class="cx-meta"><?= cxIcon('pin') ?> <b><?= count($cities) ?></b> <?= count($cities) === 1 ? 'city' : 'cities' ?></span>
          <a class="cx-meta" href="published_audits.php"><?= cxIcon('check') ?> <b><?= (int)$publishedCount ?></b> published</a>
        </div>
      </div>
    </section>

    <?php if ($CURRENT_USER_ROLE === 'admin') { require __DIR__ . '/partials/password_requests.php'; } ?>
    <?php if ($CURRENT_USER_ROLE === 'admin') { require __DIR__ . '/partials/signup_requests.php'; } ?>

    <?php if (($_GET['notice'] ?? '') === 'project_deleted_ok'): ?>
    <div class="card" role="status" style="border-left:4px solid #16a34a;background:#f0fdf4">
      <p style="margin:0;font-size:.92rem;line-height:1.5"><b>Project deleted.</b> It no longer appears on any dashboard and work on it has stopped. The City Leader and surveyors were told.</p>
    </div>
    <?php endif; ?>
    <div class="card" id="npNewCard" style="display:none">
      <div class="card-head"><h3>New project</h3></div>
      <form id="npNewForm" class="ca-form" novalidate>
        <div class="ca-field">
          <label for="npCity">City *</label>
          <input id="npCity" name="city_name" type="text" list="npCityList" maxlength="100" autocomplete="off" placeholder="Start typing to search, e.g. Pune">
          <datalist id="npCityList" data-aliases="<?= $h(json_encode(array_combine(array_map('cityKey', array_keys(CITY_ALIASES)), array_values(CITY_ALIASES)), JSON_UNESCAPED_UNICODE)) ?>">
            <?php foreach ($pickCities as $pc): ?>
              <option value="<?= $h($pc['name']) ?>" label="<?= $h($pc['state']) ?>" data-id="<?= (int)$pc['id'] ?>" data-state="<?= $h($pc['state']) ?>" data-leaders="<?= (int)$pc['leaders'] ?>" data-leader-name="<?= $h($pc['leader_name'] ?? '') ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <div class="ca-err"></div>
          <div class="ca-hint" id="npCityHint" style="display:none"></div>
        </div>
        <div class="ca-field">
          <label for="npState">State *</label>
          <input id="npState" name="state" type="text" maxlength="100" placeholder="Fills in from the city">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="npName">Project name *</label>
          <input id="npName" name="name" type="text" maxlength="150" placeholder="e.g. Pune Cycle Infrastructure Audit">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="npDate">Audit date *</label>
          <input id="npDate" name="audit_date" type="date" min="2000-01-01" max="2100-12-31" value="<?= $h($today) ?>">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field full" id="npLeaderWrap">
          <label for="npLeader">City Leader for this project</label>
          <select id="npLeader" name="leader_user_id"><option value="">— Assign later —</option></select>
          <div class="ca-hint" id="npLeaderHint">Pick a city first. Only City Leaders of that city are listed. The project is visible to the City Leader you choose, and to nobody else in the city.</div>
        </div>
        <div class="ca-field full">
          <label for="npInfo">Programme info</label>
          <textarea id="npInfo" name="programme_info" maxlength="2000" placeholder="Programme, funder or scope notes (optional)"></textarea>
          <div class="ca-err"></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">Create project</button>
          <button class="ca-btn ghost" type="button" id="npNewCancel">Cancel</button>
        </div>
      </form>
    </div>

    <div class="cx-kpis">
      <div class="cx-kpi<?= count($awaiting) > 0 ? ' hot' : '' ?>"><span class="cx-kpi-ico o"><?= cxIcon('flag') ?></span><div><b><?= count($awaiting) ?></b><span>Awaiting your approval</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico b"><?= cxIcon('route') ?></span><div><b><?= $inProgress ?></b><span>Audits in progress</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico g"><?= cxIcon('pin') ?></span><div><b><?= count($cities) ?></b><span>Cities</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico p"><?= cxIcon('check') ?></span><div><b><?= $pct($done, $segs) ?></b><span>Segments done</span></div></div>
    </div>

    <?php if ($attention): ?>
    <div class="card" id="needsAttention">
      <div class="card-head">
        <h3>Needs your attention (<?= count($attention) ?>)</h3>
      </div>
      <ul class="pd-attn">
        <?php foreach ($attention as $it): ?>
        <li class="pd-attn-row pd-<?= $h($it['tone']) ?>">
          <span class="pd-attn-dot" aria-hidden="true"></span>
          <div class="pd-attn-text">
            <strong><?= $h($it['title']) ?></strong>
            <span><?= $h($it['detail']) ?></span>
          </div>
          <a class="pd-btn<?= $it['tone'] === 'urgent' ? ' pd-btn-solid' : '' ?>" href="<?= $h($it['href']) ?>"><?= $h($it['cta']) ?> →</a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">
        <h3>Audits in progress<?= $openAudits ? ' (' . count($openAudits) . ')' : '' ?></h3>
        <?php if ($publishedCount > 0): ?><a href="published_audits.php">Published audits (<?= (int)$publishedCount ?>) →</a><?php endif; ?>
      </div>
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>Audit</th><th>City</th><th>City Leader</th><th>Status</th><th>Progress</th><th>Last activity</th><th></th></tr></thead>
          <tbody>
          <?php if (!$audits): ?>
            <tr><td colspan="7" class="pd-empty">No audits yet. Use “+ New Project” to create the first one; the City Leader then adds its roads.</td></tr>
          <?php elseif (!$openAudits): ?>
            <tr><td colspan="7" class="pd-empty">No audits in progress. Finished audits are listed under <a class="pd-link" href="published_audits.php">Published Audits</a>.</td></tr>
          <?php endif; ?>
          <?php foreach ($openAudits as $a):
              $p = cityDashProgress($a['done_count'], $a['segment_count']); ?>
            <tr>
              <td><strong><?= $h($a['name']) ?></strong><span class="pd-sub"><?= $h(cityAuditDateLabel($a['audit_date'] ?? null, (int)$a['audit_year'])) ?> · <?= $h(cityPlural($a['road_count'], 'road', 'roads')) ?></span></td>
              <td><?= $h($a['city_name']) ?></td>
              <td><?php if ($a['leader_name'] !== null): ?><?= $h($a['leader_name']) ?><?php else: ?><span class="pd-warn-text">Not assigned</span><?php endif; ?></td>
              <td><span class="pd-chip pd-chip-<?= $h(adminAuditStatusClass((string)$a['status'])) ?>"><?= $h(adminAuditStatusLabel((string)$a['status'])) ?></span></td>
              <td>
                <?php if ($a['segment_count'] > 0): ?>
                <div class="pd-bar" role="img" aria-label="<?= $p ?>% of segments done"><i style="width:<?= $p ?>%"></i></div>
                <span class="pd-sub"><?= (int)$a['done_count'] ?> of <?= (int)$a['segment_count'] ?> segments</span>
                <?php else: ?><span class="pd-sub">No segments yet</span><?php endif; ?>
              </td>
              <td class="pd-nowrap"><?= $h(cityLocalTime($a['last_activity'])) ?></td>
              <td class="pd-nowrap pd-actions"><a class="pd-link" href="<?= $h(adminAuditLink($a)) ?>"><?= $a['status'] === 'awaiting_approval' ? 'Review' : 'Open' ?> →</a>
                <button type="button" class="pd-link pd-link-danger dpDelete" data-id="<?= (int)$a['id'] ?>" data-name="<?= $h($a['name']) ?>">Delete</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" id="npCities">
      <div class="card-head">
        <h3>Cities</h3>
        <a href="admin_surveyors.php">Manage users →</a>
      </div>
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>City</th><th>City Leader</th><th>Surveyors</th><th>Roads</th><th>Segments done</th><th></th></tr></thead>
          <tbody>
          <?php if (!$cities): ?>
            <tr><td colspan="6" class="pd-empty">No cities yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($cities as $c): ?>
            <tr>
              <td><strong><?= $h($c['name']) ?></strong></td>
              <td><?php if ($c['leaders'] === 0): ?><span class="pd-warn-text">None</span><?php else: ?><?= $h($c['leader_name']) ?><?php endif; ?></td>
              <td><?= (int)$c['surveyors'] ?></td>
              <td><?= (int)$c['road_groups'] ?></td>
              <td><?= $h($pct($c['done'], $c['segs'])) ?> <span class="pd-sub"><?= (int)$c['done'] ?> of <?= (int)$c['segs'] ?></span></td>
              <td class="pd-nowrap"><button type="button" class="pd-link npAssign" data-city="<?= (int)$c['id'] ?>" data-name="<?= $h($c['name']) ?>">Add City Leader</button> · <a class="pd-link" href="city_dashboard.php?city_id=<?= (int)$c['id'] ?>">Open →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<div class="sb-overlay" id="sb-overlay"></div>
<div class="ca-modal-bg" id="dpDeleteBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="dpDelTitle">
    <h3 id="dpDelTitle">Delete this project?</h3>
    <p>This stops all work on <b id="dpDelName"></b> straight away and removes it from every dashboard. The City Leader and surveyors are told. The audit data stays in the database but cannot be opened from the app.</p>
    <div class="ca-field">
      <label for="dpDelConfirm">Type the project name to confirm</label>
      <input id="dpDelConfirm" type="text" autocomplete="off">
      <div class="ca-err"></div>
    </div>
    <div class="ca-field">
      <label for="dpDelReason">Why are you deleting it? The City Leader and surveyors will see this.</label>
      <textarea id="dpDelReason" rows="3" maxlength="200" placeholder="For example: duplicate of another project"></textarea>
      <div class="ca-err"></div>
    </div>
    <div class="ca-modal-actions">
      <button type="button" class="ca-btn ghost" id="dpDelCancel">Cancel</button>
      <button type="button" class="ca-btn ca-btn-danger" id="dpDelGo" disabled>Delete project</button>
    </div>
  </div>
</div>
<script nonce="<?= $nonce ?>" src="../js/city_search.js?v=<?= filemtime(__DIR__ . '/../js/city_search.js') ?>"></script>
<script nonce="<?= $nonce ?>" src="../js/admin_new_project.js?v=<?= filemtime(__DIR__ . '/../js/admin_new_project.js') ?>"></script>
<script nonce="<?= $nonce ?>" src="../js/admin_dashboard_delete.js?v=<?= filemtime(__DIR__ . '/../js/admin_dashboard_delete.js') ?>"></script>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
</body>
</html>
