<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_activate.php
//  POST { audit_id }
//  Moves a draft audit to active once at least one segment has a surveyor.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_activate.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/CityAudit.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../helpers/WorkNotify.php';

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID)) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);

try {
    $repo->activate($audit);
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_ACTIVATED, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id']]);
workNotifyActivated($pdo, $audit);

echo json_encode(['success' => true, 'status' => 'active']);
