<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/reports/export-city-excel.php
//  GET  — admin: ?city_id=<id> (optional if exactly one
//         city exists; required once more than one does)
//         city_leader: always scoped to their own city, city_id
//         param ignored.
//
//  The city-wide downloadable score sheet: one row per road_group
//  (real-world road) in the city with its length-weighted score,
//  plus a city-total row. This is distinct from export-excel.php
//  (a single audit session's own report) and from
//  api/admin/export-roads.php (a plain roads list, no scores).
//
//  Dependencies:
//    composer require phpoffice/phpspreadsheet
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/ScoreService.php';
require_once __DIR__ . '/../../config/rate_limit.php';

$rl = checkAndRecordApiRequest($pdo, 'user:' . $CURRENT_USER_ID, 'export_city_excel', 5, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ── Resolve which city we're exporting ─────────────────────────
$isNationalAdmin = $CURRENT_USER_ROLE === 'admin';

if ($isNationalAdmin) {
    if (isset($_GET['city_id'])) {
        $cityId = filter_var($_GET['city_id'], FILTER_VALIDATE_INT);
        if ($cityId === false) {
            http_response_code(400);
            echo 'Invalid city_id.';
            exit;
        }
        $cityCheck = $pdo->prepare('SELECT 1 FROM cities WHERE id = ? LIMIT 1');
        $cityCheck->execute([$cityId]);
        if ($cityCheck->fetchColumn() === false) {
            http_response_code(404);
            echo 'City not found.';
            exit;
        }
    } else {
        $cityCountStmt = $pdo->query('SELECT COUNT(*) FROM cities');
        if ((int)$cityCountStmt->fetchColumn() !== 1) {
            http_response_code(400);
            echo 'city_id is required — more than one city exists.';
            exit;
        }
        $cityId = (int)$pdo->query('SELECT id FROM cities LIMIT 1')->fetchColumn();
    }
} else {
    // city_leader — always their own city, never client-supplied.
    $cityId = $CURRENT_USER_CITY_ID;
    if ($cityId === null) {
        http_response_code(400);
        echo 'Your account has no city assigned — contact an Admin.';
        exit;
    }
}

// city name, best-effort — falls back gracefully if the (untracked-
// in-migrations) cities table ever lacks a `name` column.
$cityName = 'City #' . $cityId;
try {
    $nameStmt = $pdo->prepare('SELECT name FROM cities WHERE id = ? LIMIT 1');
    $nameStmt->execute([$cityId]);
    $fetchedName = $nameStmt->fetchColumn();
    if (is_string($fetchedName) && $fetchedName !== '') {
        $cityName = $fetchedName;
    }
} catch (\Throwable $e) {
    // Fall back to "City #<id>" above — not fatal.
}

// ── Fetch every verified road_group in the city ────────────────
$groupStmt = $pdo->prepare(
    'SELECT id, canonical_name
       FROM road_groups
      WHERE city_id = ? AND is_verified = 1
      ORDER BY canonical_name ASC'
);
$groupStmt->execute([$cityId]);
$groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

// Member road ids + configured total_length + segment/completion counts per group
$memberStmt = $pdo->prepare(
    'SELECT r.id, r.total_length,
            (SELECT COUNT(*) FROM segments s WHERE s.road_id = r.id) AS seg_total,
            (SELECT COUNT(*) FROM segments s WHERE s.road_id = r.id AND s.status = \'completed\') AS seg_done
       FROM roads r
      WHERE r.road_group_id = ?'
);

$rows = [];
$allRoadIds = [];
foreach ($groups as $group) {
    $memberStmt->execute([$group['id']]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    $roadIds       = array_map(fn($m) => (int)$m['id'], $members);
    $configuredLen = array_sum(array_map(fn($m) => (float)($m['total_length'] ?? 0), $members));
    $segTotal      = array_sum(array_map(fn($m) => (int)$m['seg_total'], $members));
    $segDone       = array_sum(array_map(fn($m) => (int)$m['seg_done'], $members));

    $allRoadIds = array_merge($allRoadIds, $roadIds);

    $score = calculateRoadGroupScore($roadIds, $pdo);

    $rows[] = [
        'name'           => $group['canonical_name'],
        'configured_len' => $configuredLen,
        'seg_total'      => $segTotal,
        'seg_done'       => $segDone,
        'score'          => $score,
    ];
}

$cityScore = calculateRoadGroupScore($allRoadIds, $pdo);

// ── Colour helpers (same palette as export-excel.php) ──────────
function ccBg(string $condition): string {
    return match ($condition) {
        'Good'     => 'FFD5F5DC',
        'OK'       => 'FFFFF9C4',
        'Poor'     => 'FFFFE0B2',
        'Bad'      => 'FFFFCDD2',
        'Very Bad' => 'FFEF9A9A',
        default    => 'FFF5F5F5',
    };
}
function ccFg(string $condition): string {
    return match ($condition) {
        'Good'     => 'FF1B5E20',
        'OK'       => 'FFF57F17',
        'Poor'     => 'FFE65100',
        'Bad'      => 'FFB71C1C',
        'Very Bad' => 'FF7F0000',
        default    => 'FF424242',
    };
}
function applyScoreCell($sheet, string $cell, float $score): void {
    $cond = scoreToCondition($score);
    $sheet->getStyle($cell)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => ccFg($cond)]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => ccBg($cond)]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
}
function applyConditionCell($sheet, string $cell, string $condition): void {
    $sheet->getStyle($cell)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => ccFg($condition)]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => ccBg($condition)]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
}
function applyHeaderStyle($sheet, string $range): void {
    $sheet->getStyle($range)->applyFromArray([
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF3D7A1F']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
}
function applyThinBorder($sheet, string $range): void {
    $sheet->getStyle($range)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD0D0D0']]],
    ]);
}
function applyAltRow($sheet, string $range, int $row): void {
    if ($row % 2 === 0) {
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF8FBF4');
    }
}

// ── Build spreadsheet ────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Parisar CycleAudit')
    ->setTitle("Cycle Track Score Sheet — {$cityName}")
    ->setSubject('City-wide Cycle Track Audit Score Sheet')
    ->setDescription('Generated by Parisar CycleAudit');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Score Sheet');

$sheet->mergeCells('A1:G1');
$sheet->setCellValue('A1', 'PARISAR — CITY-WIDE CYCLE TRACK SCORE SHEET');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(30);

$sheet->mergeCells('A2:G2');
$sheet->setCellValue('A2', $cityName . '  ·  Generated ' . date('d M Y'));
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1A3D0A']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5D4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(22);

$sheet->mergeCells('A3:G3');
$sheet->setCellValue('A3', 'Scores: 0 = best · 100 = worst. Only verified roads are included. Score is length-weighted across all segments.');
$sheet->getStyle('A3')->applyFromArray([
    'font'      => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF555555']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$headers = ['Road', 'Length (m)', 'Segments', 'Safety', 'Continuity', 'Comfort', 'Score', 'Condition'];
$cols    = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
foreach ($headers as $i => $h) {
    $sheet->setCellValue("{$cols[$i]}4", $h);
}
applyHeaderStyle($sheet, 'A4:H4');
$sheet->getRowDimension(4)->setRowHeight(18);
$sheet->freezePane('A5');

$r = 5;
foreach ($rows as $row) {
    $sheet->setCellValue("A{$r}", $row['name']);
    $sheet->setCellValue("B{$r}", $row['configured_len'] > 0 ? number_format($row['configured_len']) : '—');
    $sheet->setCellValue("C{$r}", $row['seg_done'] . ' / ' . $row['seg_total']);
    $sheet->getStyle("B{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    if ($row['score']) {
        $sc = $row['score'];
        $sheet->setCellValue("D{$r}", $sc['safety_score']);
        $sheet->setCellValue("E{$r}", $sc['continuity_score']);
        $sheet->setCellValue("F{$r}", $sc['comfort_score']);
        $sheet->setCellValue("G{$r}", $sc['score']);
        $sheet->setCellValue("H{$r}", $sc['condition']);
        foreach (['D', 'E', 'F', 'G'] as $c) applyScoreCell($sheet, "{$c}{$r}", (float)$sheet->getCell("{$c}{$r}")->getValue());
        applyConditionCell($sheet, "H{$r}", $sc['condition']);
    } else {
        foreach (['D', 'E', 'F', 'G', 'H'] as $c) {
            $sheet->setCellValue("{$c}{$r}", '—');
            $sheet->getStyle("{$c}{$r}")->applyFromArray([
                'font'      => ['color' => ['argb' => 'FFAAAAAA'], 'italic' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
    }

    applyThinBorder($sheet, "A{$r}:H{$r}");
    applyAltRow($sheet, "A{$r}:H{$r}", $r);
    $sheet->getRowDimension($r)->setRowHeight(16);
    $r++;
}

if (empty($rows)) {
    $sheet->mergeCells("A{$r}:H{$r}");
    $sheet->setCellValue("A{$r}", 'No verified roads in this city yet.');
    $sheet->getStyle("A{$r}")->applyFromArray([
        'font' => ['italic' => true, 'color' => ['argb' => 'FF888888']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $r++;
}

// City total row
if ($cityScore) {
    $sheet->mergeCells("A{$r}:C{$r}");
    $sheet->setCellValue("A{$r}", 'CITY TOTAL (length-weighted)');
    $sheet->setCellValue("D{$r}", $cityScore['safety_score']);
    $sheet->setCellValue("E{$r}", $cityScore['continuity_score']);
    $sheet->setCellValue("F{$r}", $cityScore['comfort_score']);
    $sheet->setCellValue("G{$r}", $cityScore['score']);
    $sheet->setCellValue("H{$r}", $cityScore['condition']);

    $sheet->getStyle("A{$r}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    foreach (['D', 'E', 'F', 'G'] as $c) applyScoreCell($sheet, "{$c}{$r}", (float)$sheet->getCell("{$c}{$r}")->getValue());
    applyConditionCell($sheet, "H{$r}", $cityScore['condition']);
    $sheet->getRowDimension($r)->setRowHeight(20);
    applyThinBorder($sheet, "A{$r}:H{$r}");
}

$sheet->getColumnDimension('A')->setWidth(26);
$sheet->getColumnDimension('B')->setWidth(13);
$sheet->getColumnDimension('C')->setWidth(12);
foreach (['D', 'E', 'F', 'G'] as $c) $sheet->getColumnDimension($c)->setWidth(12);
$sheet->getColumnDimension('H')->setWidth(12);

// ── Stream to browser ───────────────────────────────────────────
try {
    $safeName = preg_replace('/[^A-Za-z0-9\-_]/', '-', $cityName);
    $safeName = preg_replace('/-+/', '-', trim($safeName, '-'));
    $filename = 'CycleAudit-ScoreSheet-' . $safeName . '-' . date('Y-m-d') . '.xlsx';

    ActivityLogger::log($pdo, ActivityLogger::EXPORT_DOWNLOADED, (int)$CURRENT_USER_ID, ['type' => 'city_excel', 'city_id' => (int)$cityId]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

} catch (\Exception $e) {
    error_log('City Excel export error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Excel generation failed. Please try again.';
}
