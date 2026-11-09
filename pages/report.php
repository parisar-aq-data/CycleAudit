<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/report.php  (IMPROVED)
//  Browser report view — printable / save-as-PDF.
//  Mirrors the Parisar PDF report structure:
//    1. Header + meta
//    2. Final road score + dimension breakdown
//    3. Quick summary paragraph
//    4. Segment-wise scores table
//    5. Score breakdown by dimension (visual bars)
//    6. Key observations
//    7. Critical issues
//    8. Recommendations
//    9. Per-segment detail cards
//   10. Footer with Parisar branding + watermark
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../services/ScoreService.php';

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($sessionId <= 0) { header('Location: dashboard.php'); exit; }

// ── Fetch session + road + surveyor ───────────────────────────
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
if ($session === false) { header('Location: dashboard.php'); exit; }

$roadId = (int)$session['road_id'];

// ── Fetch segments + latest audit per segment ──────────────────
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

// Pre-compute scores for all audited segments
$segResults = [];
foreach ($segs as $seg) {
    $aid = $seg['audit_id'] ? (int)$seg['audit_id'] : null;
    $segResults[(int)$seg['id']] = $aid
        ? calculateSegmentScore($aid, $pdo)
        : null;
}

// Average dimension scores
$safetyAvg = $contAvg = $comfAvg = 0.0; $n = 0;
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

// ── Fetch per-segment audit details for detail cards ──────────
$segDetails = [];
foreach ($segs as $seg) {
    if (!$seg['audit_id']) continue;
    $sa = $pdo->prepare(
        'SELECT surface_material, buffer_zone, light_after_sunset,
                shade, surface_issues, overhead_issues,
                cycle_track_missing, missing_length,
                people_walking, cyclist_use, better_surface,
                footpath_rating, footpath_score,
                track_geometry, signage_count AS seg_signage_count
         FROM segment_audits WHERE id = ? LIMIT 1'
    );
    $sa->execute([$seg['audit_id']]);
    $saRow = $sa->fetch(PDO::FETCH_ASSOC);

    // Intersections
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

    // Obstructions
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
        'signage_count'  => (int)($saRow['seg_signage_count'] ?? 0),
    ];
}

// ── Collect issues across all segments ────────────────────────
$allSurface = []; $allOverhead = [];
$observations = []; $criticalIssues = []; $poorSegs = [];
$totalObsCount = 0; $totalNoRamps = 0; $missingTrackSegs = [];

foreach ($segs as $seg) {
    if (!$seg['audit_id']) continue;
    $sid    = (int)$seg['id'];
    $det    = $segDetails[$sid]  ?? null;
    $sr     = $segResults[$sid]  ?? null;
    if (!$det || !$sr) continue;
    $saRow  = $det['audit'];
    $segNum = (int)$seg['segment_number'];

    $allSurface  = array_unique(array_merge(
        $allSurface,
        json_decode((string)($saRow['surface_issues']  ?? '[]'), true) ?: []
    ));
    $allOverhead = array_unique(array_merge(
        $allOverhead,
        json_decode((string)($saRow['overhead_issues'] ?? '[]'), true) ?: []
    ));

    if (in_array($sr['rating'], ['Poor','Bad','Very Bad'])) $poorSegs[] = "Segment $segNum";

    $totalObsCount += $det['obs_total'];
    $totalNoRamps  += $det['no_ramps'];

    if ($det['obs_total'] > 10)
        $observations[] = ['type'=>'warn', 'text'=>"Segment $segNum: High obstruction count ({$det['obs_total']} total)"];
    if (!empty($saRow['cycle_track_missing']) && $saRow['cycle_track_missing'] === 'Yes') {
        $missingLen = (float)($saRow['missing_length'] ?? 0);
        $missingTrackSegs[] = "Segment $segNum";
        $lenStr = $missingLen > 0 ? " ({$missingLen}m)" : '';
        $observations[]  = ['type'=>'bad',  'text'=>"Segment $segNum: Cycle track section missing{$lenStr}"];
        $criticalIssues[] = "Missing cycle track in Segment $segNum";
    }
    if ($det['no_ramps'] > 0)
        $observations[] = ['type'=>'warn', 'text'=>"Segment $segNum: {$det['no_ramps']} intersection(s) missing ramps"];
    if ($det['no_sign'] > 0)
        $observations[] = ['type'=>'warn', 'text'=>"Segment $segNum: {$det['no_sign']} intersection(s) missing markings/signage"];
}

// ── Build recommendations ──────────────────────────────────────
$recommendations = [];
if ($totalObsCount > 0)
    $recommendations[] = "Conduct regular maintenance and remove all obstructions from the cycle track.";
if ($totalNoRamps > 0)
    $recommendations[] = "Build on/off ramps at all intersection points for smooth cyclist transitions.";
if (!empty($missingTrackSegs))
    $recommendations[] = "Construct missing cycle track sections to restore full network continuity.";
if (!empty($allSurface))
    $recommendations[] = "Repair damaged or uneven surface sections to improve cycling comfort.";
if ($safetyAvg < 50)
    $recommendations[] = "Install buffer zones or physical separators between cycle track and motorised traffic.";
if ($contAvg < 65)
    $recommendations[] = "Add clear markings and signage throughout the cycle track for better visibility.";
if ($comfAvg < 50)
    $recommendations[] = "Enforce no-encroachment rules and remove parked vehicles or vendors from the cycle track.";
if ($safetyAvg < 40)
    $recommendations[] = "Improve after-sunset lighting along the full length of the cycle track.";

// ── Best & worst segments ──────────────────────────────────────
$bestSeg = null; $worstSeg = null; $bestScore = 999; $worstScore = -1;
foreach ($segs as $seg) {
    $sr = $segResults[(int)$seg['id']] ?? null;
    if (!$sr) continue;
    if ($sr['final'] < $bestScore)  { $bestScore  = $sr['final'];  $bestSeg  = $seg; }
    if ($sr['final'] > $worstScore) { $worstScore = $sr['final'];  $worstSeg = $seg; }
}

// ── Total audited length ───────────────────────────────────────
$auditedLength = 0;
foreach ($segs as $seg) {
    if ($segResults[(int)$seg['id']]) $auditedLength += (float)$seg['length'];
}

// ── Section counter ───────────────────────────────────────────
$secN = 0;
function secNum(): string { global $secN; return (string)(++$secN); }

// ── Date ──────────────────────────────────────────────────────
$printDate = date('d M Y');

// ── Parisar logo base64 (inline so print/PDF works) ──────────
$logoPath   = __DIR__ . '/../assets/parisar-logo.png';
$logoBase64 = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Report — <?= htmlspecialchars($session['road_name']) ?></title>
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/report.css">
</head>
<body>

<?php if ($logoBase64): ?>
<div class="watermark"><img src="<?= $logoBase64 ?>" alt=""></div>
<?php endif; ?>

<!-- ── Toolbar ──────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="toolbar-title">📄 <?= htmlspecialchars($session['road_name']) ?> — Audit Report</div>
  <div class="toolbar-btns">
    <a href="dashboard.php" class="tbtn tbtn-back">← Dashboard</a>
    <a href="../api/reports/export-excel.php?session_id=<?= $sessionId ?>" class="tbtn tbtn-excel">⬇ Excel</a>
    <button class="tbtn tbtn-print" data-report-action="print">🖨 Print / Save PDF</button>
  </div>
</div>

<div class="report">

  <!-- ══ HEADER ═══════════════════════════════════════════════ -->
  <div class="rpt-header">
    <div class="rpt-header-top">
      <div>
        <div class="rpt-org">Parisar — Cycle Track Audit Programme<?= !empty($session['city_name']) ? ', ' . htmlspecialchars($session['city_name']) : '' ?></div>
        <div class="rpt-title"><?= htmlspecialchars($session['road_name']) ?></div>
        <div class="rpt-sub">
          <?= htmlspecialchars($session['start_point'] ?? '') ?>
          <?= ($session['start_point'] && $session['end_point'])
                ? ' → ' . htmlspecialchars($session['end_point']) : '' ?>
        </div>
      </div>
      <?php if ($logoBase64): ?>
      <div class="rpt-logo">
        <img src="<?= $logoBase64 ?>" alt="Parisar">
        <div class="rpt-logo-text">parisar.org</div>
      </div>
      <?php endif; ?>
    </div>
    <div class="rpt-meta">
      <div class="rpt-meta-item">
        <div class="k">Surveyor</div>
        <div class="v"><?= htmlspecialchars($session['surveyor_name']) ?></div>
      </div>
      <?php if ($session['organisation']): ?>
      <div class="rpt-meta-item">
        <div class="k">Organisation</div>
        <div class="v"><?= htmlspecialchars($session['organisation']) ?></div>
      </div>
      <?php endif; ?>
      <div class="rpt-meta-item">
        <div class="k">Session ID</div>
        <div class="v"><?= htmlspecialchars($session['public_id']) ?></div>
      </div>
      <div class="rpt-meta-item">
        <div class="k">Total Length</div>
        <div class="v"><?= $session['total_length']
            ? number_format((float)$session['total_length']) . ' m' : '—' ?></div>
      </div>
      <div class="rpt-meta-item">
        <div class="k">Report Date</div>
        <div class="v"><?= $printDate ?></div>
      </div>
      <div class="rpt-meta-item">
        <div class="k">Segments</div>
        <div class="v"><?= count($completedSegs) ?>/<?= $totalSegs ?> completed</div>
      </div>
    </div>
  </div>

  <!-- ══ FINAL ROAD SCORE ══════════════════════════════════════ -->
  <?php if ($roadScore):
    $rc = ratingColour($roadScore['rating']); ?>
  <div class="score-hero">
    <div class="final-score-wrap">
      <div class="score-badge" style="border-color:<?= $rc ?>;background:<?= $rc ?>18;">
        <div class="num"  style="color:<?= $rc ?>"><?= $roadScore['score'] ?></div>
        <div class="denom">/ 100</div>
      </div>
      <div class="score-rating" style="color:<?= $rc ?>"><?= $roadScore['rating'] ?></div>
      <div class="score-label">Road Score</div>
    </div>
    <div class="dim-scores">
      <?php foreach ([
        ['🛡', 'Safety',     $safetyAvg, 'var(--safety-c)'],
        ['🔗', 'Continuity', $contAvg,   'var(--cont-c)'],
        ['🌿', 'Comfort',    $comfAvg,   'var(--comf-c)'],
      ] as [$icon, $dl, $dv, $dc]): ?>
      <div class="dim-card">
        <div class="dim-icon"><?= $icon ?></div>
        <div class="dim-val" style="color:<?= $dc ?>"><?= $dv ?></div>
        <div class="dim-lbl"><?= $dl ?></div>
        <div class="dim-bar">
          <div class="dim-fill" style="width:<?= $dv ?>%;background:<?= $dc ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ BODY ══════════════════════════════════════════════════ -->
  <div class="rpt-body">

    <?php if (!$allDone): ?>
    <div class="incomplete-notice">
      <span>⏳</span>
      <span><?= $totalSegs - count($completedSegs) ?> segment(s) not yet audited.
      The final road score will appear once all segments are complete.</span>
    </div>
    <?php endif; ?>

    <!-- ── § Quick Summary ──────────────────────────────────── -->
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Quick Summary</div>
    <div class="summary-box">
      <?php
        $rn      = htmlspecialchars($session['road_name']);
        $lenFmt  = number_format($auditedLength);
        $scoreStr = $roadScore
          ? "{$roadScore['score']}/100, rated <strong>{$roadScore['rating']}</strong>"
          : "<em>pending — complete all segments</em>";
        $wStr = $worstSeg ? "Weakest segment is Segment {$worstSeg['segment_number']} ({$segResults[(int)$worstSeg['id']]['final']}/100)" : '';
        $bStr = $bestSeg  ? "strongest is Segment {$bestSeg['segment_number']} ({$segResults[(int)$bestSeg['id']]['final']}/100)"  : '';
        $minDimVal = min($safetyAvg, $contAvg, $comfAvg);
        $dimNames  = ['Safety'=>$safetyAvg,'Continuity'=>$contAvg,'Comfort'=>$comfAvg];
        $weakDim   = array_search($minDimVal, $dimNames);
      ?>
      <strong><?= $rn ?></strong> was audited across
      <strong><?= $totalSegs ?> segment<?= $totalSegs !== 1 ? 's' : '' ?></strong>
      covering approximately <strong><?= $lenFmt ?> metres</strong> of cycle track.
      The overall road score is <?= $scoreStr ?>.
      <?php if ($wStr && $bStr): ?>
        <?= ucfirst($wStr) ?>; <?= $bStr ?>.
      <?php endif; ?>
      <?php if ($weakDim): ?>
        <strong><?= $weakDim ?></strong> is the primary concern —
        <?= $weakDim === 'Comfort'
              ? 'surface quality, pedestrian conflict, and lack of shade reduce usability.'
          : ($weakDim === 'Safety'
              ? 'buffer zones, lighting, and obstruction density need attention.'
              : 'missing sections, ramps, and signage gaps disrupt route continuity.') ?>
      <?php endif; ?>
    </div>

    <!-- ── § Segment-wise Scores ─────────────────────────────── -->
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Segment-wise Scores</div>
    <table class="segs-table">
      <thead>
        <tr>
          <th>#</th><th>Route</th><th>Length</th>
          <th>Safety</th><th>Continuity</th><th>Comfort</th>
          <th>Final</th><th>Rating</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $cumLen = 0;
          foreach ($segs as $seg):
            $sr    = $segResults[(int)$seg['id']] ?? null;
            $col   = $sr ? ratingColour($sr['rating']) : '#aaa';
            $start = $cumLen;
            $end   = $cumLen + (float)$seg['length'];
            $cumLen = $end;
        ?>
        <tr>
          <td><strong><?= (int)$seg['segment_number'] ?></strong></td>
          <td class="segs-route"><?= number_format($start) ?>m → <?= number_format($end) ?>m</td>
          <td><?= number_format((float)$seg['length']) ?> m</td>
          <?php if ($sr): ?>
          <td><?= $sr['safety_score'] ?></td>
          <td><?= $sr['continuity_score'] ?></td>
          <td><?= $sr['comfort_score'] ?></td>
          <td>
            <span class="score-pill" style="background:<?= $col ?>22;color:<?= $col ?>">
              <?= $sr['final'] ?>
            </span>
          </td>
          <td style="color:<?= $col ?>;font-weight:700"><?= $sr['rating'] ?></td>
          <?php else: ?>
          <td colspan="5"><span class="pill-pending">⏳ Pending</span></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- ── § Score Breakdown by Dimension ─────────────────────── -->
    <?php if ($allDone && $n > 0): ?>
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Score Breakdown by Dimension</div>
    <div class="dim-breakdown-grid">
      <?php foreach ([
        ['🛡','Safety',     $safetyAvg,'var(--safety-c)',[
          'Buffer zone presence','After-sunset lighting',
          'Intersection traffic devices','Partial obstruction density',
        ]],
        ['🔗','Continuity', $contAvg,  'var(--cont-c)',[
          'Missing ramps at intersections','Absent markings and signage',
          'Total obstruction count','Missing track sections',
        ]],
        ['🌿','Comfort',    $comfAvg,  'var(--comf-c)',[
          'Surface material type','Cyclist slowed by obstructions',
          'Shade availability','Footpath quality rating',
        ]],
      ] as [$icon,$name,$val,$col,$factors]): ?>
      <div class="dim-breakdown-card">
        <div class="head"><span><?= $icon ?></span><span><?= $name ?></span></div>
        <div class="dim-big-score" style="color:<?= $col ?>"><?= $val ?> / 100</div>
        <div class="dim-breakdown-bar">
          <div class="dim-breakdown-fill" style="width:<?= $val ?>%;background:<?= $col ?>"></div>
        </div>
        <div class="dim-factors">
          <?php foreach ($factors as $f): ?>
            <span class="factor-bullet"><?= htmlspecialchars($f) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── § Key Observations ────────────────────────────────── -->
    <?php if (!empty($observations)): ?>
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Key Observations</div>
    <ul class="obs-list">
      <?php foreach ($observations as $obs): ?>
      <li class="<?= htmlspecialchars($obs['type']) ?>">
        <span class="obs-icon"><?= $obs['type']==='bad' ? '⚠️' : '📌' ?></span>
        <span><?= htmlspecialchars($obs['text']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <!-- ── § Critical Issues ─────────────────────────────────── -->
    <?php if (!empty($criticalIssues)): ?>
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Critical Issues</div>
    <div class="critical-box">
      <?php foreach ($criticalIssues as $ci): ?>
      <div class="critical-item">
        <div class="critical-dot"></div>
        <span><?= htmlspecialchars($ci) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── § Recommendations ─────────────────────────────────── -->
    <?php if (!empty($recommendations)): ?>
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Recommendations</div>
    <ol class="rec-list">
      <?php foreach ($recommendations as $rec): ?>
      <li><?= htmlspecialchars($rec) ?></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <!-- ── § Segment Detail Cards ──────────────────────────────── -->
    <?php if (!empty($segDetails)): ?>
    <div class="section-h"><span class="sec-num"><?= secNum() ?></span> Segment Detail Cards</div>
    <div class="seg-cards">
      <?php
        $cumLen2 = 0;
        foreach ($segs as $seg):
          $sid  = (int)$seg['id'];
          $sr   = $segResults[$sid] ?? null;
          $det  = $segDetails[$sid] ?? null;
          if (!$sr || !$det) { $cumLen2 += (float)$seg['length']; continue; }
          $col  = ratingColour($sr['rating']);
          $audit = $det['audit'];
          $startMc = $cumLen2;
          $endMc   = $cumLen2 + (float)$seg['length'];
          $cumLen2  = $endMc;
          $gpsSub  = trim(($seg['start_label']??'') . ' → ' . ($seg['end_label']??''));

          function dvFmt($v): array {
            if ($v === null || $v === '' || strtolower((string)$v) === 'n/a') return ['N/A','na'];
            if (strtolower((string)$v) === 'yes') return ['Yes','yes'];
            if (strtolower((string)$v) === 'no')  return ['No', 'no'];
            return [htmlspecialchars((string)$v), ''];
          }
      ?>
      <div class="seg-card">
        <div class="seg-card-header"
             style="background:<?= $col ?>12;border-bottom:2px solid <?= $col ?>33;">
          <div>
            <div class="seg-card-title">
              Segment <?= (int)$seg['segment_number'] ?>
              <?php if ($gpsSub && $gpsSub !== ' → '): ?>
                · <?= htmlspecialchars($gpsSub) ?>
              <?php endif; ?>
            </div>
            <div class="seg-card-sub">
              GPS: <?= htmlspecialchars($seg['start_label']??'?') ?>
              → <?= htmlspecialchars($seg['end_label']??'?') ?>
              | Length: <?= number_format((float)$seg['length']) ?>m
            </div>
          </div>
          <div class="seg-card-score">
            <div class="val"   style="color:<?= $col ?>"><?= $sr['final'] ?>/100</div>
            <div class="label" style="color:<?= $col ?>"><?= $sr['rating'] ?></div>
          </div>
        </div>
        <div class="seg-card-dims">
          <?php foreach ([
            ['Safety',     $sr['safety_score'],    'var(--safety-c)'],
            ['Continuity', $sr['continuity_score'], 'var(--cont-c)'],
            ['Comfort',    $sr['comfort_score'],     'var(--comf-c)'],
          ] as [$dn,$dv,$dc]): ?>
          <div class="seg-dim-item">
            <div class="seg-dim-val" style="color:<?= $dc ?>"><?= $dv ?></div>
            <div class="seg-dim-lbl"><?= $dn ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="seg-card-details">
          <?php
            $detRows = [
              'Surface Material'    => $audit['surface_material']      ?? null,
              'Track Missing'       => $audit['cycle_track_missing']   ?? null,
              'Buffer Zone'         => $audit['buffer_zone']            ?? null,
              'Lighting'            => $audit['light_after_sunset']     ?? null,
              'Shade'               => $audit['shade']                  ?? null,
              'Cyclist Can Use'     => $audit['cyclist_use']            ?? null,
              'Better Surface'      => $audit['better_surface']         ?? null,
              'Signage Count'       => $audit['seg_signage_count']      ?? 0,
              'People Walking'      => $audit['people_walking']         ?? null,
              'Intersections'       => $det['intersections'],
              'Obstructions (total)'=> $det['obs_total'],
            ];
          ?>
          <?php foreach ($detRows as $k => $v):
            [$disp, $cls] = dvFmt($v);
          ?>
          <div class="detail-row">
            <span class="detail-key"><?= $k ?></span>
            <span class="detail-val <?= $cls ?>"><?= $disp ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /.rpt-body -->

  <!-- ══ FOOTER ════════════════════════════════════════════════ -->
  <div class="rpt-footer">
    <div class="footer-left">
      <?php if ($logoBase64): ?>
      <img src="<?= $logoBase64 ?>" alt="Parisar">
      <?php endif; ?>
      <div class="footer-meta">
        <div><strong>Parisar — Cycle Track Audit Programme</strong></div>
        <div>parisar.org | Pune, Maharashtra</div>
        <div>Generated by CycleAudit · <?= APP_ORG ?></div>
      </div>
    </div>
    <div class="footer-right">
      <div>Road: <strong><?= htmlspecialchars($session['road_name']) ?></strong></div>
      <?php if ($roadScore): ?>
      <div>Final Score: <strong><?= $roadScore['score'] ?>/100</strong> (<?= $roadScore['rating'] ?>)</div>
      <?php endif; ?>
      <div style="margin-top:4px">Printed <?= $printDate ?></div>
      <div>Surveyor: <?= htmlspecialchars($session['surveyor_name']) ?></div>
    </div>
  </div>

</div><!-- /.report -->
<script src="../js/report-handlers.js?v=<?= filemtime(__DIR__ . '/../js/report-handlers.js') ?>"></script>
</body>
</html>