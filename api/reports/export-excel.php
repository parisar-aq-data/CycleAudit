<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/reports/export-excel.php
//  GET ?session_id=<id>
//
//  Generates a colour-coded .xlsx audit report using PhpSpreadsheet.
//
//  Sheets:
//    1. Summary   — road meta + overall scores
//    2. Segments  — one row per segment, cells colour-coded by condition
//    3. Details   — full per-segment audit field dump
//
//  Dependencies:
//    composer require phpoffice/phpspreadsheet
//
//  Output:
//    Content-Disposition: attachment; filename="CycleAudit-<Road>-<Date>.xlsx"
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../services/ScoreService.php';

require_once __DIR__ . '/../../config/rate_limit.php';

$rl = checkAndRecordApiRequest($pdo, 'user:' . $CURRENT_USER_ID, 'export_excel', 5, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}
// vendor/autoload.php is already loaded via config/constants.php → no need to re-require.

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;

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
            r.start_point, r.end_point,
            u.name AS surveyor_name, u.public_id AS surveyor_public_id,
            u.organisation, u.email AS surveyor_email
     FROM   audit_sessions s
     JOIN   roads r ON r.id = s.road_id
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

// ── Fetch segments ────────────────────────────────────────────
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

// ── Per-segment scores ────────────────────────────────────────
$segResults = [];
foreach ($segs as $seg) {
    $aid = $seg['audit_id'] ? (int)$seg['audit_id'] : null;
    $segResults[(int)$seg['id']] = $aid ? calculateSegmentScore($aid, $pdo) : null;
}

// ── Per-segment audit details ─────────────────────────────────
$segDetails = [];
foreach ($segs as $seg) {
    if (!$seg['audit_id']) continue;
    $sa = $pdo->prepare(
        'SELECT surface_material, buffer_zone, light_after_sunset,
                shade, cycle_track_missing, missing_length,
                people_walking, cyclist_use, better_surface,
                footpath_rating, footpath_score
         FROM segment_audits WHERE id = ? LIMIT 1'
    );
    $sa->execute([$seg['audit_id']]);
    $saRow = $sa->fetch(PDO::FETCH_ASSOC);

    $si = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage, traffic_calming
         FROM intersections WHERE audit_id = ?'
    );
    $si->execute([$seg['audit_id']]);
    $intRows = $si->fetchAll(PDO::FETCH_ASSOC);

    $noRamps = 0; $noSign = 0; $noCalming = 0;
    foreach ($intRows as $ir) {
        if (($ir['off_ramp'] ?? '') === 'No Ramp' || ($ir['on_ramp'] ?? '') === 'No Ramp') $noRamps++;
        if (($ir['markings'] ?? '') === 'Absent' || ($ir['signage'] ?? '') === 'Absent') $noSign++;
        if (strtolower($ir['traffic_calming'] ?? '') === 'absent') $noCalming++;
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
        'intersections'  => count($intRows),
        'no_ramps'       => $noRamps,
        'no_sign'        => $noSign,
        'no_calming'     => $noCalming,
        'obs_total'      => (int)($obsRow['total']   ?? 0),
        'obs_partial'    => (int)($obsRow['partial'] ?? 0),
        'cyclist_slowed' => (int)($obsRow['slowed']  ?? 0),
    ];
}

// ── Colour helpers ────────────────────────────────────────────

/**
 * Returns ARGB hex for a condition label (no leading #).
 * Used directly in PhpSpreadsheet Fill/Font colour.
 */
function xlConditionBg(string $condition): string {
    return match ($condition) {
        'Good'     => 'FFD5F5DC',   // soft green
        'OK'       => 'FFFFF9C4',   // soft yellow
        'Poor'     => 'FFFFE0B2',   // soft orange
        'Bad'      => 'FFFFCDD2',   // soft red
        'Very Bad' => 'FFEF9A9A',   // stronger red
        default    => 'FFF5F5F5',
    };
}

function xlConditionFg(string $condition): string {
    return match ($condition) {
        'Good'     => 'FF1B5E20',
        'OK'       => 'FFF57F17',
        'Poor'     => 'FFE65100',
        'Bad'      => 'FFB71C1C',
        'Very Bad' => 'FF7F0000',
        default    => 'FF424242',
    };
}

function xlScoreBg(float $score): string {
    return xlConditionBg(scoreToCondition($score));
}
function xlScoreFg(float $score): string {
    return xlConditionFg(scoreToCondition($score));
}

// ── PhpSpreadsheet style helpers ──────────────────────────────

function applyHeaderStyle($sheet, string $range, string $bgArgb = 'FF1A3D0A', string $fgArgb = 'FFFFFFFF', int $fontSize = 10): void {
    $sheet->getStyle($range)->applyFromArray([
        'font'      => ['bold' => true, 'size' => $fontSize, 'color' => ['argb' => $fgArgb]],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bgArgb]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
}

function applyScoreCell($sheet, string $cell, float $score): void {
    $cond = scoreToCondition($score);
    $sheet->getStyle($cell)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => xlConditionFg($cond)]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => xlConditionBg($cond)]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
}

function applyConditionCell($sheet, string $cell, string $condition): void {
    $sheet->getStyle($cell)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => xlConditionFg($condition)]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => xlConditionBg($condition)]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
}

function applyThinBorder($sheet, string $range): void {
    $sheet->getStyle($range)->applyFromArray([
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD0D0D0']],
        ],
    ]);
}

function applyAltRow($sheet, string $range, int $row): void {
    if ($row % 2 === 0) {
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF8FBF4');
    }
}

// ── Build Spreadsheet ─────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Parisar CycleAudit')
    ->setTitle("Cycle Track Audit — {$roadName}")
    ->setSubject('Cycle Track Audit Report')
    ->setDescription('Generated by Parisar CycleAudit');

// ════════════════════════════════════════════════════════
//  SHEET 1: SUMMARY
// ════════════════════════════════════════════════════════
$sumSheet = $spreadsheet->getActiveSheet();
$sumSheet->setTitle('Summary');

// Title block
$sumSheet->mergeCells('A1:F1');
$sumSheet->setCellValue('A1', 'PARISAR — CYCLE TRACK AUDIT REPORT');
$sumSheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sumSheet->getRowDimension(1)->setRowHeight(30);

$sumSheet->mergeCells('A2:F2');
$sumSheet->setCellValue('A2', $roadName);
$sumSheet->getStyle('A2')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1A3D0A']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5D4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sumSheet->getRowDimension(2)->setRowHeight(22);

// Meta fields
$meta = [
    ['Surveyor',      $session['surveyor_name']   ?? '—'],
    ['Organisation',  $session['organisation']     ?? '—'],
    ['Email',         $session['surveyor_email']   ?? '—'],
    ['Session ID',    $session['public_id']         ?? '—'],
    ['Road',          $roadName],
    ['Start → End',   trim(($session['start_point'] ?? '') . ' → ' . ($session['end_point'] ?? ''), ' →')],
    ['Total Length',  $session['total_length'] ? number_format((float)$session['total_length']) . ' m' : '—'],
    ['Segments',      count($completedSegs) . ' / ' . $totalSegs . ' completed'],
    ['Report Date',   date('d M Y')],
];

$r = 4;
foreach ($meta as [$k, $v]) {
    $sumSheet->setCellValue("A{$r}", $k);
    $sumSheet->setCellValue("B{$r}", $v);
    $sumSheet->getStyle("A{$r}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FF3D7A1F']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF5F9F0']],
    ]);
    $sumSheet->mergeCells("B{$r}:F{$r}");
    $r++;
}

// Overall scores block
$r += 1;
$sumSheet->mergeCells("A{$r}:F{$r}");
$sumSheet->setCellValue("A{$r}", 'OVERALL SCORES  (0 = best · 100 = worst)');
applyHeaderStyle($sumSheet, "A{$r}:F{$r}", 'FF2D5C15', 'FFFFFFFF', 10);
$sumSheet->getRowDimension($r)->setRowHeight(18);
$r++;

$scoreHeaders = ['Road Score', 'Safety', 'Continuity', 'Comfort', 'Condition', 'Segments'];
foreach ($scoreHeaders as $i => $h) {
    $col = chr(65 + $i);
    $sumSheet->setCellValue("{$col}{$r}", $h);
}
applyHeaderStyle($sumSheet, "A{$r}:F{$r}", 'FF3D7A1F', 'FFFFFFFF', 9);
$r++;

if ($roadScore) {
    $sumSheet->setCellValue("A{$r}", $roadScore['score']);
    $sumSheet->setCellValue("B{$r}", $roadScore['safety_score']);
    $sumSheet->setCellValue("C{$r}", $roadScore['continuity_score']);
    $sumSheet->setCellValue("D{$r}", $roadScore['comfort_score']);
    $sumSheet->setCellValue("E{$r}", $roadScore['condition']);
    $sumSheet->setCellValue("F{$r}", $roadScore['segment_count']);

    applyScoreCell($sumSheet, "A{$r}", $roadScore['score']);
    applyScoreCell($sumSheet, "B{$r}", $roadScore['safety_score']);
    applyScoreCell($sumSheet, "C{$r}", $roadScore['continuity_score']);
    applyScoreCell($sumSheet, "D{$r}", $roadScore['comfort_score']);
    applyConditionCell($sumSheet, "E{$r}", $roadScore['condition']);
    $sumSheet->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
} else {
    $sumSheet->mergeCells("A{$r}:F{$r}");
    $sumSheet->setCellValue("A{$r}", 'Audit incomplete — scores will appear when all segments are done');
    $sumSheet->getStyle("A{$r}")->applyFromArray([
        'font' => ['italic' => true, 'color' => ['argb' => 'FF888888']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
}
$sumSheet->getRowDimension($r)->setRowHeight(20);
applyThinBorder($sumSheet, "A4:F{$r}");

// Condition legend
$r += 2;
$sumSheet->mergeCells("A{$r}:F{$r}");
$sumSheet->setCellValue("A{$r}", 'CONDITION BANDS');
applyHeaderStyle($sumSheet, "A{$r}:F{$r}", 'FF555555', 'FFFFFFFF', 9);
$r++;

$legend = [
    ['Good',     '0 – 20',   'Best — meets all standards'],
    ['OK',       '20 – 40',  'Minor issues, usable'],
    ['Poor',     '40 – 60',  'Noticeable problems, needs improvement'],
    ['Bad',      '60 – 80',  'Serious deficiencies'],
    ['Very Bad', '80 – 100', 'Unusable / hazardous'],
];
foreach ($legend as [$cond, $range, $desc]) {
    $sumSheet->setCellValue("A{$r}", $cond);
    $sumSheet->setCellValue("B{$r}", $range);
    $sumSheet->mergeCells("C{$r}:F{$r}");
    $sumSheet->setCellValue("C{$r}", $desc);
    applyConditionCell($sumSheet, "A{$r}", $cond);
    $sumSheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $r++;
}
applyThinBorder($sumSheet, "A" . ($r - 5) . ":F" . ($r - 1));

// Column widths
$sumSheet->getColumnDimension('A')->setWidth(18);
$sumSheet->getColumnDimension('B')->setWidth(22);
foreach (['C', 'D', 'E', 'F'] as $c) $sumSheet->getColumnDimension($c)->setWidth(16);

// ════════════════════════════════════════════════════════
//  SHEET 2: SEGMENTS
// ════════════════════════════════════════════════════════
$segSheet = $spreadsheet->createSheet();
$segSheet->setTitle('Segments');

// Title
$segSheet->mergeCells('A1:J1');
$segSheet->setCellValue('A1', "SEGMENT SCORES — {$roadName}");
$segSheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$segSheet->getRowDimension(1)->setRowHeight(26);

// Subtitle note
$segSheet->mergeCells('A2:J2');
$segSheet->setCellValue('A2', 'Scores: 0 = best · 100 = worst. Cells colour-coded by condition band.');
$segSheet->getStyle('A2')->applyFromArray([
    'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF555555']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Headers
$segHeaders = ['#', 'Start', 'End', 'Length (m)', 'Safety', 'Continuity', 'Comfort', 'Final Score', 'Condition', 'Status'];
$segCols    = ['A', 'B',     'C',   'D',           'E',      'F',          'G',       'H',           'I',         'J'];
foreach ($segHeaders as $i => $h) {
    $segSheet->setCellValue("{$segCols[$i]}3", $h);
}
applyHeaderStyle($segSheet, 'A3:J3', 'FF3D7A1F', 'FFFFFFFF', 9);
$segSheet->getRowDimension(3)->setRowHeight(18);

// Data rows
$dataRow = 4;
foreach ($segs as $seg) {
    $sid = (int)$seg['id'];
    $sr  = $segResults[$sid] ?? null;

    $segSheet->setCellValue("A{$dataRow}", (int)$seg['segment_number']);
    $segSheet->setCellValue("B{$dataRow}", $seg['start_label'] ?? '—');
    $segSheet->setCellValue("C{$dataRow}", $seg['end_label']   ?? '—');
    $segSheet->setCellValue("D{$dataRow}", (float)$seg['length']);
    $segSheet->getStyle("A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $segSheet->getStyle("D{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    if ($sr) {
        $segSheet->setCellValue("E{$dataRow}", $sr['safety_score']);
        $segSheet->setCellValue("F{$dataRow}", $sr['continuity_score']);
        $segSheet->setCellValue("G{$dataRow}", $sr['comfort_score']);
        $segSheet->setCellValue("H{$dataRow}", $sr['final']);
        $segSheet->setCellValue("I{$dataRow}", $sr['condition']);
        $segSheet->setCellValue("J{$dataRow}", ucfirst($seg['status'] ?? 'pending'));

        applyScoreCell($segSheet, "E{$dataRow}", $sr['safety_score']);
        applyScoreCell($segSheet, "F{$dataRow}", $sr['continuity_score']);
        applyScoreCell($segSheet, "G{$dataRow}", $sr['comfort_score']);
        applyScoreCell($segSheet, "H{$dataRow}", $sr['final']);
        applyConditionCell($segSheet, "I{$dataRow}", $sr['condition']);
    } else {
        foreach (['E', 'F', 'G', 'H', 'I'] as $c) {
            $segSheet->setCellValue("{$c}{$dataRow}", '—');
            $segSheet->getStyle("{$c}{$dataRow}")->applyFromArray([
                'font'      => ['color' => ['argb' => 'FFAAAAAA'], 'italic' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $segSheet->setCellValue("J{$dataRow}", 'Pending');
        $segSheet->getStyle("J{$dataRow}")->getFont()->setItalic(true)->getColor()->setARGB('FFAAAAAA');
    }

    applyThinBorder($segSheet, "A{$dataRow}:J{$dataRow}");
    applyAltRow($segSheet, "A{$dataRow}:D{$dataRow}", $dataRow);   // only non-score cols get alt-row
    $segSheet->getRowDimension($dataRow)->setRowHeight(16);
    $dataRow++;
}

// Road score totals row
if ($roadScore) {
    $segSheet->mergeCells("A{$dataRow}:D{$dataRow}");
    $segSheet->setCellValue("A{$dataRow}", 'ROAD TOTAL (length-weighted)');
    $segSheet->setCellValue("E{$dataRow}", $roadScore['safety_score']);
    $segSheet->setCellValue("F{$dataRow}", $roadScore['continuity_score']);
    $segSheet->setCellValue("G{$dataRow}", $roadScore['comfort_score']);
    $segSheet->setCellValue("H{$dataRow}", $roadScore['score']);
    $segSheet->setCellValue("I{$dataRow}", $roadScore['condition']);

    $segSheet->getStyle("A{$dataRow}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    foreach (['E', 'F', 'G', 'H'] as $c) applyScoreCell($segSheet, "{$c}{$dataRow}", (float)$segSheet->getCell("{$c}{$dataRow}")->getValue());
    applyConditionCell($segSheet, "I{$dataRow}", $roadScore['condition']);
    $segSheet->getRowDimension($dataRow)->setRowHeight(20);
    applyThinBorder($segSheet, "A{$dataRow}:J{$dataRow}");
}

// Column widths
$segSheet->getColumnDimension('A')->setWidth(5);
$segSheet->getColumnDimension('B')->setWidth(22);
$segSheet->getColumnDimension('C')->setWidth(22);
$segSheet->getColumnDimension('D')->setWidth(12);
foreach (['E', 'F', 'G', 'H'] as $c) $segSheet->getColumnDimension($c)->setWidth(13);
$segSheet->getColumnDimension('I')->setWidth(12);
$segSheet->getColumnDimension('J')->setWidth(12);

// Freeze header
$segSheet->freezePane('A4');

// ════════════════════════════════════════════════════════
//  SHEET 3: DETAILS
// ════════════════════════════════════════════════════════
$detSheet = $spreadsheet->createSheet();
$detSheet->setTitle('Details');

// Title
$detSheet->mergeCells('A1:R1');
$detSheet->setCellValue('A1', "SEGMENT AUDIT DETAILS — {$roadName}");
$detSheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$detSheet->getRowDimension(1)->setRowHeight(26);

// Headers
$detHeaders = [
    '#', 'Start Label', 'End Label', 'Length (m)',
    'Surface',  'Buffer Zone', 'Lighting', 'Shade',
    'Track Missing', 'Missing (m)',
    'Intersections', 'Missing Ramps', 'No Sign/Marking', 'No Calming',
    'Obs Total', 'Obs Partial', 'Cyclist Slowed',
    'Status',
];
foreach ($detHeaders as $i => $h) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $detSheet->setCellValue("{$col}2", $h);
}
$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($detHeaders));
applyHeaderStyle($detSheet, "A2:{$lastCol}2", 'FF3D7A1F', 'FFFFFFFF', 9);
$detSheet->getRowDimension(2)->setRowHeight(18);
$detSheet->freezePane('A3');

// Data rows
$detRow = 3;
foreach ($segs as $seg) {
    $sid = (int)$seg['id'];
    $det = $segDetails[$sid] ?? null;
    $sr  = $segResults[$sid] ?? null;

    $values = [
        (int)$seg['segment_number'],
        $seg['start_label'] ?? '—',
        $seg['end_label']   ?? '—',
        (float)$seg['length'],
        $det ? ($det['audit']['surface_material']   ?? '—') : '—',
        $det ? ($det['audit']['buffer_zone']          ?? '—') : '—',
        $det ? ($det['audit']['light_after_sunset']   ?? '—') : '—',
        $det ? ($det['audit']['shade']                ?? '—') : '—',
        $det ? ($det['audit']['cycle_track_missing']  ?? '—') : '—',
        $det ? (float)($det['audit']['missing_length'] ?? 0) : 0,
        $det ? $det['intersections']   : '—',
        $det ? $det['no_ramps']        : '—',
        $det ? $det['no_sign']         : '—',
        $det ? $det['no_calming']      : '—',
        $det ? $det['obs_total']       : '—',
        $det ? $det['obs_partial']     : '—',
        $det ? $det['cyclist_slowed']  : '—',
        ucfirst($seg['status'] ?? 'pending'),
    ];

    foreach ($values as $i => $val) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $detSheet->setCellValue("{$col}{$detRow}", $val);
    }

    // Colour the Status cell
    $statusCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($detHeaders));
    if (($seg['status'] ?? '') === 'completed') {
        $detSheet->getStyle("{$statusCol}{$detRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF1B5E20']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD5F5DC']],
        ]);
    } else {
        $detSheet->getStyle("{$statusCol}{$detRow}")->getFont()->setItalic(true)->getColor()->setARGB('FFAAAAAA');
    }

    // Colour the cycle_track_missing cell (col 9 = I)
    $mtCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(9);
    if ($det && ($det['audit']['cycle_track_missing'] ?? '') === 'Yes') {
        $detSheet->getStyle("{$mtCol}{$detRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFB71C1C']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCDD2']],
        ]);
    }

    applyAltRow($detSheet, "A{$detRow}:{$lastCol}{$detRow}", $detRow);
    applyThinBorder($detSheet, "A{$detRow}:{$lastCol}{$detRow}");
    $detSheet->getRowDimension($detRow)->setRowHeight(15);
    $detRow++;
}

// Column widths
$detWidths = [5, 22, 22, 11, 18, 15, 12, 10, 14, 11, 13, 13, 17, 11, 11, 12, 14, 12];
foreach ($detWidths as $i => $w) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $detSheet->getColumnDimension($col)->setWidth($w);
}

// ── Set active sheet to Summary ───────────────────────────────
$spreadsheet->setActiveSheetIndex(0);

// ── Stream to browser ─────────────────────────────────────────
try {
    $safeName = preg_replace('/[^A-Za-z0-9\-_]/', '-', $roadName);
    $safeName = preg_replace('/-+/', '-', trim($safeName, '-'));
    $filename = 'CycleAudit-' . $safeName . '-' . date('Y-m-d') . '.xlsx';

    ActivityLogger::log($pdo, ActivityLogger::EXPORT_DOWNLOADED, (int)$CURRENT_USER_ID, ['type' => 'road_report_excel', 'road_id' => $roadId, 'session_id' => $sessionId]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

} catch (\Exception $e) {
    error_log('Excel export error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Excel generation failed. Please try again.';
}
