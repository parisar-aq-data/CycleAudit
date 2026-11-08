<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/audit-sessions/create.php
//  POST — creates or resumes an audit session for a road.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log('audit-sessions/create.php uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';
require_once __DIR__ . '/../../repositories/SurveyorWorkRepository.php';

require_once __DIR__ . '/../../config/rate_limit.php';

$rl = checkAndRecordApiRequest($pdo, 'user:' . $CURRENT_USER_ID, 'audit_session_create', 10, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$v = Validator::make($data)
    ->required('road_id')
    ->integer('road_id')
    ->min('road_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

$roadId = (int)$data['road_id'];

try {
    $repo = new RoadRepository($pdo);

    // A project the Admin deleted is closed to everyone: work on it stops.
    if ((new SurveyorWorkRepository($pdo))->projectDeletedForRoad($roadId)) {
        http_response_code(410);
        echo json_encode(['success' => false, 'error' => PROJECT_DELETED_MESSAGE, 'project_deleted' => true]);
        exit;
    }

    // City-audit roads: only a surveyor assigned to a segment of the road may start a session.
    if (!(new SurveyorWorkRepository($pdo))->mayOpenRoad($roadId, (int)$CURRENT_USER_ID)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This road is not assigned to you.']);
        exit;
    }

    // Finalized roads are locked — never spin up a new session for
    // them (this is what "View" on the dashboard hits). Doing so would
    // flip the road's dashboard status back to "Active" even though
    // nothing can actually be edited. Instead, hand back the existing
    // (already-completed) session id so read-only features on that page
    // — e.g. "Download Road Score PDF" — still work.
    $road = $repo->find($roadId);
    if ($road !== null && $road['finalized_at'] !== null) {
        $latest = $repo->findLatestSession($CURRENT_USER_ID, $roadId);
        echo json_encode([
            'success'    => true,
            'session_id' => $latest['id'] ?? null,
            'public_id'  => $latest['public_id'] ?? null,
            'finalized'  => true,
        ]);
        exit;
    }

    // Resume existing active session if one exists
    $existing = $repo->findActiveSession($CURRENT_USER_ID, $roadId);
    if ($existing) {
        echo json_encode([
            'success'    => true,
            'session_id' => $existing['id'],
            'public_id'  => $existing['public_id'],
            'resumed'    => true,
        ]);
        exit;
    }

    // Create new session
    $result = $repo->createSession($CURRENT_USER_ID, $roadId);
    ActivityLogger::log($pdo, ActivityLogger::SESSION_STARTED, (int)$CURRENT_USER_ID, ['road_id' => (int)$roadId, 'session_id' => (int)$result['session_id']]);

    echo json_encode([
        'success'    => true,
        'session_id' => $result['session_id'],
        'public_id'  => $result['public_id'],
        'resumed'    => false,
    ]);

} catch (Throwable $e) {
    error_log('api/audit-sessions/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
