<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/reports/export-pdf.php
//  GET ?session_id=<id>
//
//  Generates and streams a PDF audit report using mPDF.
//  Mirrors the structure of pages/report.php but as a downloadable PDF.
//
//  Dependencies:
//    composer require mpdf/mpdf
//
//  Output:
//    Content-Disposition: attachment; filename="CycleAudit-<RoadName>-<Date>.pdf"
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../services/ScoreService.php';

require_once __DIR__ . '/../../config/rate_limit.php';

$rl = checkAndRecordApiRequest($pdo, 'user:' . $CURRENT_USER_ID, 'export_pdf', 5, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}
// vendor/autoload.php is already loaded via config/constants.php → no need to re-require.

// ── Validate input ────────────────────────────────────────────
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($sessionId <= 0) {
    http_response_code(400);
    echo 'Invalid session_id.';
    exit;
}

// ── Fetch session + road + surveyor ──────────────────────────
$stmtSess = $pdo->prepare(
    'SELECT s.*, r.name AS road_name, r.total_length,
            r.start_point, r.end_point, c.name AS city_name,
            u.name AS surveyor_name, u.public_id AS surveyor_public_id,
            u.organisation, u.email AS surveyor_email
     FROM   audit_sessions s
     JOIN   roads r ON r.id = s.road_id
     LEFT JOIN road_groups rg ON rg.id = r.road_group_id
     LEFT JOIN cities c ON c.id = rg.city_id
     JOIN   users u ON u.id = s.user_id
     WHERE  s.id = ? AND s.user_id = ?
     LIMIT  1'
);
$stmtSess->execute([$sessionId, $CURRENT_USER_ID]);
$session = $stmtSess->fetch(PDO::FETCH_ASSOC);

if ($session === false) {
    http_response_code(403);
    echo 'Session not found or access denied.';
    exit;
}

$roadId   = (int)$session['road_id'];
$roadName = $session['road_name'] ?? 'Unknown Road';

// ── Fetch segments + latest audit per segment ─────────────────
$stmtSegs = $pdo->prepare(
    'SELECT s.id, s.segment_number, s.start_label, s.end_label,
            s.length, s.status,
            sa.id AS audit_id
     FROM   segments s
     LEFT JOIN segment_audits sa
           ON  sa.id = (
                 SELECT id FROM segment_audits
                 WHERE  segment_id = s.id AND session_id = ?
                 ORDER  BY id DESC LIMIT 1
               )
     WHERE  s.road_id = ?
     ORDER  BY s.segment_number ASC'
);
$stmtSegs->execute([$sessionId, $roadId]);
$segs = $stmtSegs->fetchAll(PDO::FETCH_ASSOC);

$totalSegs     = count($segs);
$completedSegs = array_filter($segs, fn($s) => $s['status'] === 'completed');
$allDone       = count($completedSegs) === $totalSegs && $totalSegs > 0;
$roadScore     = $allDone ? calculateRoadScore($roadId, $pdo) : null;

// ── Pre-compute scores ────────────────────────────────────────
$segResults = [];
foreach ($segs as $seg) {
    $aid = $seg['audit_id'] ? (int)$seg['audit_id'] : null;
    $segResults[(int)$seg['id']] = $aid ? calculateSegmentScore($aid, $pdo) : null;
}

// ── Average dimension scores ──────────────────────────────────
$safetyAvg = $contAvg = $comfAvg = 0.0;
$n = 0;
foreach ($segResults as $sr) {
    if (!$sr) continue;
    $safetyAvg += $sr['safety_score'];
    $contAvg   += $sr['continuity_score'];
    $comfAvg   += $sr['comfort_score'];
    $n++;
}
if ($n > 0) {
    $safetyAvg = round($safetyAvg / $n, 1);
    $contAvg   = round($contAvg   / $n, 1);
    $comfAvg   = round($comfAvg   / $n, 1);
}

// ── Per-segment audit details ─────────────────────────────────
$segDetails = [];
foreach ($segs as $seg) {
    if (!$seg['audit_id']) continue;
    $sa = $pdo->prepare(
        'SELECT surface_material, buffer_zone, light_after_sunset,
                shade, cycle_track_missing, missing_length,
                people_walking, cyclist_use, better_surface
         FROM segment_audits WHERE id = ? LIMIT 1'
    );
    $sa->execute([$seg['audit_id']]);
    $saRow = $sa->fetch(PDO::FETCH_ASSOC);

    $si = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage FROM intersections WHERE audit_id = ?'
    );
    $si->execute([$seg['audit_id']]);
    $intRows  = $si->fetchAll(PDO::FETCH_ASSOC);
    $intCount = 0; $noRamps = 0; $noSign = 0;
    foreach ($intRows as $ir) {
        $intCount++;
        if (($ir['off_ramp'] ?? '') === 'No Ramp' || ($ir['on_ramp'] ?? '') === 'No Ramp') $noRamps++;
        if (($ir['markings'] ?? '') === 'Absent' || ($ir['signage'] ?? '') === 'Absent') $noSign++;
    }

    $so = $pdo->prepare(
        'SELECT COALESCE(SUM(total_obstructions), 0)   AS total,
                COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(cyclist_slowed), 0)       AS slowed
         FROM obstructions WHERE audit_id = ?'
    );
    $so->execute([$seg['audit_id']]);
    $obsRow = $so->fetch(PDO::FETCH_ASSOC);

    $segDetails[(int)$seg['id']] = [
        'audit'          => $saRow,
        'intersections'  => $intCount,
        'no_ramps'       => $noRamps,
        'no_sign'        => $noSign,
        'obs_total'      => (int)($obsRow['total']   ?? 0),
        'obs_partial'    => (int)($obsRow['partial'] ?? 0),
        'cyclist_slowed' => (int)($obsRow['slowed']  ?? 0),
    ];
}

// ── Collect issues + recommendations ─────────────────────────
$criticalIssues = [];
$observations   = [];
$recommendations = [];
$missingTrackSegs = [];
$totalObsCount = 0;
$totalNoRamps  = 0;

foreach ($segs as $seg) {
    if (!$seg['audit_id']) continue;
    $sid = (int)$seg['id'];
    $det = $segDetails[$sid] ?? null;
    $sr  = $segResults[$sid] ?? null;
    if (!$det || !$sr) continue;
    $saRow  = $det['audit'];
    $segNum = (int)$seg['segment_number'];

    if (in_array($sr['rating'], ['Poor', 'Bad', 'Very Bad'])) {
        $criticalIssues[] = "Segment $segNum scored {$sr['final']}/100 ({$sr['rating']})";
    }

    $totalObsCount += $det['obs_total'];
    $totalNoRamps  += $det['no_ramps'];

    if (!empty($saRow['cycle_track_missing']) && $saRow['cycle_track_missing'] === 'Yes') {
        $missingLen = (float)($saRow['missing_length'] ?? 0);
        $missingTrackSegs[] = "Segment $segNum";
        $lenStr = $missingLen > 0 ? " ({$missingLen}m)" : '';
        $observations[] = "⚠ Segment $segNum: Cycle track section missing{$lenStr}";
        $criticalIssues[] = "Missing cycle track in Segment $segNum";
    }
    if ($det['no_ramps'] > 0) {
        $observations[] = "↑ Segment $segNum: {$det['no_ramps']} intersection(s) missing ramps";
    }
    if ($det['no_sign'] > 0) {
        $observations[] = "↑ Segment $segNum: {$det['no_sign']} intersection(s) missing markings/signage";
    }
    if ($det['obs_total'] > 10) {
        $observations[] = "↑ Segment $segNum: High obstruction count ({$det['obs_total']})";
    }
}

if ($totalObsCount > 0)
    $recommendations[] = 'Conduct regular maintenance and remove all obstructions from the cycle track.';
if ($totalNoRamps > 0)
    $recommendations[] = 'Build on/off ramps at all intersection points for smooth cyclist transitions.';
if (!empty($missingTrackSegs))
    $recommendations[] = 'Construct missing cycle track sections to restore full network continuity.';
if ($safetyAvg < 50)
    $recommendations[] = 'Install buffer zones or physical separators between cycle track and traffic.';
if ($contAvg < 65)
    $recommendations[] = 'Add clear markings and signage throughout the cycle track.';
if ($comfAvg < 50)
    $recommendations[] = 'Enforce no-encroachment rules and remove obstructions from the cycle track.';
if ($safetyAvg < 40)
    $recommendations[] = 'Improve after-sunset lighting along the full length of the cycle track.';

// ── Helpers ───────────────────────────────────────────────────
function pdfConditionColor(string $condition): string {
    return match ($condition) {
        'Good'     => '#27ae60',
        'OK'       => '#e6a817',
        'Poor'     => '#e67e22',
        'Bad'      => '#e74c3c',
        'Very Bad' => '#8e1010',
        default    => '#888888',
    };
}

function pdfScoreBar(float $score, string $color, int $width = 180): string {
    $fill = max(0, min(100, $score));
    $px   = round($fill / 100 * $width);
    return "
        <table style='border-collapse:collapse;'>
          <tr>
            <td style='padding:0;'>
              <div style='width:{$width}px;height:8px;background:#e8e8e8;border-radius:4px;overflow:hidden;'>
                <div style='width:{$px}px;height:8px;background:{$color};border-radius:4px;'></div>
              </div>
            </td>
            <td style='padding:0 0 0 6px;font-size:10px;color:#555;white-space:nowrap;'>{$fill}/100</td>
          </tr>
        </table>";
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$printDate = date('d M Y');
$logoPath  = __DIR__ . '/../../assets/parisar-logo.png';
$logoB64   = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';

// ── Build HTML ────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

  /* ── Header ── */
  .pdf-header {
    background: linear-gradient(135deg, #1a3d0a 0%, #2d5c15 60%, #3d7a1f 100%);
    color: #fff; padding: 22px 28px 18px; margin-bottom: 0;
  }
  .pdf-header-top { display: flex; justify-content: space-between; align-items: flex-start; }
  .pdf-org { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; opacity: .75; margin-bottom: 4px; }
  .pdf-title { font-size: 20px; font-weight: 700; letter-spacing: -.3px; line-height: 1.2; }
  .pdf-sub { font-size: 10px; opacity: .8; margin-top: 4px; }
  .pdf-logo { width: 52px; height: 52px; border-radius: 8px; overflow: hidden; background: rgba(255,255,255,.15); padding: 4px; }
  .pdf-logo img { width: 100%; height: 100%; object-fit: contain; }

  /* ── Meta bar ── */
  .pdf-meta {
    background: #f5f9f0; border-bottom: 2px solid #d4e8c0;
    padding: 10px 28px; display: flex; flex-wrap: wrap; gap: 0;
  }
  .pdf-meta-item { flex: 0 0 25%; padding: 4px 0; }
  .pdf-meta-item .k { font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #6b8f4a; font-weight: 700; }
  .pdf-meta-item .v { font-size: 10px; font-weight: 600; color: #1a3d0a; margin-top: 1px; }

  /* ── Score hero ── */
  .score-hero {
    background: #fff; border: 2px solid #e0eed0;
    border-radius: 10px; padding: 18px 24px;
    margin: 16px 28px; display: flex; align-items: center; gap: 24px;
  }
  .score-badge { text-align: center; padding: 12px 20px; border-radius: 8px; border: 3px solid #ccc; min-width: 90px; }
  .score-badge .num { font-size: 34px; font-weight: 800; line-height: 1; }
  .score-badge .denom { font-size: 12px; color: #888; }
  .score-rating { font-size: 18px; font-weight: 800; margin-top: 4px; text-align: center; }
  .score-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #888; text-align: center; margin-top: 2px; }
  .dim-scores { display: flex; gap: 16px; flex: 1; }
  .dim-card { flex: 1; text-align: center; padding: 10px; background: #fafafa; border-radius: 8px; border: 1px solid #eee; }
  .dim-card .dim-icon { font-size: 16px; margin-bottom: 3px; }
  .dim-card .dim-val { font-size: 16px; font-weight: 800; }
  .dim-card .dim-lbl { font-size: 9px; color: #888; text-transform: uppercase; letter-spacing: .5px; }

  /* ── Section headings ── */
  .section-h {
    font-size: 11px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .8px; color: #1a3d0a;
    border-left: 4px solid #3d7a1f; padding: 4px 0 4px 10px;
    margin: 18px 28px 10px;
  }

  /* ── Segments table ── */
  .seg-table { width: calc(100% - 56px); margin: 0 28px; border-collapse: collapse; font-size: 10px; }
  .seg-table th {
    background: #1a3d0a; color: #fff; padding: 6px 8px;
    text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .5px;
  }
  .seg-table td { padding: 6px 8px; border-bottom: 1px solid #e8f0df; vertical-align: middle; }
  .seg-table tr:nth-child(even) td { background: #f8fbf4; }
  .pill {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: 9px; font-weight: 700;
  }

  /* ── Detail cards ── */
  .seg-cards { margin: 0 28px; }
  .seg-card {
    border: 1px solid #dde8cc; border-radius: 8px;
    margin-bottom: 12px; overflow: hidden; page-break-inside: avoid;
  }
  .seg-card-header { padding: 10px 14px; border-bottom: 1px solid #dde8cc; }
  .seg-card-title { font-size: 11px; font-weight: 800; color: #1a3d0a; }
  .seg-card-sub { font-size: 9px; color: #6b8f4a; margin-top: 2px; }
  .seg-card-body { display: flex; }
  .seg-card-dims { display: flex; gap: 0; border-bottom: 1px solid #eee; }
  .seg-dim { flex: 1; text-align: center; padding: 8px 4px; border-right: 1px solid #eee; }
  .seg-dim:last-child { border-right: none; }
  .seg-dim .val { font-size: 14px; font-weight: 800; }
  .seg-dim .lbl { font-size: 8px; color: #888; text-transform: uppercase; }
  .seg-card-details { padding: 8px 14px; }
  .detail-grid { display: flex; flex-wrap: wrap; gap: 0; }
  .detail-item { flex: 0 0 50%; padding: 3px 0; }
  .detail-item .dk { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: .3px; }
  .detail-item .dv { font-size: 10px; font-weight: 600; color: #1a1a1a; }

  /* ── Observations & recs ── */
  .obs-list, .rec-list { margin: 0 28px; padding-left: 16px; font-size: 10px; }
  .obs-list li, .rec-list li { margin-bottom: 4px; line-height: 1.5; }

  /* ── Critical issues ── */
  .critical-box { margin: 0 28px; background: #fff5f5; border: 1px solid #fcc; border-radius: 6px; padding: 10px 14px; }
  .critical-item { font-size: 10px; color: #c0392b; padding: 2px 0; }
  .critical-item::before { content: '• '; font-weight: 700; }

  /* ── Summary ── */
  .summary-box { margin: 0 28px; background: #f5f9f0; border-left: 4px solid #3d7a1f; padding: 10px 14px; border-radius: 0 6px 6px 0; font-size: 10px; line-height: 1.7; }

  /* ── Footer ── */
  .pdf-footer {
    background: #1a3d0a; color: rgba(255,255,255,.85);
    padding: 12px 28px; margin-top: 24px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .pdf-footer .fl { font-size: 9px; line-height: 1.7; }
  .pdf-footer .fr { font-size: 9px; line-height: 1.7; text-align: right; }
  .footer-brand { font-size: 11px; font-weight: 700; color: #fff; }

  /* ── Incomplete notice ── */
  .incomplete-notice {
    margin: 0 28px 12px; background: #fffbe6; border: 1px solid #f0d060;
    border-radius: 6px; padding: 8px 14px; font-size: 10px; color: #7a5a00;
  }

  /* ── Page break helper ── */
  .page-break { page-break-after: always; }
</style>
</head>
<body>

<!-- ══ HEADER ══════════════════════════════════════════════════ -->
<div class="pdf-header">
  <div class="pdf-header-top">
    <div>
      <div class="pdf-org">Parisar — Cycle Track Audit Programme<?= !empty($session['city_name']) ? ', ' . $e($session['city_name']) : '' ?></div>
      <div class="pdf-title"><?= $e($roadName) ?></div>
      <?php if ($session['start_point'] || $session['end_point']): ?>
      <div class="pdf-sub">
        <?= $e($session['start_point'] ?? '') ?>
        <?= ($session['start_point'] && $session['end_point']) ? ' → ' . $e($session['end_point']) : '' ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($logoB64): ?>
    <div class="pdf-logo"><img src="<?= $logoB64 ?>" alt="Parisar"></div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ META BAR ════════════════════════════════════════════════ -->
<div class="pdf-meta">
  <div class="pdf-meta-item">
    <div class="k">Surveyor</div>
    <div class="v"><?= $e($session['surveyor_name']) ?></div>
  </div>
  <?php if ($session['organisation']): ?>
  <div class="pdf-meta-item">
    <div class="k">Organisation</div>
    <div class="v"><?= $e($session['organisation']) ?></div>
  </div>
  <?php endif; ?>
  <div class="pdf-meta-item">
    <div class="k">Session ID</div>
    <div class="v"><?= $e($session['public_id']) ?></div>
  </div>
  <div class="pdf-meta-item">
    <div class="k">Total Length</div>
    <div class="v"><?= $session['total_length'] ? number_format((float)$session['total_length']) . ' m' : '—' ?></div>
  </div>
  <div class="pdf-meta-item">
    <div class="k">Segments</div>
    <div class="v"><?= count($completedSegs) ?>/<?= $totalSegs ?> completed</div>
  </div>
  <div class="pdf-meta-item">
    <div class="k">Report Date</div>
    <div class="v"><?= $printDate ?></div>
  </div>
  <div class="pdf-meta-item">
    <div class="k">Email</div>
    <div class="v"><?= $e($session['surveyor_email'] ?? '—') ?></div>
  </div>
</div>

<!-- ══ ROAD SCORE HERO ══════════════════════════════════════════ -->
<?php if ($roadScore):
    $rc = pdfConditionColor($roadScore['rating']); ?>
<div class="score-hero">
  <div>
    <div class="score-badge" style="border-color:<?= $rc ?>;background:<?= $rc ?>18;">
      <div class="num" style="color:<?= $rc ?>"><?= $roadScore['score'] ?></div>
      <div class="denom">/ 100</div>
    </div>
    <div class="score-rating" style="color:<?= $rc ?>"><?= $e($roadScore['rating']) ?></div>
    <div class="score-label">Road Score</div>
  </div>
  <div class="dim-scores">
    <?php foreach ([
      ['🛡', 'Safety',      $safetyAvg, '#3498db'],
      ['🔗', 'Continuity',  $contAvg,   '#9b59b6'],
      ['🌿', 'Comfort',     $comfAvg,   '#e67e22'],
    ] as [$icon, $lbl, $val, $col]): ?>
    <div class="dim-card">
      <div class="dim-icon"><?= $icon ?></div>
      <div class="dim-val" style="color:<?= $col ?>"><?= $val ?></div>
      <div class="dim-lbl"><?= $lbl ?></div>
      <br>
      <?= pdfScoreBar($val, $col, 120) ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php elseif (!$allDone): ?>
<div class="incomplete-notice">
  ⏳ <?= $totalSegs - count($completedSegs) ?> segment(s) not yet audited —
  the road score will appear once all segments are complete.
</div>
<?php endif; ?>

<!-- ══ §1 QUICK SUMMARY ══════════════════════════════════════════ -->
<div class="section-h">1. Quick Summary</div>
<div class="summary-box">
<?php
    $lenFmt   = number_format(array_sum(array_map(fn($s) => (float)$s['length'], $segs)));
    $scoreStr = $roadScore
        ? "{$roadScore['score']}/100, rated <strong>{$roadScore['rating']}</strong>"
        : '<em>pending — complete all segments</em>';
    $dimNames = ['Safety' => $safetyAvg, 'Continuity' => $contAvg, 'Comfort' => $comfAvg];
    $weakDim  = array_search(min($dimNames), $dimNames);
?>
  <strong><?= $e($roadName) ?></strong> was audited across
  <strong><?= $totalSegs ?> segment<?= $totalSegs !== 1 ? 's' : '' ?></strong>
  covering approximately <strong><?= $lenFmt ?> metres</strong> of cycle track.
  The overall road score is <?= $scoreStr ?>.
  <?php if ($weakDim): ?>
  <strong><?= $weakDim ?></strong> is the primary area of concern.
  <?php endif; ?>
</div>

<!-- ══ §2 SEGMENT-WISE SCORES ════════════════════════════════════ -->
<div class="section-h">2. Segment-wise Scores</div>
<table class="seg-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Route (from start)</th>
      <th>Length</th>
      <th>Safety</th>
      <th>Continuity</th>
      <th>Comfort</th>
      <th>Final Score</th>
      <th>Rating</th>
    </tr>
  </thead>
  <tbody>
  <?php
    $cumLen = 0;
    foreach ($segs as $seg):
      $sr   = $segResults[(int)$seg['id']] ?? null;
      $col  = $sr ? pdfConditionColor($sr['rating']) : '#aaa';
      $start = $cumLen;
      $end   = $cumLen + (float)$seg['length'];
      $cumLen = $end;
  ?>
    <tr>
      <td><strong><?= (int)$seg['segment_number'] ?></strong></td>
      <td><?= number_format($start) ?>m → <?= number_format($end) ?>m</td>
      <td><?= number_format((float)$seg['length']) ?> m</td>
      <?php if ($sr): ?>
      <td><?= $sr['safety_score'] ?></td>
      <td><?= $sr['continuity_score'] ?></td>
      <td><?= $sr['comfort_score'] ?></td>
      <td>
        <span class="pill" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44;">
          <?= $sr['final'] ?>
        </span>
      </td>
      <td style="color:<?= $col ?>;font-weight:700;"><?= $e($sr['rating']) ?></td>
      <?php else: ?>
      <td colspan="5" style="color:#aaa;font-style:italic;">Pending</td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- ══ §3 KEY OBSERVATIONS ══════════════════════════════════════ -->
<?php if (!empty($observations)): ?>
<div class="section-h">3. Key Observations</div>
<ul class="obs-list">
  <?php foreach ($observations as $obs): ?>
  <li><?= $e($obs) ?></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<!-- ══ §4 CRITICAL ISSUES ════════════════════════════════════════ -->
<?php if (!empty($criticalIssues)): ?>
<div class="section-h">4. Critical Issues</div>
<div class="critical-box">
  <?php foreach ($criticalIssues as $ci): ?>
  <div class="critical-item"><?= $e($ci) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ §5 RECOMMENDATIONS ═══════════════════════════════════════ -->
<?php if (!empty($recommendations)): ?>
<div class="section-h">5. Recommendations</div>
<ol class="rec-list">
  <?php foreach ($recommendations as $rec): ?>
  <li><?= $e($rec) ?></li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<!-- ══ §6 SEGMENT DETAIL CARDS ══════════════════════════════════ -->
<?php if (!empty($segDetails)): ?>
<div class="section-h">6. Segment Detail Cards</div>
<div class="seg-cards">
  <?php foreach ($segs as $seg):
    $sid  = (int)$seg['id'];
    $sr   = $segResults[$sid] ?? null;
    $det  = $segDetails[$sid] ?? null;
    if (!$sr || !$det) continue;
    $col  = pdfConditionColor($sr['rating']);
    $audit = $det['audit'];
  ?>
  <div class="seg-card">
    <div class="seg-card-header" style="background:<?= $col ?>12;border-left:4px solid <?= $col ?>;">
      <table width="100%" style="border-collapse:collapse;">
        <tr>
          <td>
            <div class="seg-card-title">
              Segment <?= (int)$seg['segment_number'] ?>
              <?php if ($seg['start_label'] || $seg['end_label']): ?>
                · <?= $e($seg['start_label'] ?? '') ?> → <?= $e($seg['end_label'] ?? '') ?>
              <?php endif; ?>
            </div>
            <div class="seg-card-sub">Length: <?= number_format((float)$seg['length']) ?>m</div>
          </td>
          <td align="right" style="white-space:nowrap;">
            <span style="font-size:18px;font-weight:800;color:<?= $col ?>;"><?= $sr['final'] ?>/100</span><br>
            <span style="font-size:10px;font-weight:700;color:<?= $col ?>;"><?= $e($sr['rating']) ?></span>
          </td>
        </tr>
      </table>
    </div>
    <!-- Dimension scores -->
    <table width="100%" style="border-collapse:collapse;border-bottom:1px solid #eee;">
      <tr>
        <?php foreach ([
          ['Safety',     $sr['safety_score'],     '#3498db'],
          ['Continuity', $sr['continuity_score'],  '#9b59b6'],
          ['Comfort',    $sr['comfort_score'],      '#e67e22'],
        ] as [$dn, $dv, $dc]): ?>
        <td style="text-align:center;padding:8px 4px;border-right:1px solid #eee;">
          <div style="font-size:14px;font-weight:800;color:<?= $dc ?>;"><?= $dv ?></div>
          <div style="font-size:8px;color:#888;text-transform:uppercase;"><?= $dn ?></div>
          <?= pdfScoreBar($dv, $dc, 100) ?>
        </td>
        <?php endforeach; ?>
      </tr>
    </table>
    <!-- Detail fields -->
    <div class="seg-card-details">
      <table width="100%" style="border-collapse:collapse;font-size:9.5px;">
        <?php
          $fields = [
            ['Surface',       $audit['surface_material']    ?? '—'],
            ['Buffer Zone',   $audit['buffer_zone']          ?? '—'],
            ['Lighting',      $audit['light_after_sunset']   ?? '—'],
            ['Shade',         $audit['shade']                ?? '—'],
            ['Track Missing', $audit['cycle_track_missing']  ?? '—'],
            ['Intersections', $det['intersections']],
            ['Obstructions',  $det['obs_total']],
            ['Missing Ramps', $det['no_ramps']],
          ];
          $rows = array_chunk($fields, 2);
          foreach ($rows as $row):
        ?>
        <tr>
          <?php foreach ($row as $f): ?>
          <td style="padding:3px 6px 3px 0;width:25%;color:#888;font-size:8px;text-transform:uppercase;"><?= $e($f[0]) ?></td>
          <td style="padding:3px 12px 3px 0;width:25%;font-weight:600;"><?= $e($f[1]) ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ FOOTER ════════════════════════════════════════════════════ -->
<div class="pdf-footer">
  <div class="fl">
    <div class="footer-brand">Parisar — Cycle Track Audit Programme</div>
    <div>parisar.org &nbsp;|&nbsp; Pune, Maharashtra</div>
    <div>Generated by CycleAudit</div>
  </div>
  <div class="fr">
    <div><?= $e($roadName) ?></div>
    <?php if ($roadScore): ?>
    <div>Score: <strong><?= $roadScore['score'] ?>/100</strong> (<?= $e($roadScore['rating']) ?>)</div>
    <?php endif; ?>
    <div>Printed <?= $printDate ?></div>
  </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ── Render PDF via mPDF ───────────────────────────────────────
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'margin_top'        => 0,
        'margin_bottom'     => 0,
        'margin_left'       => 0,
        'margin_right'      => 0,
        'setAutoTopMargin'  => false,
        'setAutoBottomMargin' => false,
        'tempDir'           => sys_get_temp_dir() . '/mpdf',
    ]);

    $mpdf->SetTitle("Cycle Track Audit — {$roadName}");
    $mpdf->SetAuthor('Parisar CycleAudit');
    $mpdf->SetCreator('CycleAudit by Parisar');

    $mpdf->WriteHTML($html);

    // Safe filename
    $safeName = preg_replace('/[^A-Za-z0-9\-_]/', '-', $roadName);
    $safeName = preg_replace('/-+/', '-', trim($safeName, '-'));
    $filename = 'CycleAudit-' . $safeName . '-' . date('Y-m-d') . '.pdf';

    ActivityLogger::log($pdo, ActivityLogger::EXPORT_DOWNLOADED, (int)$CURRENT_USER_ID, ['type' => 'road_report_pdf', 'road_id' => $roadId, 'session_id' => $sessionId]);
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);

} catch (\Exception $e) {
    error_log('PDF export error: ' . $e->getMessage());
    http_response_code(500);
    echo 'PDF generation failed. Please try again or use Print → Save as PDF.';
}
