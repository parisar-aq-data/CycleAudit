<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/segments/save.php
//  POST — inserts segments for ONE road.
// ═══════════════════════════════════════════════════════════════

// ── JSON header FIRST — ensures even fatal errors return JSON ──
header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log('save.php uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/permissions.php';
require_once __DIR__ . '/../../../helpers/Validator.php';
require_once __DIR__ . '/../../../repositories/RoadRepository.php';
require_once __DIR__ . '/../../../repositories/SegmentRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── CSRF verification ──────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$raw      = file_get_contents('php://input');
$data     = json_decode($raw, true) ?? [];
$segments = $data['segments'] ?? [];

$v = Validator::make($data)
    ->required('road_id')
    ->integer('road_id')
    ->min('road_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

if (empty($segments) || !is_array($segments)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'segments array is required and must not be empty.']);
    exit;
}

$roadId = (int)$data['road_id'];

try {
    $roadRepo    = new RoadRepository($pdo);
    $segmentRepo = new SegmentRepository($pdo);

    $road = $roadRepo->find($roadId);
    if ($road === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found (id=' . $roadId . ').']);
        exit;
    }

    gate('save_segments', $CURRENT_USER_ID, $CURRENT_USER_ROLE, ['owner_id' => $road['creator_id'], 'city_id' => $road['city_id']]);

    if ($road['finalized_at'] !== null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This road has been finalized and can no longer be edited.']);
        exit;
    }

    $pdo->beginTransaction();

    $segmentRepo->deleteForRoad($roadId);

    $stmt = $pdo->prepare(
        "INSERT INTO segments
           (public_id, road_id, segment_number, start_label, end_label,
            start_distance, end_distance, length, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
    );

    $saved = 0;
    foreach ($segments as $seg) {
        $segNum = isset($seg['segment_number']) ? (int)$seg['segment_number']   : 0;
        // Labels are free text shown to other users: drop control characters and
        // keep within the column size (VARCHAR 200). The pages also escape them.
        $startL = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($seg['start_label'] ?? ''))), 0, 200);
        $endL   = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($seg['end_label']   ?? ''))), 0, 200);
        $startD = isset($seg['start_distance']) ? (float)$seg['start_distance'] : 0.0;
        $endD   = isset($seg['end_distance'])   ? (float)$seg['end_distance']   : 0.0;
        $length = isset($seg['length'])         ? (float)$seg['length']         : 0.0;

        if ($segNum <= 0) continue;

        // Use a temporary unique public_id to avoid UNIQUE KEY collision on
        // empty string default when inserting multiple segments at once.
        $tempPublicId = 'SEG-R' . $roadId . 'N' . $segNum;
        $stmt->execute([$tempPublicId, $roadId, $segNum, $startL ?: null, $endL ?: null, $startD, $endD, $length]);

        $segId       = (int)$pdo->lastInsertId();
        $segPublicId = 'SEG-' . str_pad((string)$segId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE segments SET public_id = ? WHERE id = ?')->execute([$segPublicId, $segId]);

        $saved++;
    }

    $pdo->commit();
    ActivityLogger::log($pdo, ActivityLogger::ROAD_SEGMENTS_SAVED, (int)$CURRENT_USER_ID, ['road_id' => (int)$roadId, 'segments' => $saved]);

    echo json_encode(['success' => true, 'segments_saved' => $saved]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('api/roads/segments/save.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}