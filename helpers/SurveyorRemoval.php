<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/SurveyorRemoval.php
//  The rule for removing a surveyor who still has assigned segments.
//  Pure functions (no DB, no session), so they are unit-tested.
//
//    - Segments they have NOT started go back to unassigned right away.
//    - Segments they HAVE started (auditing begun, an active session on
//      the road, or sent back for a revisit) stay with them. They finish,
//      and the removal happens when the last one is submitted.
//    - Segments already submitted or approved are finished work: they
//      stay on record and never hold a removal back.
// ═══════════════════════════════════════════════════════════════

/** Audit statuses in which a surveyor can still have work to do. */
const REMOVAL_OPEN_AUDIT_STATUSES = ['draft', 'active', 'in_review'];

const REMOVAL_ACTION_TEAM       = 'team';
const REMOVAL_ACTION_DEACTIVATE = 'deactivate';

/**
 * Sort a surveyor's assignment rows into "give back now" and "let them finish".
 * Each row needs: audit_status, assignment_status, segment_status, active_sessions.
 *
 * @param  list<array<string,mixed>> $rows
 * @return array{release:list<array<string,mixed>>, keep:list<array<string,mixed>>}
 */
function surveyorRemovalClassify(array $rows): array
{
    $release = [];
    $keep    = [];
    foreach ($rows as $r) {
        if (!in_array((string)$r['audit_status'], REMOVAL_OPEN_AUDIT_STATUSES, true)) {
            continue;   // finished, published or deleted audits: nothing left to do
        }
        switch ((string)$r['assignment_status']) {
            case 'assigned':
                $started = (string)$r['segment_status'] !== 'pending' || (int)($r['active_sessions'] ?? 0) > 0;
                if ($started) {
                    $keep[] = $r;
                } else {
                    $release[] = $r;
                }
                break;
            case 'needs_revisit':
                $keep[] = $r;   // sent back to them: they have to redo it
                break;
            default:
                break;          // submitted / approved
        }
    }
    return ['release' => $release, 'keep' => $keep];
}

/** 'now' when nothing is in progress, 'wait' when they must finish first. */
function surveyorRemovalOutcome(array $plan): string
{
    return count($plan['keep']) === 0 ? 'now' : 'wait';
}

/**
 * How many of these rows sit in each audit.
 *
 * @param  list<array<string,mixed>> $rows
 * @return array<int,array{audit_id:int,name:string,leader_user_id:?int,count:int}>  keyed by audit id
 */
function surveyorRemovalCountByAudit(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['audit_id'];
        if (!isset($out[$id])) {
            $out[$id] = [
                'audit_id'       => $id,
                'name'           => (string)$r['audit_name'],
                'leader_user_id' => isset($r['leader_user_id']) && $r['leader_user_id'] !== null ? (int)$r['leader_user_id'] : null,
                'count'          => 0,
            ];
        }
        $out[$id]['count']++;
    }
    return $out;
}

/** The sentence shown to whoever asked for the removal. */
function surveyorRemovalMessage(string $name, string $action, string $mode, int $released, int $kept): string
{
    $parts  = [];
    if ($mode === 'wait') {
        $parts[] = $name . ' has ' . $kept . ' segment' . ($kept === 1 ? '' : 's') . ' in progress. They stay until they submit '
                 . ($kept === 1 ? 'it' : 'them') . ', then ' . ($action === REMOVAL_ACTION_DEACTIVATE ? 'are deactivated' : 'leave your team') . ' automatically.';
    } else {
        $parts[] = $name . ' ' . ($action === REMOVAL_ACTION_DEACTIVATE ? 'was deactivated.' : 'was removed from your team.');
    }
    if ($released > 0) {
        $parts[] = $released . ' segment' . ($released === 1 ? ' they had' : 's they had') . ' not started ' . ($released === 1 ? 'is' : 'are') . ' unassigned again, and they were told.';
    }
    return implode(' ', $parts);
}
