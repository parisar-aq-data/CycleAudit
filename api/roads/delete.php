<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/delete.php
//  POST — removes a road and all its cascading children.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log('delete.php uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';

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

    $road = $repo->find($roadId);
    if ($road === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    gate('delete_road', $CURRENT_USER_ID, $CURRENT_USER_ROLE, ['owner_id' => $road['creator_id'], 'city_id' => $road['city_id']]);

    $repo->delete($roadId);
    ActivityLogger::log($pdo, ActivityLogger::ROAD_DELETED, (int)$CURRENT_USER_ID, ['road_id' => (int)$roadId, 'name' => (string)($road['name'] ?? '')]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('api/roads/delete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
