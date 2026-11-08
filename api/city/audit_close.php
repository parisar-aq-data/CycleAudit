<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_close.php
//  POST { audit_id, action: "close" | "send" }
//  close: every segment approved -> audit becomes finalised (report ready).
//  send:  finalised audit goes to the Admin (awaiting_approval).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_close.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../../helpers/WorkNotify.php';

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID)) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);

$action = (string)($body['action'] ?? '');
if (!in_array($action, ['close', 'send'], true)) {
    cityApiFail(422, 'Unknown action.');
}

$review = new AuditReviewRepository($pdo);
try {
    if ($action === 'close') {
        $review->close($audit);
        ActivityLogger::log($pdo, ActivityLogger::AUDIT_CLOSED, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id']]);
        workNotifyClosed($pdo, $audit);
        echo json_encode(['success' => true, 'status' => 'finalised']);
    } else {
        $review->sendToAdmin($audit);
        ActivityLogger::log($pdo, ActivityLogger::AUDIT_SENT_TO_ADMIN, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id']]);
        workNotifySentToAdmin($pdo, $audit);
        echo json_encode(['success' => true, 'status' => 'awaiting_approval']);
    }
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}
