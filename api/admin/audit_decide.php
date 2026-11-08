<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_decide.php
//  POST { audit_id, action: "approve" | "return", note (required for return) }
//  The Admin decides on an audit a City Leader sent for approval.
//  approve: audit becomes published (the report is final).
//  return:  audit goes back to the City Leader (in_review) with the
//           Admin's note saying what has to change.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/audit_decide.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/PublicAudits.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../../helpers/WorkNotify.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? (new CityAuditRepository($pdo))->find((int)$auditId) : null;
if ($audit === null) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);

$action = (string)($body['action'] ?? '');
if (!in_array($action, ['approve', 'return'], true)) {
    cityApiFail(422, 'Unknown action.');
}

$review = new AuditReviewRepository($pdo);
try {
    if ($action === 'approve') {
        $review->approveAudit($audit, (int)$CURRENT_USER_ID);
        ActivityLogger::log($pdo, ActivityLogger::AUDIT_APPROVED, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id']]);
        workNotifyAdminDecision($pdo, $audit, 'approve', '');
        publicAuditsCacheClear();
        echo json_encode(['success' => true, 'status' => 'published']);
    } else {
        $review->returnAudit($audit, (int)$CURRENT_USER_ID, (string)($body['note'] ?? ''));
        ActivityLogger::log($pdo, ActivityLogger::AUDIT_RETURNED, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id']]);
        workNotifyAdminDecision($pdo, $audit, 'return', (string)($body['note'] ?? ''));
        echo json_encode(['success' => true, 'status' => 'in_review']);
    }
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}
