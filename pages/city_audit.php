<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_audit.php?id=N  —  one city audit
//  City Leader: add roads from the city's road list, generate their
//  segments, remove a road while nothing is audited on it.
//  Admin can open any audit read-only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../helpers/ProjectStatus.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/partials/cx_icons.php';

$isNational = $CURRENT_USER_ROLE === 'admin';
$repo       = new CityAuditRepository($pdo);
$audit      = $repo->find((int)($_GET['id'] ?? 0));

if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}
if (projectIsDeleted($audit)) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php?notice=project_deleted_ok' : 'city_dashboard.php?notice=project_deleted'));
    exit;
}

$canEdit   = !$isNational && $repo->isEditable($audit);
$available = $canEdit ? $repo->availableRoadGroups((int)$audit['city_id'], (int)$audit['id']) : [];
$cityRoadCount = 0;
if ($canEdit && !$available) {
    $crc = $pdo->prepare('SELECT COUNT(*) FROM road_groups WHERE city_id = ? AND is_flagged = 0');
    $crc->execute([(int)$audit['city_id']]);
    $cityRoadCount = (int)$crc->fetchColumn();
}
$roads     = $repo->roadsWithSegments((int)$audit['id']);
$canAssign = !$isNational && $repo->canAssign($audit);
$auditLeaderId = isset($audit['leader_user_id']) ? (int)$audit['leader_user_id'] : null;
$teamOnly   = !$isNational && teamScopeApplies($pdo, (int)$audit['city_id']);
$surveyors = $canAssign ? $repo->assignableSurveyors((int)$audit['city_id'], $auditLeaderId) : [];
$counts    = $repo->assignmentCounts((int)$audit['id']);
$canActivate = !$isNational && $audit['status'] === 'draft';
$allAssigned = $counts['total'] > 0 && $counts['assigned'] === $counts['total'];
$canActivateNow = $counts['assigned'] > 0;   // one assigned segment is enough to start
$review     = new AuditReviewRepository($pdo);
$rv         = $review->counts((int)$audit['id']);
$showReview = $audit['status'] !== 'draft' && $audit['status'] !== 'voided';
$canReview  = !$isNational && in_array($audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true);
$adminNote  = $review->adminDecision((int)$audit['id'])['admin_note'];
$wasReturned = !$isNational && $adminNote !== null && in_array($audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true);
$submitted  = $showReview ? $review->submissions((int)$audit['id']) : [];
$sentBack   = $showReview ? $review->sentBack((int)$audit['id']) : [];
$closeBlock = auditReviewCloseBlockReason((string)$audit['status'], $rv);
$isClosed   = in_array($audit['status'], ['finalised', 'awaiting_approval', 'published'], true);
$roadScores = [];
if ($isClosed) {
    foreach ($roads as $r0) {
        try { $roadScores[(int)$r0['id']] = calculateRoadScore((int)$r0['id'], $pdo); }
        catch (Throwable $e) { $roadScores[(int)$r0['id']] = null; }
    }
}
$segScore = static function (?int $auditRowId) use ($pdo): ?array {
    if ($auditRowId === null) { return null; }
    try { return calculateSegmentScore($auditRowId, $pdo); } catch (Throwable $e) { return null; }
};
$backUrl   = 'city_dashboard.php' . ($isNational ? '?city_id=' . (int)$audit['city_id'] : '');

// Admin only: the city's City Leaders (the project goes to one of them), and what deleting would stop.
$adminLeaders = []; $adminImpact = null;
if ($isNational) {
    $adminLeaders = cityLeadersOf($pdo, (int)$audit['city_id']);
    $adminImpact = $repo->deletionImpact((int)$audit['id']);
}

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';
$num       = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$statusLabel = ucfirst(str_replace('_', ' ', (string)$audit['status']));

// ── Dashboard-style summary: stage tracker, progress mix, next step, scores ──
$stage      = cityAuditStage((string)$audit['status']);
$published  = $audit['status'] === 'published';
$segCounts  = [
    'total'         => (int)$rv['total'],
    'unassigned'    => (int)$rv['unassigned'],
    'assigned'      => (int)$rv['assigned'],
    'submitted'     => (int)$rv['submitted'],
    'needs_revisit' => (int)$rv['needs_revisit'],
    'approved'      => (int)$rv['approved'],
];
$mix        = citySegmentMix($segCounts);
$approvedPct = cityDashProgress($segCounts['approved'], $segCounts['total']);
$attnItems  = cityAuditAttention((string)$audit['status'], $segCounts, $adminNote, $isNational);
$nextItem   = $attnItems[0] ?? null;
foreach ($attnItems as $it0) {
    if ($it0['level'] === 'action') { $nextItem = $it0; break; }
}
$nextCta    = cityAuditNextAction((string)$audit['status'], $segCounts, $isNational, (int)$audit['id']);
$overall    = null;
$roadConds  = [];
if ($isClosed) {
    $wSum = 0.0; $fSum = 0.0; $sSum = 0.0; $cSum = 0.0; $mSum = 0.0;
    foreach ($roads as $r0) {
        $rs0 = $roadScores[(int)$r0['id']] ?? null;
        if (!$rs0) { continue; }
        $w0 = max(0.0, (float)$r0['total_length']);
        $wSum += $w0;
        $fSum += (float)$rs0['score'] * $w0;
        $sSum += (float)$rs0['safety_score'] * $w0;
        $cSum += (float)$rs0['continuity_score'] * $w0;
        $mSum += (float)$rs0['comfort_score'] * $w0;
        $roadConds[] = ['condition' => $rs0['condition'] ?? ''];
    }
    if ($wSum > 0.0) {
        $sc0 = round($fSum / $wSum, 2);
        $overall = [
            'score' => $sc0, 'condition' => ScoreHelpers::scoreToCondition($sc0),
            'bars'  => ['Safety' => round($sSum / $wSum, 2), 'Continuity' => round($cSum / $wSum, 2), 'Comfort' => round($mSum / $wSum, 2)],
        ];
    }
}
$barClass = static fn(float $v): string => cityScoreBarClass($v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title><?= $h($audit['name']) ?> — CycleAudit</title>
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
      <h1><?= $h($audit['name']) ?></h1>
      <p><a href="<?= $h($backUrl) ?>">← All audits</a></p>
    </div>
    <?php if ($isNational): ?>
    <div class="ap-actions">
      <span class="ap-leader" style="margin-right:10px"><?php if ($audit['leader_name'] !== null): ?>City Leader: <b><?= $h($audit['leader_name']) ?></b><?php else: ?><b style="color:#b45309">No City Leader assigned</b><?php endif; ?></span>
      <button type="button" class="ca-btn" id="apAssignBtn"><?= $audit['leader_name'] !== null ? 'Change City Leader' : 'Assign City Leader' ?></button>
      <button type="button" class="ca-btn ghost" id="apEditBtn">Edit project</button>
      <button type="button" class="ca-btn ca-btn-danger" id="apDeleteBtn">Delete project</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="content cx-page cx-audit" id="caApp" data-csrf="<?= $csrf ?>" data-audit-id="<?= (int)$audit['id'] ?>">

    <section class="cx-hero">
      <div class="cx-metas">
        <span class="ca-badge <?= $h($audit['status']) ?>"><?= $h($statusLabel) ?></span>
        <span class="cx-meta"><?= cxIcon('pin') ?> <b><?= $h($audit['city_name']) ?></b></span>
        <span class="cx-meta"><?= cxIcon('flag') ?> <b><?= $h($audit['state']) ?></b></span>
        <span class="cx-meta"><?= cxIcon('calendar') ?> <b><?= $h(cityAuditDateLabel($audit['audit_date'] ?? null, (int)$audit['audit_year'])) ?></b></span>
        <?php if ($audit['created_by_name']): ?><span class="cx-meta"><?= cxIcon('user') ?> Created by <b><?= $h($audit['created_by_name']) ?></b></span><?php endif; ?>
      </div>
      <?php if ($audit['programme_info']): ?>
        <p class="cx-prog-info"><?= $h($audit['programme_info']) ?></p>
      <?php endif; ?>
      <?php if ($stage >= 0): ?>
      <div class="cx-track" role="list" aria-label="Audit progress">
        <?php foreach (CITY_AUDIT_STAGES as $i => $label):
            $cls = ($published || $i < $stage) ? 'done' : ($i === $stage ? 'now' : '');
        ?>
          <div class="cx-step <?= $cls ?>" role="listitem"<?= $cls === 'now' ? ' aria-current="step"' : '' ?>>
            <i><?= $cls === 'done' ? cxIcon('tick') : ($i + 1) ?></i>
            <span><?= $h($label) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($nextItem): ?>
    <div class="cx-next <?= $h($nextItem['level']) ?>">
      <span class="cx-next-ico"><?= cxIcon($nextItem['level'] === 'done' ? 'check' : ($nextItem['level'] === 'info' ? 'clock' : 'alert')) ?></span>
      <div class="cx-next-txt">
        <small><?= $nextItem['level'] === 'action' ? 'Your next step' : ($nextItem['level'] === 'done' ? 'All done' : 'Status') ?></small>
        <p><?= $h($nextItem['text']) ?></p>
      </div>
      <?php if ($nextCta): ?>
        <?php
        // "Send to Admin" / "Close audit" act right here: one click, one confirmation.
        // (Used to scroll down to the Review card, which needed a second click.)
        $ctaDirect = null;
        if (!$isNational && $audit['status'] === 'finalised') { $ctaDirect = 'caSend'; }
        elseif (!empty($canReview) && $closeBlock === null && $nextCta['label'] === 'Close audit') { $ctaDirect = 'caClose'; }
        ?>
        <?php if ($ctaDirect): ?>
          <button class="ca-btn" type="button" data-click-target="<?= $h($ctaDirect) ?>"><?= $h($nextCta['label']) ?> <?= cxIcon('arrow') ?></button>
        <?php else: ?>
          <a class="ca-btn" href="<?= $h($nextCta['href']) ?>"><?= $h($nextCta['label']) ?> <?= cxIcon('arrow') ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="cx-kpis">
      <div class="cx-kpi"><span class="cx-kpi-ico g"><?= cxIcon('road') ?></span><div><b><?= count($roads) ?></b><span><?= count($roads) === 1 ? 'Road' : 'Roads' ?> in this audit</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico b"><?= cxIcon('route') ?></span><div><b><?= (int)$segCounts['total'] ?></b><span>Segments</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico o"><?= cxIcon('users') ?></span><div><b><?= (int)$counts['assigned'] ?> <small>/ <?= (int)$counts['total'] ?></small></b><span>Have a surveyor</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico p"><?= cxIcon('check') ?></span><div><b><?= (int)$segCounts['approved'] ?> <small>/ <?= (int)$segCounts['total'] ?></small></b><span>Approved (<?= $approvedPct ?>%)</span></div></div>
    </div>

    <?php $half = $mix ? ' cx-half' : ''; /* progress + assignment sit side by side on wide screens */ ?>
    <?php if ($mix): ?>
    <div class="card cx-half">
      <div class="cx-mix-top"><span>Segment progress</span><span><b><?= $approvedPct ?>%</b> approved</span></div>
      <div class="cx-mix" role="img" aria-label="<?= $h($segCounts['approved'] . ' of ' . $segCounts['total'] . ' segments approved') ?>">
        <?php foreach ($mix as $m): if ($m['pct'] > 0): ?><i class="<?= $h($m['key']) ?>" style="width:<?= (int)$m['pct'] ?>%" title="<?= $h($m['label'] . ': ' . $m['count']) ?>"></i><?php endif; endforeach; ?>
      </div>
      <div class="cx-leg">
        <?php foreach ($mix as $m): if ($m['count'] > 0): ?><span class="<?= $h($m['key']) ?>"><b><?= (int)$m['count'] ?></b> <?= $h($m['label']) ?></span><?php endif; endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card<?= $half ?>" id="caAssign">
      <div class="card-head">
        <h3>Surveyor assignment</h3>
        <?php if ($canActivate): ?>
          <button class="ca-btn" type="button" id="caActivate" <?= $canActivateNow ? '' : 'disabled' ?>>Activate Audit</button>
        <?php endif; ?>
      </div>
      <p class="ca-assign-sum"><b><?= (int)$counts['assigned'] ?></b> of <b><?= (int)$counts['total'] ?></b> segments have a surveyor.</p>
      <?php if ($counts['total'] > 0): ?>
        <div class="cd-track" style="margin-top:10px"><i style="width:<?= cityDashProgress((int)$counts['assigned'], (int)$counts['total']) ?>%"></i></div>
      <?php endif; ?>
      <?php if ($canAssign && !$surveyors): ?>
        <?php if ($teamOnly): ?>
          <p class="ca-hint-line">Nobody is in your team yet, so segments cannot be assigned. Add surveyors on the <a href="city_team.php">My team</a> page first.</p>
        <?php else: ?>
          <p class="ca-hint-line">No active surveyors are registered in <?= $h($audit['city_name']) ?> yet, so segments cannot be assigned.</p>
        <?php endif; ?>
      <?php elseif ($canActivate && !$canActivateNow): ?>
        <p class="ca-hint-line"><?= $counts['total'] === 0 ? 'Add a road, then assign its segments.' : 'Assign at least one segment to a surveyor to activate the audit.' ?></p>
      <?php elseif ($canActivate && !$allAssigned): ?>
        <p class="ca-hint-line">You can activate now. The <?= (int)($counts['total'] - $counts['assigned']) ?> unassigned segment(s) can still be assigned later.</p>
      <?php elseif ($audit['status'] === 'active'): ?>
        <p class="ca-hint-line">This audit is active. You can still add or remove roads and segments, and reassign segments, until auditing starts on them.</p>
      <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="card" id="caAddRoad">
      <div class="card-head"><h3>Add a road</h3></div>
      <?php if (!$available): ?>
        <?php if ($cityRoadCount === 0): ?>
        <p class="rd-empty">Your city has no roads yet. <a href="admin.php">Add them on the Roads page</a>, then come back to add them to this audit.</p>
        <?php else: ?>
        <p class="rd-empty">Every road in this city is already part of this audit. <a href="admin.php">Add more on the Roads page.</a></p>
        <?php endif; ?>
      <?php else: ?>
      <form id="caRoadForm" class="ca-form" novalidate>
        <div class="ca-field full">
          <label for="caRoad">Road *</label>
          <select id="caRoad" name="road_group_id">
            <option value="">— Select a road —</option>
            <?php foreach ($available as $rg): ?>
              <option value="<?= (int)$rg['id'] ?>"><?= $h($rg['canonical_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caLen">Total road length (metres) *</label>
          <input id="caLen" name="total_length" type="number" min="50" step="any" inputmode="decimal" placeholder="e.g. 1500">
          <div class="ca-hint">Minimum 50 m. Used to work out the segments.</div>
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caSeg">Standard segment length *</label>
          <select id="caSeg" name="segment_choice">
            <option value="100">100 meters</option>
            <option value="200">200 meters</option>
            <option value="300">300 meters</option>
            <option value="500" selected>500 meters</option>
            <option value="custom">Custom length…</option>
          </select>
          <div id="caCustomWrap" style="display:none;margin-top:8px">
            <input name="segment_custom" type="number" min="10" step="any" inputmode="decimal" placeholder="e.g. 150">
          </div>
          <div class="ca-err"></div>
        </div>
        <div class="full ca-preview" id="caPreview">
          <div><span>Estimated segments</span><b id="caPvCount">–</b></div>
          <div><span>Each segment</span><b id="caPvEach">–</b></div>
          <div><span>Last segment</span><b id="caPvLast">–</b></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">+ Generate &amp; Save Segments</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showReview): ?>
    <div class="card" id="caReview">
      <div class="card-head">
        <h3>Review &amp; close <a class="cd-back" style="margin:0 0 0 10px" href="city_audit_report.php?id=<?= (int)$audit['id'] ?>">View full report →</a></h3>
        <?php if ($canReview): ?>
          <button class="ca-btn" type="button" id="caClose" <?= $closeBlock === null ? '' : 'disabled' ?>>Close Audit</button>
        <?php elseif ($audit['status'] === 'finalised' && !$isNational): ?>
          <button class="ca-btn" type="button" id="caSend">Send to Admin</button>
        <?php endif; ?>
      </div>
      <div class="cd-chips" style="margin:0 0 4px">
        <span class="cd-chip ok"><?= (int)$rv['approved'] ?> approved</span>
        <span class="cd-chip review"><?= (int)$rv['submitted'] ?> waiting for review</span>
        <span class="cd-chip back"><?= (int)$rv['needs_revisit'] ?> sent back</span>
        <span class="cd-chip"><?= (int)$rv['assigned'] ?> still with surveyors</span>
        <span class="cd-chip">of <?= (int)$rv['total'] ?> segments</span>
      </div>
      <?php if ($canReview && $closeBlock !== null): ?>
        <p class="ca-hint-line">Close Audit unlocks when every segment is approved. <?= $h($closeBlock) ?></p>
      <?php elseif ($audit['status'] === 'finalised'): ?>
        <p class="ca-hint-line">This audit is closed and its report is ready below. Send it to the Admin for approval.</p>
      <?php elseif ($audit['status'] === 'awaiting_approval'): ?>
        <p class="ca-hint-line"><?= $isNational ? 'Waiting for your approval. Open the report to approve or return it.' : 'Sent to the Admin. Waiting for approval.' ?></p>
      <?php elseif ($audit['status'] === 'published'): ?>
        <p class="ca-hint-line">Approved by the Admin. The report is final.</p>
      <?php endif; ?>
      <?php if ($wasReturned): ?>
        <div class="rp-returned">
          <b>The Admin sent this audit back for changes.</b>
          <p class="rp-returned-note"><?= $h($adminNote) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($submitted): ?>
        <h4 class="ca-sub">Waiting for your review</h4>
        <?php foreach ($submitted as $sb): ?>
          <?php $sc = $segScore(isset($sb['latest_audit_id']) ? (int)$sb['latest_audit_id'] : null); $d = $sb['data']; ?>
          <div class="ca-sub-card" data-segment-id="<?= (int)$sb['segment_id'] ?>">
            <div class="ca-sub-head">
              <div>
                <b><a href="city_segment_review.php?audit_id=<?= (int)$audit['id'] ?>&amp;segment_id=<?= (int)$sb['segment_id'] ?>"><?= $h($sb['road_name']) ?> · Segment <?= (int)$sb['segment_number'] ?></a></b>
                <small><?= $h($num($sb['length'])) ?> m · by <?= $h($sb['surveyor_name'] ?? 'Unknown') ?><?= $sb['submitted_at'] ? ' · ' . $h(cityLocalTime($sb['submitted_at'])) : '' ?></small>
              </div>
              <?php if ($sc !== null && isset($sc['final'])): ?>
                <span class="ca-score">Score <?= $h($num($sc['final'])) ?></span>
              <?php endif; ?>
            </div>
            <div class="ca-sub-data">
              <?php foreach ([
                'Cycle track missing' => $d['cycle_track_missing'] ?? null,
                'Cyclist use'         => $d['cyclist_use'] ?? null,
                'Surface'             => $d['surface_material'] ?? null,
                'Width (m)'           => $d['segment_width'] ?? null,
                'Shade'               => $d['shade'] ?? null,
                'Buffer zone'         => $d['buffer_zone'] ?? null,
                'Signage count'       => $d['signage_count'] ?? null,
              ] as $label => $val): ?>
                <span><?= $h($label) ?>: <b><?= ($val === null || $val === '') ? '—' : $h($val) ?></b></span>
              <?php endforeach; ?>
            </div>
            <?php if (!empty($d['comments'])): ?><p class="ca-sub-note">Surveyor's comments: <?= $h($d['comments']) ?></p><?php endif; ?>
            <?php if ($canReview): ?>
            <div class="ca-sub-actions">
              <?php if (cityAnswersRecorded($d)): ?>
              <button class="ca-btn ca-approve" type="button" data-segment-id="<?= (int)$sb['segment_id'] ?>">Approve</button>
              <?php else: ?>
              <button class="ca-btn ca-approve" type="button" disabled title="No answers were recorded. Send it back for a re-audit.">Approve</button>
              <?php endif; ?>
              <a class="ca-btn ghost" href="city_segment_review.php?audit_id=<?= (int)$audit['id'] ?>&amp;segment_id=<?= (int)$sb['segment_id'] ?>">Full review</a>
              <button class="ca-btn ghost ca-sendback-open" type="button">Send back…</button>
            </div>
            <div class="ca-sendback" style="display:none">
              <textarea class="ca-note" rows="2" maxlength="500" placeholder="What does the surveyor need to fix? (required)"></textarea>
              <select class="ca-seg-select ca-new-surveyor" aria-label="Surveyor for the re-audit">
                <option value="">Same surveyor (<?= $h($sb['surveyor_name'] ?? 'Unknown') ?>)</option>
                <?php foreach ($review->citySurveyors((int)$audit['city_id'], $auditLeaderId) as $sv): ?>
                  <?php if ((int)$sv['id'] !== (int)$sb['surveyor_id']): ?>
                    <option value="<?= (int)$sv['id'] ?>">Assign to <?= $h($sv['name']) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <button class="ca-btn danger ca-sendback" type="button" data-segment-id="<?= (int)$sb['segment_id'] ?>">Send back for re-audit</button>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php elseif ($canReview): ?>
        <p class="rd-empty">Nothing is waiting for review right now.</p>
      <?php endif; ?>

      <?php if ($sentBack): ?>
        <h4 class="ca-sub">Sent back for re-audit</h4>
        <?php foreach ($sentBack as $sbk): ?>
          <p class="ca-sub-line"><b><?= $h($sbk['road_name']) ?> · Segment <?= (int)$sbk['segment_number'] ?></b>
            with <?= $h($sbk['surveyor_name'] ?? 'Unknown') ?> — <?= $h($sbk['review_note'] ?? '') ?></p>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($isClosed): ?>
        <h4 class="ca-sub">Audit report</h4>
        <?php if ($overall): ?>
        <div class="cx-score">
          <div class="rv-score cond-<?= $h(cityConditionClass($overall['condition'])) ?>"><b><?= $h($num($overall['score'])) ?></b><span><?= $h($overall['condition']) ?></span></div>
          <div class="rv-bars">
            <?php foreach ($overall['bars'] as $bl => $bv): ?>
            <div class="rv-bar-row"><span><?= $h($bl) ?></span><div class="cd-bar"><i class="<?= $h($barClass((float)$bv)) ?>" style="width:<?= (int)max(0, min(100, round((float)$bv))) ?>%"></i></div><b><?= $h($num($bv)) ?></b></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php $cc = cityConditionCounts($roadConds); if (count($roadConds) > 1): ?>
        <div class="rp-legend" style="margin-bottom:8px">
          <?php foreach ($cc as $cn => $cv): if ($cv > 0): ?><span class="<?= $h(cityConditionClass($cn)) ?>"><?= $h($cn) ?>: <?= $h(cityPlural((int)$cv, 'road', 'roads')) ?></span><?php endif; endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="rd-scroll"><table class="rd-table">
          <thead><tr><th>Road</th><th>Segments</th><th>Score</th><th>Safety</th><th>Continuity</th><th>Comfort</th><th>Condition</th></tr></thead>
          <tbody>
          <?php foreach ($roads as $r1): $rs = $roadScores[(int)$r1['id']] ?? null; ?>
            <tr>
              <td><?= $h($r1['name']) ?></td>
              <td><?= count($r1['segments']) ?></td>
              <?php if ($rs): ?>
                <td><b><?= $h($num($rs['score'])) ?></b></td><td><?= $h($num($rs['safety_score'])) ?></td>
                <td><?= $h($num($rs['continuity_score'])) ?></td><td><?= $h($num($rs['comfort_score'])) ?></td>
                <td><span class="rp-cond <?= $h(cityConditionClass($rs['condition'])) ?>"><?= $h($rs['condition']) ?></span></td>
              <?php else: ?><td colspan="5">Not available</td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card" id="caRoads">
      <div class="card-head"><h3>Roads in this audit (<?= count($roads) ?>)</h3></div>
      <?php if (!$roads): ?>
        <div class="cx-empty"><?= cxIcon('road') ?><b>No roads yet</b><?= $canEdit ? 'Add the first road using the form above.' : 'No roads have been added to this audit.' ?></div>
      <?php endif; ?>
      <?php foreach ($roads as $r): ?>
        <?php
          $rAssigned = 0; $rPending = 0;
          foreach ($r['segments'] as $s0) {
              if ($s0['assigned_to'] !== null) { $rAssigned++; }
              if ($s0['status'] === 'pending') { $rPending++; }
          }
        ?>
        <div class="ca-road" data-road-id="<?= (int)$r['id'] ?>">
          <div class="ca-road-head">
            <div>
              <h4><?= $h($r['name']) ?></h4>
              <small><?= $h($num($r['total_length'])) ?> m · <?= count($r['segments']) ?> segments of <?= $h($num($r['segment_length'])) ?> m · <?= $rAssigned ?> of <?= count($r['segments']) ?> assigned</small>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <?php if ($canEdit): ?>
                <button class="ca-btn danger ca-remove" type="button"
                        data-road-id="<?= (int)$r['id'] ?>" data-name="<?= $h($r['name']) ?>">Remove</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="ca-road-body">
            <?php if ($canAssign && $surveyors && $rPending > 1): ?>
            <div class="ca-road-bulk">
              <span>Give all pending segments of this road to one surveyor:</span>
              <select class="ca-seg-select ca-road-surveyor" aria-label="Surveyor for all pending segments of <?= $h($r['name']) ?>">
                <option value="">— Choose surveyor —</option>
                <?php foreach ($surveyors as $sv): ?>
                  <option value="<?= (int)$sv['id'] ?>"><?= $h($sv['name']) ?></option>
                <?php endforeach; ?>
                <option value="0">Unassign all</option>
              </select>
              <button class="ca-btn ghost ca-assign-road" type="button" data-road-id="<?= (int)$r['id'] ?>">Apply to all</button>
            </div>
            <?php endif; ?>
            <?php if ($canEdit && $r['finalized_at'] === null): ?>
            <div class="ca-road-bulk ca-seg-addbar">
              <span>Add a segment to the end of this road:</span>
              <input type="number" class="ca-seg-select ca-seg-add-len" min="10" step="any" inputmode="decimal"
                     value="<?= $h($num($r['segment_length'] ?? 100)) ?>" aria-label="Length of the new segment in metres" style="width:96px"> m
              <button class="ca-btn ghost ca-seg-add" type="button" data-road-id="<?= (int)$r['id'] ?>" data-name="<?= $h($r['name']) ?>">+ Add segment</button>
            </div>
            <?php endif; ?>
            <div class="rd-scroll">
              <table class="rd-table">
                <thead><tr><th>#</th><th>From</th><th>To</th><th>Length</th><th>Status</th><th>Surveyor</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($r['segments'] as $s): ?>
                  <tr>
                    <td><?= (int)$s['segment_number'] ?></td>
                    <td><?= $h($num($s['start_distance'])) ?> m</td>
                    <td><?= $h($num($s['end_distance'])) ?> m</td>
                    <td><?= $h($num($s['length'])) ?> m</td>
                    <td><span class="cx-pill st-<?= $h($s['status']) ?>"><?= $h(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></span></td>
                    <td>
                    <?php if ($canAssign && $s['status'] === 'pending'): ?>
                      <?php $cur = $s['assigned_to'] === null ? '' : (string)(int)$s['assigned_to']; $known = false; ?>
                      <select class="ca-seg-select ca-seg-assign" data-segment-id="<?= (int)$s['segment_id'] ?>" data-current="<?= $h($cur) ?>"
                              aria-label="Surveyor for segment <?= (int)$s['segment_number'] ?>">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($surveyors as $sv): ?>
                          <?php if ((string)$sv['id'] === $cur) { $known = true; } ?>
                          <option value="<?= (int)$sv['id'] ?>" <?= (string)$sv['id'] === $cur ? 'selected' : '' ?>><?= $h($sv['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($cur !== '' && !$known): ?>
                          <option value="<?= $h($cur) ?>" selected><?= $h($s['assigned_name'] ?? 'Unknown') ?> (not in your team or inactive)</option>
                        <?php endif; ?>
                      </select>
                    <?php else: ?>
                      <?= $s['assigned_name'] !== null ? $h($s['assigned_name']) : '—' ?>
                    <?php endif; ?>
                    </td>
                    <?php if ($canEdit): ?>
                    <td>
                      <?php if ($s['status'] === 'pending'): ?>
                        <button class="ca-btn danger ca-seg-remove" type="button" data-segment-id="<?= (int)$s['segment_id'] ?>"
                                data-no="<?= (int)$s['segment_number'] ?>" data-name="<?= $h($r['name']) ?>">Delete</button>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</main>
<?php if ($isNational): ?>
<div class="ca-modal-bg" id="apEditBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="apEditTitle">
    <h3 id="apEditTitle">Edit project</h3>
    <form id="apEditForm" class="ca-form" novalidate>
      <div class="ca-field">
        <label for="apName">Project name *</label>
        <input id="apName" name="name" type="text" maxlength="150" value="<?= $h($audit['name']) ?>">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field">
        <label for="apDate">Audit date *</label>
        <input id="apDate" name="audit_date" type="date" min="2000-01-01" max="2100-12-31" value="<?= $h($audit['audit_date'] ?? '') ?>">
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="apInfo">Programme info</label>
        <textarea id="apInfo" name="programme_info" maxlength="2000"><?= $h($audit['programme_info'] ?? '') ?></textarea>
        <div class="ca-err"></div>
      </div>
      <div class="ca-field full">
        <label for="apLeader">City Leader for this project</label>
        <select id="apLeader" name="leader_user_id">
          <option value=""><?= $audit['leader_user_id'] === null ? '— Not assigned —' : '— Keep ' . $h($audit['leader_name']) . ' —' ?></option>
          <?php foreach ($adminLeaders as $cu): ?>
            <?php if ((int)$cu['id'] === (int)$audit['leader_user_id']) { continue; } ?>
            <option value="<?= (int)$cu['id'] ?>"><?= $h($cu['name']) ?><?= $cu['email'] !== '' ? ' (' . $h($cu['email']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="ca-hint"><?php if (!$adminLeaders): ?>
            <?= $h($audit['city_name']) ?> has no City Leader yet. Make someone a City Leader on the Users page first.
          <?php else: ?>
            Only City Leaders of <?= $h($audit['city_name']) ?> are listed. The chosen City Leader sees this project and assigns its surveyors; other City Leaders in the city do not see it.
          <?php endif; ?></div>
      </div>
      <div class="ca-hint full">The city (<?= $h($audit['city_name']) ?>) and state cannot be changed here. Changes show on the City Leader’s and surveyors’ dashboards the next time they load.</div>
      <div class="ca-modal-actions">
        <button type="button" class="ca-btn ghost" id="apEditCancel">Cancel</button>
        <button type="submit" class="ca-btn" id="apEditSave">Save changes</button>
      </div>
    </form>
  </div>
</div>
<div class="ca-modal-bg" id="apDeleteBg" hidden>
  <div class="ca-modal ap-modal" role="dialog" aria-modal="true" aria-labelledby="apDelTitle">
    <h3 id="apDelTitle">Delete this project?</h3>
    <p>This stops all work on <b><?= $h($audit['name']) ?></b> straight away:
      <?= (int)$adminImpact['roads'] ?> <?= $adminImpact['roads'] === 1 ? 'road' : 'roads' ?>,
      <?= (int)$adminImpact['segments'] ?> <?= $adminImpact['segments'] === 1 ? 'segment' : 'segments' ?>
      (<?= (int)$adminImpact['started'] ?> already worked on) and
      <?= (int)$adminImpact['surveyors'] ?> <?= $adminImpact['surveyors'] === 1 ? 'surveyor' : 'surveyors' ?>.
      It disappears from every dashboard, and the City Leader and surveyors are told. The audit data stays in the database but cannot be opened from the app.</p>
    <div class="ca-field">
      <label for="apDelConfirm">Type the project name to confirm</label>
      <input id="apDelConfirm" type="text" autocomplete="off" placeholder="<?= $h($audit['name']) ?>" data-name="<?= $h($audit['name']) ?>">
      <div class="ca-err"></div>
    </div>
    <div class="ca-field">
      <label for="apDelReason">Why are you deleting it? The City Leader and surveyors will see this.</label>
      <textarea id="apDelReason" rows="3" maxlength="200" placeholder="For example: duplicate of another project"></textarea>
      <div class="ca-err"></div>
    </div>
    <div class="ca-modal-actions">
      <button type="button" class="ca-btn ghost" id="apDelCancel">Cancel</button>
      <button type="button" class="ca-btn ca-btn-danger" id="apDelGo" disabled>Delete project</button>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="ca-toast" id="caToast"></div>
<div class="sb-overlay" id="sb-overlay"></div>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
<script nonce="<?= $nonce ?>" src="../js/confirm_box.js?v=<?= filemtime(__DIR__ . '/../js/confirm_box.js') ?>"></script>
<script nonce="<?= $nonce ?>" src="../js/city_audit.js?v=<?= filemtime(__DIR__ . '/../js/city_audit.js') ?>"></script>
<?php if ($isNational): ?>
<script nonce="<?= $nonce ?>" src="../js/admin_project.js?v=<?= filemtime(__DIR__ . '/../js/admin_project.js') ?>"></script>
<?php endif; ?>
<script nonce="<?= $nonce ?>" src="../js/project_watch.js?v=<?= filemtime(__DIR__ . '/../js/project_watch.js') ?>" data-audit-id="<?= (int)$audit['id'] ?>" data-home="<?= $isNational ? 'platform_dashboard.php?notice=project_deleted_ok' : 'city_dashboard.php?notice=project_deleted' ?>"></script>
</body>
</html>
