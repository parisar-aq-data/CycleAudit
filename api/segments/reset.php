<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/reset.php
//  POST — clears all audit data for a segment and resets to pending.
//  UPDATED: uses SegmentRepository + Validator
// ═══════════════════════════════════════════════════════════════

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
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
$body = json_decode($raw, true) ?? [];

$v = Validator::make($body)
    ->required('segment_id', 'session_id')
    ->integer('segment_id', 'session_id')
    ->min('segment_id', 1)
    ->min('session_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

$segmentId = (int)$body['segment_id'];

// A project the Admin deleted is closed to everyone: work on it stops.
require_once __DIR__ . '/../../repositories/SurveyorWorkRepository.php';
if ((new SurveyorWorkRepository($pdo))->projectDeletedForSegment($segmentId)) {
    http_response_code(410);
    echo json_encode(['success' => false, 'error' => PROJECT_DELETED_MESSAGE, 'project_deleted' => true]);
    exit;
}
$sessionId = (int)$body['session_id'];

try {
    $pdo->beginTransaction();
    $repo = new SegmentRepository($pdo);

    // ── 1. Verify segment exists ───────────────────────────────
    $segment = $repo->findWithRoad($segmentId);
    if ($segment === null) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    // ── 2. Verify session ownership ────────────────────────────
    if ($repo->findSession($sessionId, $CURRENT_USER_ID) === null) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session not found or not owned by you.']);
        exit;
    }

    // ── 3. Delete all audit data for this segment ──────────────
    $repo->deleteAuditData($segmentId);

    // ── 4. Reset segment to pending ────────────────────────────
    $repo->resetToPending($segmentId);

    // ── 5. Re-open session if it was completed ─────────────────
    $repo->reopenCompletedSession(
        $segment['road_id'] ?? 0,
        $CURRENT_USER_ID
    );

    $pdo->commit();
    ActivityLogger::log($pdo, ActivityLogger::SEGMENT_RESET, (int)$CURRENT_USER_ID, ['segment_id' => (int)$segmentId]);

    echo json_encode([
        'success'    => true,
        'segment_id' => $segmentId,
        'message'    => 'Form reset — all audit data cleared.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('api/segments/reset.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
