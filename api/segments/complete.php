<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/complete.php
//  PUT — manually marks a segment as completed.
//  UPDATED: uses SegmentRepository + AuditSessionRepository + Validator
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';
require_once __DIR__ . '/../../repositories/AuditSessionRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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
    ->required('segment_id', 'session_id')
    ->integer('segment_id', 'session_id')
    ->min('segment_id', 1)
    ->min('session_id', 1);

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
$sessionId = (int)$data['session_id'];

try {
    $segRepo     = new SegmentRepository($pdo);
    $sessionRepo = new AuditSessionRepository($pdo);

    // ── Verify active session ownership ────────────────────────
    $session = $segRepo->findActiveSession($sessionId, $CURRENT_USER_ID);

    if ($session === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session not found, not owned by you, or not active.']);
        exit;
    }

    // ── Verify segment belongs to the session's road ───────────
    if (!$segRepo->belongsToRoad($segmentId, (int)$session['road_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Segment does not belong to this session's road."]);
        exit;
    }

    // ── Mark completed ─────────────────────────────────────────
    $updated = $segRepo->markCompleted($segmentId);

    if (!$updated) {
        echo json_encode(['success' => true, 'already_completed' => true]);
        exit;
    }

    // ── Auto-complete session if all segments done ─────────────
    $sessionRepo->autoCompleteIfReady($sessionId);
    ActivityLogger::log($pdo, ActivityLogger::SEGMENT_COMPLETED, (int)$CURRENT_USER_ID, ['segment_id' => (int)$segmentId, 'session_id' => (int)$sessionId]);

    echo json_encode(['success' => true, 'already_completed' => false]);

} catch (Throwable $e) {
    error_log('api/segments/complete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
