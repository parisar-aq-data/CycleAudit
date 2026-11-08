<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_delete.php
//  POST { audit_id, confirm_name }
//  The Admin deletes a project. To avoid doing this by mistake, confirm_name must
//  be the project's exact name (the Admin types it). The project is not wiped:
//  it is marked 'voided', so it disappears from every dashboard and all work on
//  it stops, while its data stays in the database. The City Leader and the
//  surveyors holding segments in it are told.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/audit_delete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/Cities.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/PublicAudits.php';
require_once __DIR__ . '/../../helpers/Notifications.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/NotificationRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null) {
    cityApiFail(404, 'Project not found.');
}
cityApiFailIfDeleted($audit);

// Typed-name check: the Admin must type the project's name exactly.
$typed = trim((string)($body['confirm_name'] ?? ''));
if ($typed === '' || !hash_equals((string)$audit['name'], $typed)) {
    cityApiFail(422, 'The name you typed does not match this project. Nothing was deleted.', ['errors' => ['confirm_name' => 'Type the project name exactly.']]);
}

// The Admin must say why. The reason goes to the City Leader and the surveyors.
$reason = projectDeleteReasonClean($body['reason'] ?? '');
$reasonError = projectDeleteReasonError($reason);
if ($reasonError !== null) {
    cityApiFail(422, $reasonError, ['errors' => ['reason' => $reasonError]]);
}

$impact      = $repo->deletionImpact((int)$audit['id']);
$surveyorIds = $repo->assignedSurveyorIds((int)$audit['id']);
$leaderId    = $audit['leader_user_id'] !== null ? (int)$audit['leader_user_id'] : null;

$pdo->beginTransaction();
try {
    $repo->deleteAudit($audit);
    // Open work sessions on its roads are archived (the status your database has for read-only sessions; 'abandoned' is not in the live enum), so nobody is left mid-audit on a deleted project.
    $pdo->prepare(
        "UPDATE audit_sessions SET status = 'archived' WHERE status = 'active' AND road_id IN (SELECT id FROM roads WHERE audit_id = ?)"
    )->execute([(int)$audit['id']]);
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    cityApiFail(409, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_DELETED, $CURRENT_USER_ID, [
    'audit_id' => (int)$audit['id'], 'city_id' => (int)$audit['city_id'], 'name' => (string)$audit['name'], 'reason' => $reason,
    'roads' => $impact['roads'], 'segments' => $impact['segments'], 'started' => $impact['started'], 'surveyors' => $impact['surveyors'],
]);

// Tell the City Leader and every surveyor on it. A failure here must never undo the delete.
try {
    $notes = new NotificationRepository($pdo);
    $t = projectDeletedNotificationText((string)$audit['name'], $reason);
    $targets = $surveyorIds;
    if ($leaderId !== null) {
        $targets[] = $leaderId;
    }
    foreach (array_unique($targets) as $uid) {
        $notes->create((int)$uid, NOTIFY_PROJECT_DELETED, $t['title'], $t['body'], null);
    }
    // A surveyor whose removal was waiting on this project's work may be free to go now.
    require_once __DIR__ . '/../../helpers/WorkNotify.php';
    foreach (array_unique($surveyorIds) as $sid) {
        workFinishRemovalIfDone($pdo, (int)$sid);
    }
} catch (Throwable $e) {
    error_log('project delete notification failed: ' . $e->getMessage());
}

publicAuditsCacheClear();
echo json_encode(['success' => true]);
