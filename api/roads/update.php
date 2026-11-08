<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/update.php
//  POST — updates an existing road owned by the logged-in user.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

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
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$v = Validator::make($data)
    ->required('road_id', 'name')
    ->integer('road_id')
    ->min('road_id', 1)
    ->maxLength('name', 255)
    ->in('segment_method', ['auto', 'manual'])
    ->numeric('total_length', 'segment_length');

if (isset($data['name']) && mb_strlen(trim((string)$data['name'])) < 3) {
    $v->addError('Road name must be at least 3 characters.');
}
$segmentMethod = trim((string)($data['segment_method'] ?? 'auto'));
$segmentLength = isset($data['segment_length']) ? (float)$data['segment_length'] : null;
if ($segmentMethod === 'auto' && ($segmentLength === null || $segmentLength <= 0)) {
    $v->addError('segment_length is required and must be > 0 when segment_method is "auto".');
}
$totalLength = isset($data['total_length']) ? (float)$data['total_length'] : null;
if ($totalLength !== null && $totalLength <= 0) {
    $v->addError('total_length must be a positive number.');
}

if ($v->fails()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $v->allErrors()]);
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

    gate('edit_road', $CURRENT_USER_ID, $CURRENT_USER_ROLE, ['owner_id' => $road['creator_id'], 'city_id' => $road['city_id']]);

    $repo->update($roadId, $CURRENT_USER_ID, $data);
    ActivityLogger::log($pdo, ActivityLogger::ROAD_UPDATED, (int)$CURRENT_USER_ID, ['road_id' => (int)$roadId]);

    echo json_encode(['success' => true, 'road_id' => $roadId]);

} catch (Throwable $e) {
    error_log('api/roads/update.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
