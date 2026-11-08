<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/WorkNotify.php
//  Who is told what as work moves along (assigned -> submitted ->
//  approved / sent back -> audit closed -> approved by the Admin),
//  and when a surveyor is removed. Every function swallows its own
//  errors: a notification must never undo or fail the action itself.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/SurveyorRemoval.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../repositories/SurveyorRemovalRepository.php';

function workAuditLink(array $audit): string
{
    return 'city_audit.php?id=' . (int)$audit['id'];
}

/**
 * A surveyor was removed (or moved to another city).
 *
 * @param array{id:int,name:string}                                  $target
 * @param array{release:list<array>, keep:list<array>}               $plan
 * @param string $mode   'now' | 'wait'
 * @param string $action 'team' | 'deactivate'
 */
function workNotifyRemoval(PDO $pdo, array $target, array $plan, string $mode, string $action, ?string $moveTo = null): void
{
    try {
        $notes    = new NotificationRepository($pdo);
        $released = count($plan['release']);
        $kept     = count($plan['keep']);

        if ($moveTo !== null) {
            $back = $released > 0
                ? ' The ' . notifyPlural($released, 'segment', 'segments') . ' you had not started ' . ($released === 1 ? 'was' : 'were') . ' taken off your dashboard.'
                : '';
            $notes->create((int)$target['id'], NOTIFY_REMOVAL, 'You moved to another city',
                'The Admin moved you to ' . $moveTo . '.' . $back, 'dashboard.php');
        } elseif ($mode === 'wait' || $action === REMOVAL_ACTION_TEAM || $released > 0) {
            $t = surveyorRemovedNotificationText($mode, $action, $released, $kept);
            $notes->create((int)$target['id'], NOTIFY_REMOVAL, $t['title'], $t['body'], 'dashboard.php');
        }

        // City Leaders of the audits the freed segments belong to.
        foreach (surveyorRemovalCountByAudit($plan['release']) as $a) {
            if ($a['leader_user_id'] !== null) {
                $t = surveyorRemovalLeaderNotificationText((string)$target['name'], $a['name'], $a['count']);
                $notes->create($a['leader_user_id'], NOTIFY_REMOVAL, $t['title'], $t['body'], 'city_audit.php?id=' . $a['audit_id']);
            }
        }
        // ...and the ones whose surveyor has to finish first.
        if ($mode === 'wait') {
            $told = [];
            foreach (surveyorRemovalCountByAudit($plan['keep']) as $a) {
                if ($a['leader_user_id'] !== null && !isset($told[$a['leader_user_id']])) {
                    $told[$a['leader_user_id']] = true;
                    $t = surveyorRemovalWaitingNotificationText((string)$target['name'], $kept);
                    $notes->create($a['leader_user_id'], NOTIFY_REMOVAL, $t['title'], $t['body'], 'city_audit.php?id=' . $a['audit_id']);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('workNotifyRemoval failed: ' . $e->getMessage());
    }
}

/** A waiting removal has just been carried out. @param array{action:string,requested_by:?int,name:string,leaders:list<int>} $done */
function workNotifyRemovalDone(PDO $pdo, int $surveyorId, array $done): void
{
    try {
        $notes = new NotificationRepository($pdo);
        $t = surveyorRemovalDoneNotificationText($done['action']);
        $notes->create($surveyorId, NOTIFY_REMOVAL, $t['title'], $t['body'], null);
        $who = $done['leaders'];
        if ($done['requested_by'] !== null) {
            $who[] = $done['requested_by'];
        }
        $l = surveyorRemovalDoneLeaderNotificationText($done['name']);
        $notes->tellMany(array_diff($who, [$surveyorId]), NOTIFY_REMOVAL, $l['title'], $l['body'], null);
    } catch (Throwable $e) {
        error_log('workNotifyRemovalDone failed: ' . $e->getMessage());
    }
}

/** After a surveyor submits a segment: tell the City Leader, then finish a waiting removal if that was the last one. */
function workAfterSubmit(PDO $pdo, int $segmentId, int $surveyorId): void
{
    try {
        $q = $pdo->prepare(
            'SELECT a.id, a.name, a.leader_user_id, u.name AS surveyor_name
               FROM segments s JOIN roads r ON r.id = s.road_id
               JOIN city_audits a ON a.id = r.audit_id
               JOIN users u ON u.id = ?
              WHERE s.id = ?'
        );
        $q->execute([$surveyorId, $segmentId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && $row['leader_user_id'] !== null) {
            $t = segmentSubmittedNotificationText((string)$row['surveyor_name'], (string)$row['name']);
            (new NotificationRepository($pdo))->create((int)$row['leader_user_id'], NOTIFY_WORK, $t['title'], $t['body'], workAuditLink($row));
        }
    } catch (Throwable $e) {
        error_log('workAfterSubmit notify failed: ' . $e->getMessage());
    }
    workFinishRemovalIfDone($pdo, $surveyorId);
}

/** Carry out a waiting removal when nothing of theirs is in progress any more, and tell the people involved. */
function workFinishRemovalIfDone(PDO $pdo, int $surveyorId): void
{
    try {
        $done = (new SurveyorRemovalRepository($pdo))->completeIfDone($surveyorId);
        if ($done !== null) {
            workNotifyRemovalDone($pdo, $surveyorId, $done);
        }
    } catch (Throwable $e) {
        error_log('workFinishRemovalIfDone failed: ' . $e->getMessage());
    }
}

/**
 * The City Leader reviewed a segment.
 *
 * @param int         $surveyorId   who held it
 * @param string      $action       'approve' | 'send_back'
 * @param int|null    $newSurveyor  set when a send-back hands it to someone else
 */
function workNotifyReview(PDO $pdo, array $audit, int $surveyorId, string $action, string $note, ?int $newSurveyor): void
{
    try {
        $notes = new NotificationRepository($pdo);
        $name  = (string)$audit['name'];
        if ($action === 'approve') {
            $t = segmentApprovedNotificationText($name);
            $notes->create($surveyorId, NOTIFY_WORK, $t['title'], $t['body'], 'dashboard.php');
            return;
        }
        if ($newSurveyor !== null && $newSurveyor !== $surveyorId) {
            $t = unassignmentNotificationText($name, 1, 'moved');
            $notes->create($surveyorId, NOTIFY_UNASSIGNED, $t['title'], $t['body'], 'dashboard.php');
            $t = segmentSentBackNotificationText($name, $note, true);
            $notes->create($newSurveyor, NOTIFY_WORK, $t['title'], $t['body'], 'dashboard.php');
            return;
        }
        $t = segmentSentBackNotificationText($name, $note, false);
        $notes->create($surveyorId, NOTIFY_WORK, $t['title'], $t['body'], 'dashboard.php');
    } catch (Throwable $e) {
        error_log('workNotifyReview failed: ' . $e->getMessage());
    }
}

/** The audit was activated: every surveyor with segments in it is told it is open. */
function workNotifyActivated(PDO $pdo, array $audit): void
{
    try {
        $notes = new NotificationRepository($pdo);
        foreach ($notes->surveyorsOfAudit((int)$audit['id']) as $uid => $n) {
            $t = auditActivatedNotificationText((string)$audit['name'], $n);
            $notes->create($uid, NOTIFY_WORK, $t['title'], $t['body'], 'dashboard.php');
        }
    } catch (Throwable $e) {
        error_log('workNotifyActivated failed: ' . $e->getMessage());
    }
}

/** The City Leader closed the audit: its surveyors are thanked. */
function workNotifyClosed(PDO $pdo, array $audit): void
{
    try {
        $notes = new NotificationRepository($pdo);
        $t = auditClosedNotificationText((string)$audit['name']);
        $notes->tellMany(array_keys($notes->surveyorsOfAudit((int)$audit['id'])), NOTIFY_WORK, $t['title'], $t['body'], 'dashboard.php');
    } catch (Throwable $e) {
        error_log('workNotifyClosed failed: ' . $e->getMessage());
    }
}

/** The audit was sent to the Admin for approval: every active Admin is told. */
function workNotifySentToAdmin(PDO $pdo, array $audit): void
{
    try {
        $notes = new NotificationRepository($pdo);
        $t = auditSentToAdminNotificationText((string)$audit['name'], (string)($audit['city_name'] ?? 'a city'));
        $notes->tellMany($notes->activeAdminIds(), NOTIFY_WORK, $t['title'], $t['body'], null);
    } catch (Throwable $e) {
        error_log('workNotifySentToAdmin failed: ' . $e->getMessage());
    }
}

/** The Admin approved or returned the audit: its City Leader is told. */
function workNotifyAdminDecision(PDO $pdo, array $audit, string $action, string $note): void
{
    try {
        if (!isset($audit['leader_user_id']) || $audit['leader_user_id'] === null) {
            return;
        }
        $t = $action === 'approve'
            ? auditApprovedNotificationText((string)$audit['name'])
            : auditReturnedNotificationText((string)$audit['name'], $note);
        (new NotificationRepository($pdo))->create((int)$audit['leader_user_id'], NOTIFY_WORK, $t['title'], $t['body'], workAuditLink($audit));
    } catch (Throwable $e) {
        error_log('workNotifyAdminDecision failed: ' . $e->getMessage());
    }
}
