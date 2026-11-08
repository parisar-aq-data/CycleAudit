<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/create.php
//  POST — creates a new road owned by the logged-in user.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log('create.php uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../helpers/CityScope.php';

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

// Surveyors cannot define roads; they audit what a City Leader assigns.
gate('create_road', $CURRENT_USER_ID, $CURRENT_USER_ROLE);

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$v = Validator::make($data)
    ->required('name')
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

$repo = new RoadRepository($pdo);

// ── Which city is this audit in? ─────────────────────────────────
// Road names are only unique within a city, so every name lookup below
// is scoped to one city. Surveyors and City Leaders always use their own
// city. An Admin has no fixed city: they may pass city_id in the
// body, otherwise the same default as road creation applies (their own
// city, or the only city if exactly one exists).
$cityScope = resolveViewerCityScope($pdo, $CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
if ($cityScope === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account has no city assigned — contact an Admin.']);
    exit;
}
if ($cityScope === null) {
    if (isset($data['city_id'])) {
        $auditCityId = filter_var($data['city_id'], FILTER_VALIDATE_INT);
        $cityCheck = $pdo->prepare('SELECT 1 FROM cities WHERE id = ? LIMIT 1');
        if ($auditCityId !== false) {
            $cityCheck->execute([$auditCityId]);
        }
        if ($auditCityId === false || $cityCheck->fetchColumn() === false) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid city_id.']);
            exit;
        }
    } else {
        try {
            $auditCityId = $repo->resolveCityIdForNewRoadGroup($CURRENT_USER_ID);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'city_id is required — more than one city exists.']);
            exit;
        }
    }
} else {
    $auditCityId = $cityScope;
}

// ── Restrict introduction of brand-new road names to admins ───────
// Regular users may only attach a new audit session to an EXISTING
// road_group in their city (matched by normalized name). This is the
// server-side half of hiding "Other / Custom Road" from non-admins in
// the UI — the UI gate alone wouldn't stop a direct POST to this
// endpoint. A road with the same name in another city doesn't count.
if (!isAnyAdmin($CURRENT_USER_ROLE) && !$repo->roadGroupExists((string)$data['name'], $auditCityId)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'That road isn\'t in the list yet. Only admins can add new roads — please ask an admin to add it first.',
    ]);
    exit;
}

// ── Assigned roads are reserved for their assigned surveyor ──────
// Confirmed scope (Sep 30): City Leader adds roads and assigns them
// to surveyors. An unassigned road stays open to any surveyor in
// the city (unchanged self-service behaviour); an assigned one is
// reserved for that surveyor only. Admins bypass this, same as the
// check above.
if (!isAnyAdmin($CURRENT_USER_ROLE)) {
    $assignedTo = $repo->getAssignedSurveyorId((string)$data['name'], $auditCityId);
    if ($assignedTo !== null && $assignedTo !== $CURRENT_USER_ID) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'This road has been assigned to a different surveyor by your City Leader.',
        ]);
        exit;
    }
}

try {
    $result = $repo->create($CURRENT_USER_ID, $data, $auditCityId);
    ActivityLogger::log($pdo, ActivityLogger::ROAD_CREATED, (int)$CURRENT_USER_ID, ['road_id' => (int)$result['road_id'], 'name' => (string)($data['name'] ?? '')]);

    echo json_encode([
        'success'   => true,
        'road_id'   => $result['road_id'],
        'public_id' => $result['public_id'],
    ]);

} catch (Throwable $e) {
    error_log('api/roads/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
