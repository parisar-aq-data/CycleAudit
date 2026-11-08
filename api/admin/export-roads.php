<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/export-roads.php
//  POST — accepts the currently-filtered Roads admin rows as JSON
//  and streams back a flat .xlsx workbook, one row per road group.
//
//  No DB re-query: the client (pages/admin.php) is the single
//  source of truth for "what's on screen" — whatever the on-page
//  search filter currently shows is exactly what gets exported.
//  CSV is handled entirely client-side (see admin.php); this
//  endpoint only covers the Excel format.
//
//  Body: { rows: [ { name, entry_count, total_segments,
//                    is_verified, created_at }, ... ] }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/constants.php';
// vendor/autoload.php is already loaded via config/constants.php.

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── CSRF verification (same pattern as api/admin/roads.php) ────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$rows = $body['rows'] ?? null;

if (!is_array($rows)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing rows.']);
    exit;
}

// ── Build workbook ────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Roads');

$headers = ['Road Name', 'Entries', 'Total Segments', 'Verified', 'Created At'];
foreach ($headers as $i => $h) {
    $col = Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValue("{$col}1", $h);
}
$lastCol = Coordinate::stringFromColumnIndex(count($headers));
$sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D0A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(20);

$r = 2;
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $sheet->setCellValue("A{$r}", (string)($row['name'] ?? ''));
    $sheet->setCellValue("B{$r}", (int)($row['entry_count'] ?? 0));
    $sheet->setCellValue("C{$r}", (int)($row['total_segments'] ?? 0));
    $sheet->setCellValue("D{$r}", !empty($row['is_verified']) ? 'Yes' : 'No');
    $sheet->setCellValue("E{$r}", (string)($row['created_at'] ?? ''));
    $sheet->getStyle("B{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    if ($r % 2 === 0) {
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF5F5F5']],
        ]);
    }
    $r++;
}

$widths = [30, 12, 16, 12, 20];
foreach ($widths as $i => $w) {
    $col = Coordinate::stringFromColumnIndex($i + 1);
    $sheet->getColumnDimension($col)->setWidth($w);
}
$sheet->freezePane('A2');

// ── Stream to browser ───────────────────────────────────────────
try {
    $filename = 'CycleAudit-Roads-' . date('Y-m-d') . '.xlsx';

    header_remove('Content-Type');
    ActivityLogger::log($pdo, ActivityLogger::EXPORT_DOWNLOADED, (int)$CURRENT_USER_ID, ['type' => 'roads_excel']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
} catch (\Throwable $e) {
    error_log('api/admin/export-roads.php: ' . $e->getMessage());
    http_response_code(500);
    header_remove('Content-Type');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Excel generation failed.']);
}
