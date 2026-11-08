<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Notifications.php
//  The wording of notifications. Pure functions (no DB, no session),
//  so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

const NOTIFY_ASSIGNED = 'segments_assigned';
const NOTIFY_UNASSIGNED = 'segments_unassigned';
const NOTIFY_PROJECT_DELETED = 'project_deleted';
const NOTIFY_PROJECT_UPDATED = 'project_updated';
const NOTIFY_LEADER_CHANGED  = 'city_leader_changed';
const NOTIFY_PROJECT_ASSIGNED = 'project_assigned';

/** "1 segment" / "3 segments". */
function notifyPlural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/**
 * What a surveyor is told when a City Leader assigns them work.
 *
 * @param string      $auditName   the audit's name
 * @param string      $auditStatus draft | active | in_review | ... (city_audits.status)
 * @param int         $segments    how many segments were assigned
 * @param string|null $roadName    set when a whole road was assigned
 * @return array{title:string, body:string}
 */
function assignmentNotificationText(string $auditName, string $auditStatus, int $segments, ?string $roadName): array
{
    $what = $roadName !== null && $roadName !== ''
        ? notifyPlural($segments, 'segment', 'segments') . ' of ' . $roadName
        : notifyPlural($segments, 'segment', 'segments');

    $body = 'Your City Leader assigned you ' . $what . ' in the audit "' . $auditName . '".';
    $body .= $auditStatus === 'draft'
        ? ' You can start once the audit is activated.'
        : ' You can start from your dashboard.';

    return ['title' => 'New assignment', 'body' => $body];
}

/**
 * What a surveyor is told when segments they were given are taken away again:
 * moved to another surveyor, cleared, or deleted along with the segment / road.
 *
 * @param string $reason 'moved' | 'cleared' | 'removed'
 * @return array{title:string, body:string}
 */
function unassignmentNotificationText(string $auditName, int $segments, string $reason): array
{
    $what = notifyPlural($segments, 'segment', 'segments');
    $tail = ' ' . ($segments === 1 ? 'It is' : 'They are') . ' no longer on your dashboard.';
    switch ($reason) {
        case 'removed':
            return ['title' => 'Assignment removed',
                    'body'  => 'Your City Leader removed ' . $what . ' that you were assigned from the audit "' . $auditName . '".' . $tail];
        case 'cleared':
            return ['title' => 'Assignment removed',
                    'body'  => 'Your City Leader took you off ' . $what . ' in the audit "' . $auditName . '".' . $tail];
        default:
            return ['title' => 'Assignment changed',
                    'body'  => 'Your City Leader moved ' . $what . ' in the audit "' . $auditName . '" to another surveyor.' . $tail];
    }
}

/**
 * Who has to be told after an assignment change.
 *
 * @param array<int,int> $before  segment id => surveyor who held it before ($holders may omit unassigned segments)
 * @param list<int>      $segmentIds every segment being changed
 * @param int|null       $newSurveyorId the new surveyor, null when clearing
 * @return array{gained:int, lost:array<int,int>}
 *         gained = segments the new surveyor did not already have;
 *         lost   = surveyor id => how many segments they lose
 */
function assignmentNotifyPlan(array $before, array $segmentIds, ?int $newSurveyorId): array
{
    $gained = 0;
    $lost   = [];
    foreach (array_unique(array_map('intval', $segmentIds)) as $segId) {
        $old = $before[$segId] ?? null;
        if ($newSurveyorId !== null && $old !== $newSurveyorId) {
            $gained++;
        }
        if ($old !== null && $old !== $newSurveyorId) {
            $lost[$old] = ($lost[$old] ?? 0) + 1;
        }
    }
    return ['gained' => $gained, 'lost' => $lost];
}

const PROJECT_DELETE_REASON_MIN = 5;
const PROJECT_DELETE_REASON_MAX = 200;

/** The reason as typed, with control characters removed and spaces tidied. */
function projectDeleteReasonClean(mixed $raw): string
{
    $s = is_string($raw) ? $raw : '';
    $s = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** Why the Admin's reason for deleting a project cannot be used (null = fine). */
function projectDeleteReasonError(string $cleanReason): ?string
{
    $n = mb_strlen($cleanReason);
    if ($n < PROJECT_DELETE_REASON_MIN) {
        return 'Write the reason for deleting this project (at least ' . PROJECT_DELETE_REASON_MIN . ' characters). The City Leader and surveyors will read it.';
    }
    if ($n > PROJECT_DELETE_REASON_MAX) {
        return 'The reason can be at most ' . PROJECT_DELETE_REASON_MAX . ' characters.';
    }
    return null;
}

/** What people on a project are told when the Admin deletes it, with the Admin's reason. */
function projectDeletedNotificationText(string $auditName, string $reason = ''): array
{
    $body = 'The Admin deleted the project "' . $auditName . '". Work on it has stopped and it no longer appears on your dashboard.';
    if ($reason !== '') {
        $body .= ' Reason: ' . $reason;
    }
    return [
        'title' => 'Project deleted',
        'body'  => mb_substr($body, 0, 500),   // notifications.body is VARCHAR(500)
    ];
}

/**
 * What the City Leader is told when the Admin edits a project.
 *
 * @param list<string> $changed  human labels, e.g. ['name', 'audit date', 'programme info']
 * @return array{title:string, body:string}
 */
function projectUpdatedNotificationText(string $auditName, array $changed): array
{
    $what = count($changed) > 1
        ? implode(', ', array_slice($changed, 0, -1)) . ' and ' . end($changed)
        : (string)($changed[0] ?? 'details');
    return [
        'title' => 'Project updated',
        'body'  => 'The Admin updated the ' . $what . ' of the project "' . $auditName . '".',
    ];
}

/** What a person is told when the Admin changes who leads a city. */
function cityLeaderChangedNotificationText(string $cityName, bool $nowLeader): array
{
    return $nowLeader
        ? ['title' => 'You are now City Leader', 'body' => 'The Admin made you the City Leader of ' . $cityName . '. Open your City Dashboard to manage its projects.']
        : ['title' => 'City Leader changed', 'body' => 'The Admin made someone else the City Leader of ' . $cityName . '. You are now a Surveyor.'];
}

/** What a City Leader is told when the Admin assigns a project to them. */
function projectAssignedNotificationText(string $auditName): array
{
    return [
        'title' => 'New project assigned',
        'body'  => 'The Admin assigned the project "' . $auditName . '" to you. Open it to add roads and assign surveyors.',
    ];
}

/** What a City Leader is told when the Admin hands their project to another City Leader. */
function projectUnassignedNotificationText(string $auditName): array
{
    return [
        'title' => 'Project reassigned',
        'body'  => 'The Admin assigned the project "' . $auditName . '" to another City Leader. It no longer appears on your dashboard.',
    ];
}

// ── Removing a surveyor who still has work (helpers/SurveyorRemoval.php) ──

const NOTIFY_REMOVAL = 'surveyor_removal';
/** Progress of the work itself: submitted, approved, sent back, audit opened / closed. */
const NOTIFY_WORK = 'work_status';

/**
 * What the surveyor is told when they are removed.
 *
 * @param string $mode 'now' (gone straight away) | 'wait' (finish the started segments first)
 * @param string $action 'team' | 'deactivate'
 * @return array{title:string, body:string}
 */
function surveyorRemovedNotificationText(string $mode, string $action, int $released, int $kept): array
{
    $back = $released > 0
        ? ' The ' . notifyPlural($released, 'segment', 'segments') . ' you had not started ' . ($released === 1 ? 'was' : 'were') . ' taken off your dashboard.'
        : '';
    $what = $action === 'deactivate' ? 'You were removed from Parisar.' : 'You were removed from your City Leader\'s team.';
    if ($mode === 'wait') {
        return [
            'title' => 'Finish your work, then you are removed',
            'body'  => $what . ' Please finish the ' . notifyPlural($kept, 'segment', 'segments') . ' you already started.'
                     . ' The removal takes effect once you submit ' . ($kept === 1 ? 'it' : 'them') . '.' . $back,
        ];
    }
    return [
        'title' => $action === 'deactivate' ? 'Your account was deactivated' : 'Removed from the team',
        'body'  => $what . $back,
    ];
}

/** What the surveyor is told once their last started segment is in and the removal happens. */
function surveyorRemovalDoneNotificationText(string $action): array
{
    return [
        'title' => 'Removal complete',
        'body'  => $action === 'deactivate'
            ? 'You submitted all your started segments, so your account is now deactivated. Thank you for your audits.'
            : 'You submitted all your started segments, so you are no longer in your City Leader\'s team. Thank you for your audits.',
    ];
}

/** What a City Leader is told about segments that went back to unassigned. */
function surveyorRemovalLeaderNotificationText(string $surveyorName, string $auditName, int $released): array
{
    return [
        'title' => 'Segments are unassigned again',
        'body'  => $surveyorName . ' was removed. ' . notifyPlural($released, 'segment', 'segments') . ' in the audit "' . $auditName
                 . '" ' . ($released === 1 ? 'is' : 'are') . ' unassigned again. Assign ' . ($released === 1 ? 'it' : 'them') . ' to another surveyor.',
    ];
}

/** What a City Leader is told when the removal has to wait for started work. */
function surveyorRemovalWaitingNotificationText(string $surveyorName, int $kept): array
{
    return [
        'title' => 'Removal waiting for work in progress',
        'body'  => $surveyorName . ' has ' . notifyPlural($kept, 'segment', 'segments') . ' in progress. They are removed automatically once they submit '
                 . ($kept === 1 ? 'it' : 'them') . '.',
    ];
}

/** What a City Leader is told when the waiting removal happens. */
function surveyorRemovalDoneLeaderNotificationText(string $surveyorName): array
{
    return [
        'title' => 'Surveyor removed',
        'body'  => $surveyorName . ' submitted their last started segment and has now been removed.',
    ];
}

// ── Progress of the work ─────────────────────────────────────

/** City Leader: a surveyor submitted a segment. */
function segmentSubmittedNotificationText(string $surveyorName, string $auditName): array
{
    return [
        'title' => 'Segment submitted',
        'body'  => $surveyorName . ' submitted a segment in the audit "' . $auditName . '". It is waiting for your review.',
    ];
}

/** Surveyor: the City Leader approved their segment. */
function segmentApprovedNotificationText(string $auditName): array
{
    return [
        'title' => 'Segment approved',
        'body'  => 'Your City Leader approved a segment you submitted in the audit "' . $auditName . '".',
    ];
}

/**
 * Surveyor: a segment was sent back for a revisit.
 * @param bool $handedOver true for the surveyor who is given the segment by someone else's send-back
 */
function segmentSentBackNotificationText(string $auditName, string $note, bool $handedOver): array
{
    $note = mb_substr(trim($note), 0, 250);
    return [
        'title' => 'Segment needs a revisit',
        'body'  => ($handedOver
                ? 'Your City Leader gave you a segment to re-audit in "' . $auditName . '".'
                : 'Your City Leader sent a segment back in the audit "' . $auditName . '".')
                . ($note !== '' ? ' Note: ' . $note : ''),
    ];
}

/** Surveyor: the audit they were assigned to is now open. */
function auditActivatedNotificationText(string $auditName, int $segments): array
{
    return [
        'title' => 'Audit is open',
        'body'  => 'The audit "' . $auditName . '" is now active. You can start your ' . notifyPlural($segments, 'segment', 'segments') . ' from your dashboard.',
    ];
}

/** Surveyor: the City Leader closed the audit. */
function auditClosedNotificationText(string $auditName): array
{
    return [
        'title' => 'Audit closed',
        'body'  => 'The audit "' . $auditName . '" was closed. Thank you for your work.',
    ];
}

/** Admin: a City Leader sent an audit for approval. */
function auditSentToAdminNotificationText(string $auditName, string $cityName): array
{
    return [
        'title' => 'Audit waiting for approval',
        'body'  => 'The City Leader of ' . $cityName . ' sent the audit "' . $auditName . '" for your approval.',
    ];
}

/** City Leader: the Admin approved the audit. */
function auditApprovedNotificationText(string $auditName): array
{
    return [
        'title' => 'Audit approved',
        'body'  => 'The Admin approved the audit "' . $auditName . '". It is now published.',
    ];
}

/** City Leader: the Admin sent the audit back. */
function auditReturnedNotificationText(string $auditName, string $note): array
{
    $note = mb_substr(trim($note), 0, 250);
    return [
        'title' => 'Audit returned',
        'body'  => 'The Admin sent the audit "' . $auditName . '" back for changes.' . ($note !== '' ? ' Note: ' . $note : ''),
    ];
}
