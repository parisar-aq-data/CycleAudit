<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/published_audits.php  —  Published audits (admin only)
//  Everything visitors see on the home page's Audit Data section:
//  take a live audit off it (Unpublish), delete a past audit, or add
//  an audit done before CycleAudit (with an optional PDF report).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/AdminDashboard.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../helpers/PublicAudits.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../repositories/AdminDashboardRepository.php';
require_once __DIR__ . '/../repositories/PastAuditRepository.php';
require_once __DIR__ . '/../repositories/PublicAuditRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';
require_once __DIR__ . '/../helpers/PastAudits.php';

if ($CURRENT_USER_ROLE !== 'admin') {
    header('Location: ' . roleHomePage($CURRENT_USER_ROLE));
    exit;
}

$dash   = new AdminDashboardRepository($pdo);
$cities = $dash->cities();
$audits = adminSortAudits($dash->audits());

// Condition, score and length of every published live audit (hidden ones too), worked out the same
// way as the home page, so the filters here match what visitors see there.
$pubRepo     = new PublicAuditRepository($pdo);
$liveRows    = $pubRepo->published(true);
$roadToAudit = $pubRepo->roadAuditMap(array_column($liveRows, 'id'));
$scoresBy    = [];
foreach (loadLatestSegmentScores(array_keys($roadToAudit), $pdo) as $s) {
    $scoresBy[$roadToAudit[(int)$s['road_id']]][] = ['final' => $s['final'], 'length' => $s['length']];
}
$liveShape = [];
foreach ($liveRows as $lr) {
    $liveShape[(int)$lr['id']] = publicAuditShape($lr, cityReportAggregate($scoresBy[(int)$lr['id']] ?? []));
}

// Everything shown on the public Audit Data page: published live audits and past audits, newest first.
$publishedRows = [];
foreach ($audits as $a) {
    if ($a['status'] !== 'published') {
        continue;
    }
    $publishedRows[] = [
        'kind' => 'live', 'id' => (int)$a['id'], 'name' => (string)$a['name'], 'city' => (string)$a['city_name'],
        'date' => publicAuditDate($a['audit_date'] ?? null, (int)$a['audit_year']),
        'detail' => cityPlural($a['road_count'], 'road', 'roads') . ' · ' . cityPlural($a['segment_count'], 'segment', 'segments'),
        'view' => 'public_audit_report.php?id=' . (int)$a['id'] . '&from=admin',
        'hidden' => !empty($a['public_hidden_at']),
        'city_id' => (string)($liveShape[(int)$a['id']]['city_id'] ?? $a['city_id'] ?? ''),
        'state' => (string)($liveShape[(int)$a['id']]['state'] ?? ''),
        'length_km' => (float)($liveShape[(int)$a['id']]['length_km'] ?? 0),
        'score' => $liveShape[(int)$a['id']]['score'] ?? null,
        'condition' => $liveShape[(int)$a['id']]['condition'] ?? null,
    ];
}
foreach ((new PastAuditRepository($pdo))->published() as $p) {
    $ps = pastAuditShape($p);
    $publishedRows[] = [
        'kind' => 'past', 'id' => (int)$p['id'], 'name' => (string)$p['name'], 'city' => (string)$p['city_name'],
        'date' => substr((string)$p['audit_date'], 0, 10),
        'detail' => cityPlural((int)$p['road_count'], 'road', 'roads') . ' · ' . cityPlural((int)$p['segment_count'], 'segment', 'segments')
                    . ' · ' . rtrim(rtrim(number_format((float)$p['length_km'], 1, '.', ''), '0'), '.') . ' km',
        'view' => ((int)$p['has_report'] === 1) ? '../api/public/past_audit_report.php?id=' . (int)$p['id'] : '',
        'hidden' => false,
        'city_id' => (string)$ps['city_id'], 'state' => $ps['state'], 'length_km' => (float)$ps['length_km'],
        'score' => $ps['score'], 'condition' => $ps['condition'],
    ];
}
// Shown on the home page first, hidden ones after them; newest first within each group.
usort($publishedRows, static fn(array $x, array $y): int => ($x['hidden'] <=> $y['hidden']) ?: strcmp($y['date'], $x['date']) ?: ($y['id'] <=> $x['id']));
$visibleCount = count(array_filter($publishedRows, static fn(array $r): bool => !$r['hidden']));
// Cities that have a published audit, for the City filter (A to Z).
$filterCities = [];
foreach ($publishedRows as $r) {
    $filterCities[$r['city_id']] = $r['city'];
}
asort($filterCities, SORT_NATURAL | SORT_FLAG_CASE);

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'published';
$csrf      = $h($_SESSION['csrf_token'] ?? '');
// Cities in the picker: every city already in the app, plus the known Indian cities.
$pickCities = [];
foreach (indianCityList() as $n => $st) {
    $pickCities[mb_strtolower($n)] = ['id' => 0, 'name' => $n, 'state' => $st];
}
foreach ($cities as $c) {
    $k = mb_strtolower((string)$c['name']);
    $pickCities[$k] = ['id' => (int)$c['id'], 'name' => (string)$c['name'],
                       'state' => cityStateFor((string)$c['name']) ?? ($pickCities[$k]['state'] ?? '')];
}
uasort($pickCities, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
$today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Published Audits — CycleAudit</title>
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_audit.css?v=<?= filemtime(__DIR__ . '/../css/city_audit.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1>Published Audits</h1>
      <p>What visitors see in the home page's Audit Data section.</p>
    </div>
    <button class="btn-new" id="paAddBtn" type="button">+ Add past audit</button>
  </div>
  <div class="content" id="npApp" data-csrf="<?= $csrf ?>">

    <?php
    $pubNotices = [
        'past_audit_added'   => ['Past audit published.', 'It now appears on the home page.'],
        'past_audit_deleted' => ['Past audit deleted.', 'It no longer appears on the home page.'],
        'audit_unpublished'  => ['Audit hidden.', 'It is off the home page. It is still approved and nothing else changed. Use Publish again to show it.'],
        'audit_republished'  => ['Audit published again.', 'It is back on the home page.'],
    ];
    if (isset($pubNotices[$_GET['notice'] ?? ''])): $pn = $pubNotices[$_GET['notice']]; ?>
    <div class="card" role="status" style="border-left:4px solid #16a34a;background:#f0fdf4">
      <p style="margin:0;font-size:.92rem;line-height:1.5"><b><?= $h($pn[0]) ?></b> <?= $h($pn[1]) ?></p>
    </div>
    <?php endif; ?>

    <div class="card" id="pubCard">
      <div class="card-head">
        <h3>Published audits<?= $visibleCount ? ' (' . $visibleCount . ')' : '' ?></h3>
      </div>
      <p class="pd-card-note">Audits visitors can see on the home page. An approved audit appears here by itself. Unpublish hides one from the home page without deleting it, and Publish again brings it back. Add audits done before CycleAudit with “Add past audit”.</p>
      <?php if ($publishedRows): ?>
      <div class="pd-filters" id="pubFilters">
        <label>City<select id="pubCity" aria-label="Filter by city"><option value="">All cities</option>
          <?php foreach ($filterCities as $fcId => $fcName): ?><option value="<?= $h($fcId) ?>"><?= $h($fcName) ?></option><?php endforeach; ?>
        </select></label>
        <label>Condition<select id="pubCond" aria-label="Filter by condition"><option value="">All conditions</option><option>Good</option><option>OK</option><option>Poor</option><option>Bad</option><option>Very Bad</option></select></label>
        <label>Sort by<select id="pubSort" aria-label="Sort audits">
          <option value="newest">Newest first</option><option value="oldest">Oldest first</option>
          <option value="best">Best score first</option><option value="worst">Worst score first</option>
          <option value="longest">Longest first</option><option value="name">Name A to Z</option>
        </select></label>
        <label class="pd-fsearch">Search<input id="pubQ" type="search" placeholder="Audit or city name" aria-label="Search audits"></label>
        <button type="button" id="pubClear" class="pd-fclear">Clear filters</button>
      </div>
      <p class="pd-fmsg" id="pubMsg" aria-live="polite"></p>
      <?php endif; ?>
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>Audit</th><th>City</th><th>Condition</th><th>Type</th><th></th></tr></thead>
          <tbody id="pubRows">
          <?php if (!$publishedRows): ?>
            <tr><td colspan="5" class="pd-empty">Nothing is published yet. Approve an audit, or add a past audit.</td></tr>
          <?php endif; ?>
          <?php foreach ($publishedRows as $pr): ?>
            <tr data-city="<?= $h($pr['city_id']) ?>" data-cond="<?= $h($pr['condition'] ?? '') ?>" data-score="<?= $pr['score'] === null ? '' : $h($pr['score']) ?>" data-len="<?= $h($pr['length_km']) ?>" data-date="<?= $h($pr['date']) ?>" data-name="<?= $h(mb_strtolower($pr['name'])) ?>" data-hidden="<?= $pr['hidden'] ? '1' : '0' ?>" data-text="<?= $h(mb_strtolower($pr['name'] . ' ' . $pr['city'] . ' ' . $pr['state'])) ?>"<?= $pr['hidden'] ? ' style="opacity:.62"' : '' ?>>
              <td><strong><?= $h($pr['name']) ?></strong><span class="pd-sub"><?= $h(cityAuditDateLabel($pr['date'], (int)substr($pr['date'], 0, 4))) ?> · <?= $h($pr['detail']) ?></span></td>
              <td><?= $h($pr['city']) ?></td>
              <td><?php if ($pr['condition']): ?><span class="pd-cond pd-cond-<?= $h(strtolower(str_replace(' ', '-', (string)$pr['condition']))) ?>"><?= $h($pr['condition']) ?></span><span class="pd-sub">Score <?= $h($pr['score']) ?></span><?php else: ?><span class="pd-sub">Not scored</span><?php endif; ?></td>
              <td><?php if ($pr['hidden']): ?><span class="pd-chip pd-chip-past">Hidden from home page</span><?php else: ?><span class="pd-chip <?= $pr['kind'] === 'past' ? 'pd-chip-past' : 'pd-chip-published' ?>"><?= $pr['kind'] === 'past' ? 'Past audit' : 'Live audit' ?></span><?php endif; ?></td>
              <td class="pd-nowrap"><div class="pd-actions">
                <?php if ($pr['view'] !== ''): ?><a class="pd-link" href="<?= $h($pr['view']) ?>" target="_blank" rel="noopener">View report</a><?php else: ?><span class="pd-sub">No report file</span><?php endif; ?>
                <?php if ($pr['kind'] === 'live' && $pr['hidden']): ?><button type="button" class="pd-link pubRepublish" data-id="<?= (int)$pr['id'] ?>" data-name="<?= $h($pr['name']) ?>">Publish again</button>
                <?php elseif ($pr['kind'] === 'live'): ?><button type="button" class="pd-link pubUnpublish" data-id="<?= (int)$pr['id'] ?>" data-name="<?= $h($pr['name']) ?>">Unpublish</button><?php endif; ?>
                <button type="button" class="pd-link pd-link-danger pubDelete" data-kind="<?= $h($pr['kind']) ?>" data-id="<?= (int)$pr['id'] ?>" data-name="<?= $h($pr['name']) ?>">Delete</button>
              </div></td>
            </tr>
          <?php endforeach; ?>
            <tr id="pubNoMatch" hidden><td colspan="5" class="pd-empty">No published audits match these filters.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>
<div class="ca-modal-bg" id="paAddBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="paAddTitle">
    <h3 id="paAddTitle">Add a past audit</h3>
    <p>For an audit done before CycleAudit. It is published on the home page as soon as you save it.</p>
    <form id="paAddForm" class="ca-form" novalidate>
      <div class="ca-field">
        <label for="paCity">City *</label>
        <input id="paCity" name="city_name" type="text" list="npCityList" maxlength="100" autocomplete="off" placeholder="Start typing, e.g. Pune">
          <datalist id="npCityList" data-aliases="<?= $h(json_encode(array_combine(array_map('cityKey', array_keys(CITY_ALIASES)), array_values(CITY_ALIASES)), JSON_UNESCAPED_UNICODE)) ?>">
            <?php foreach ($pickCities as $pc): ?>
              <option value="<?= $h($pc['name']) ?>" label="<?= $h($pc['state']) ?>" data-id="<?= (int)$pc['id'] ?>" data-state="<?= $h($pc['state']) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        <div class="ca-err"></div>
      </div>
      <div class="ca-field">
        <label for="paState">State</label>
        <input id="paState" name="state" type="text" readonly tabindex="-1" placeholder="Fills in from the city">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="paName">Audit name *</label>
        <input id="paName" name="name" type="text" maxlength="150" placeholder="e.g. Pune Cycle Track Audit 2024–25">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="paDate">Audit date *</label>
        <input id="paDate" name="audit_date" type="date" min="2000-01-01" max="<?= $h($today) ?>">
        <div class="ca-hint">The audit appears under the year of this date.</div>
        <div class="ca-err"></div>
      </div>
      <div class="ca-field">
        <label for="paRoads">Roads *</label>
        <input id="paRoads" name="road_count" type="number" min="1" step="1" inputmode="numeric">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field">
        <label for="paSegs">Segments *</label>
        <input id="paSegs" name="segment_count" type="number" min="1" step="1" inputmode="numeric">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field">
        <label for="paLen">Total length (km) *</label>
        <input id="paLen" name="length_km" type="text" inputmode="decimal" placeholder="e.g. 12.5">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field">
        <label for="paScore">Overall score <span style="font-weight:400">(optional)</span></label>
        <input id="paScore" name="score" type="text" inputmode="decimal" placeholder="0 to 100, lower is better">
        <div class="ca-hint">Sets the Good / OK / Poor / Bad badge. Leave empty for no badge.</div>
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="paReport">PDF report <span style="font-weight:400">(optional, up to 8 MB)</span></label>
        <input id="paReport" name="report" type="file" accept="application/pdf,.pdf">
        <div class="ca-hint">Visitors can open and download it from the audit card. Without it the card shows the summary only.</div>
        <div class="ca-err"></div>
      </div>
      <div class="ca-modal-actions full">
        <button type="button" class="ca-btn ghost" id="paAddCancel">Cancel</button>
        <button type="submit" class="ca-btn" id="paAddSave">Publish past audit</button>
      </div>
    </form>
  </div>
</div>
<div class="ca-modal-bg" id="pubConfirmBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="pubConfTitle">
    <h3 id="pubConfTitle"></h3>
    <p id="pubConfText"></p>
    <div class="ca-field" id="pubConfNameWrap" hidden>
      <label for="pubConfName">Type the audit name to confirm</label>
      <input id="pubConfName" type="text" autocomplete="off">
      <div class="ca-err"></div>
    </div>
    <div class="ca-field" id="pubConfReasonWrap" hidden>
      <label for="pubConfReason">Why are you deleting it? The City Leader and surveyors will see this.</label>
      <textarea id="pubConfReason" rows="3" maxlength="200" placeholder="For example: duplicate of another project"></textarea>
      <div class="ca-err"></div>
    </div>
    <div class="ca-modal-actions">
      <button type="button" class="ca-btn ghost" id="pubConfCancel">Cancel</button>
      <button type="button" class="ca-btn" id="pubConfGo">Confirm</button>
    </div>
  </div>
</div>
<div class="sb-overlay" id="sb-overlay"></div>
<div class="ca-toast" id="caToast"></div>
<script nonce="<?= $nonce ?>" src="../js/city_search.js?v=<?= filemtime(__DIR__ . '/../js/city_search.js') ?>"></script>
<script nonce="<?= $nonce ?>" src="../js/admin_published.js?v=<?= filemtime(__DIR__ . '/../js/admin_published.js') ?>"></script>
<script nonce="<?= $nonce ?>" src="../js/admin_published_filters.js?v=<?= filemtime(__DIR__ . '/../js/admin_published_filters.js') ?>"></script>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
</body>
</html>
