<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/unlock.php
//  POST — resets a completed segment back to 'pending'.
//  UPDATED: uses SegmentRepository + Validator + gate()
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';

header('Content-Type: application/json');

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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$v = Validator::make($data)
    ->required('segment_id')
    ->integer('segment_id')
    ->min('segment_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

$segmentId = (int)$data['segment_id'];

// A project the Admin deleted is closed to everyone: work on it stops.
require_once __DIR__ . '/../../repositories/SurveyorWorkRepository.php';
if ((new SurveyorWorkRepository($pdo))->projectDeletedForSegment($segmentId)) {
    http_response_code(410);
    echo json_encode(['success' => false, 'error' => PROJECT_DELETED_MESSAGE, 'project_deleted' => true]);
    exit;
}

try {
    $pdo->beginTransaction();
    $repo = new SegmentRepository($pdo);

    // ── 1. Fetch segment + road ownership ──────────────────────
    $segment = $repo->findWithRoad($segmentId);

    if ($segment === null) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    // ── 2. RBAC gate ───────────────────────────────────────────
    gate('unlock_segment', $CURRENT_USER_ID, $CURRENT_USER_ROLE, ['owner_id' => $segment['creator_id'], 'city_id' => $segment['city_id']]);

    // ── 2b. Finalized roads are permanently locked ──────────────
    if ($segment['finalized_at'] !== null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This audit has been finalized and can no longer be edited.']);
        exit;
    }

    // ── 3. Only completed segments can be unlocked ─────────────
    if ($segment['status'] !== 'completed') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Segment is not locked — nothing to unlock.']);
        exit;
    }

    // ── 4. Reset segment to pending (keeps audit data) ─────────
    $repo->resetToPending($segmentId);

    // ── 5. Re-open the session for this road if completed ──────
    $repo->reopenCompletedSession((int)$segment['road_id'], $CURRENT_USER_ID);

    $pdo->commit();
    ActivityLogger::log($pdo, ActivityLogger::SEGMENT_UNLOCKED, (int)$CURRENT_USER_ID, ['segment_id' => (int)$segmentId]);

    echo json_encode([
        'success'    => true,
        'segment_id' => $segmentId,
        'message'    => 'Segment unlocked — existing answers pre-loaded for editing.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('unlock.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
