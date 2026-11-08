<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/ActivityLogView.php
//  Turns activity_log rows (an action code plus a bag of ids in `meta`)
//  into what the Admin reads on the Activity Log page: who, what, details.
//  Pure functions, apart from activityLoadNames() which reads names from the
//  database so ids can be shown as names.
// ═══════════════════════════════════════════════════════════════

/** Filter groups on the Activity Log page: key => label. */
function activityCategories(): array
{
    return [
        'account'   => 'Sign-in and accounts',
        'passwords' => 'Passwords',
        'people'    => 'Users and teams',
        'projects'  => 'Projects',
        'fieldwork' => 'Roads and segments',
        'public'    => 'Home page',
        'exports'   => 'Downloads',
        'other'     => 'Other',
    ];
}

/** action code => [label, category]. Every ActivityLogger constant must be here (a test checks). */
function activityActionMap(): array
{
    return [
        // sign-in and accounts
        'user_login'        => ['Signed in', 'account'],
        'user_login_failed' => ['Failed sign-in', 'account'],
        'user_logout'       => ['Signed out', 'account'],
        'user_registered'   => ['Account created', 'account'],
        'profile_updated'   => ['Profile updated', 'account'],
        // passwords
        'password_changed'          => ['Password changed', 'passwords'],
        'password_reset_requested'  => ['Password reset requested', 'passwords'],
        'password_reset_completed'  => ['Password reset with a link', 'passwords'],
        'user_password_reset'       => ['Password reset by staff', 'passwords'],
        'password_request_approved'  => ['Password request approved', 'passwords'],
        'password_request_dismissed' => ['Password request dismissed', 'passwords'],
        // users and teams
        'user_role_changed' => ['Role changed', 'people'],
        'user_city_changed' => ['City changed', 'people'],
        'user_activated'    => ['User activated', 'people'],
        'user_deactivated'  => ['User deactivated', 'people'],
        'team_member_added'   => ['Team member added', 'people'],
        'team_member_removed' => ['Team member removed', 'people'],
        // projects
        'audit_created'          => ['Project created', 'projects'],
        'audit_updated'          => ['Project edited', 'projects'],
        'audit_deleted'          => ['Project deleted', 'projects'],
        'audit_activated'        => ['Project started', 'projects'],
        'audit_closed'           => ['Project finalised', 'projects'],
        'audit_sent_to_admin'    => ['Sent to Admin for approval', 'projects'],
        'audit_approved'         => ['Project approved', 'projects'],
        'audit_returned'         => ['Project returned', 'projects'],
        'audit_road_added'       => ['Road added to project', 'projects'],
        'audit_road_removed'     => ['Road removed from project', 'projects'],
        'audit_segment_added'    => ['Segment added to project', 'projects'],
        'audit_segment_removed'  => ['Segment removed from project', 'projects'],
        'audit_segments_assigned' => ['Segments assigned', 'projects'],
        'audit_segment_approved'  => ['Segment approved', 'projects'],
        'audit_segment_sent_back' => ['Segment sent back', 'projects'],
        // roads and segments (field work)
        'segment_submitted' => ['Segment submitted', 'fieldwork'],
        'segment_edited'    => ['Segment edited', 'fieldwork'],
        'segment_completed' => ['Segment completed', 'fieldwork'],
        'segment_reset'     => ['Segment form reset', 'fieldwork'],
        'segment_unlocked'  => ['Segment unlocked', 'fieldwork'],
        'session_started'   => ['Audit session started', 'fieldwork'],
        'session_closed'    => ['Audit session closed', 'fieldwork'],
        'road_created'      => ['Road created', 'fieldwork'],
        'road_updated'      => ['Road edited', 'fieldwork'],
        'road_deleted'      => ['Road deleted', 'fieldwork'],
        'road_finalized'    => ['Road finalised', 'fieldwork'],
        'road_segments_saved' => ['Road segments saved', 'fieldwork'],
        'road_group_created'  => ['Road added to catalogue', 'fieldwork'],
        'road_group_deleted'  => ['Road removed from catalogue', 'fieldwork'],
        // home page
        'audit_unpublished'     => ['Audit hidden from home page', 'public'],
        'audit_republished'     => ['Audit shown on home page', 'public'],
        'past_audit_added'      => ['Past audit added', 'public'],
        'past_audit_deleted'    => ['Past audit deleted', 'public'],
        'collaborator_added'    => ['Collaborator added', 'public'],
        'collaborator_updated'  => ['Collaborator edited', 'public'],
        'collaborator_shown'    => ['Collaborator shown', 'public'],
        'collaborator_hidden'   => ['Collaborator hidden', 'public'],
        'collaborator_removed'  => ['Collaborator removed', 'public'],
        // downloads
        'export_downloaded' => ['Downloaded a file', 'exports'],
    ];
}

/** @return array{label:string, category:string, tone:string} tone is good, bad or neutral (pill colour). */
function activityActionInfo(string $action): array
{
    $map = activityActionMap();
    if (isset($map[$action])) {
        [$label, $category] = $map[$action];
    } else {
        $label    = ucfirst(str_replace('_', ' ', $action));
        $category = 'other';
    }
    $tone = 'neutral';
    foreach (['deleted', 'removed', 'failed', 'deactivated', 'returned', 'sent_back', 'unpublished', 'hidden', 'dismissed'] as $w) {
        if (str_contains($action, $w)) {
            $tone = 'bad';
            break;
        }
    }
    if ($tone === 'neutral') {
        foreach (['created', 'approved', 'activated', 'added', 'registered', 'republished', 'shown', 'completed', 'submitted', 'login'] as $w) {
            if (str_contains($action, $w)) {
                $tone = 'good';
                break;
            }
        }
    }
    return ['label' => $label, 'category' => $category, 'tone' => $tone];
}

function activityRoleLabel(?string $role): string
{
    return match ($role) {
        'admin'       => 'Admin',
        'city_leader' => 'City Leader',
        'surveyor'    => 'Surveyor',
        default       => $role !== null && $role !== '' ? ucfirst(str_replace('_', ' ', $role)) : '',
    };
}

/** "6 Nov 2026, 12:54 AM" in Indian time, from a UTC database timestamp. */
function activityWhen(?string $utc): string
{
    if ($utc === null || trim($utc) === '') {
        return '—';
    }
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $d->format('j M Y, g:i A');
    } catch (Exception $e) {
        return $utc;
    }
}

/**
 * Every id found in the rows' meta, by kind, so their names can be looked up at once.
 *
 * @param  list<array<string,mixed>> $metas
 * @return array{user:list<int>, audit:list<int>, road:list<int>, segment:list<int>, city:list<int>}
 */
function activityCollectIds(array $metas): array
{
    $kinds = [
        'user_id' => 'user', 'target_user_id' => 'user', 'leader_user_id' => 'user',
        'previous_leader_user_id' => 'user', 'surveyor_id' => 'user', 'new_surveyor_id' => 'user',
        'audit_id' => 'audit', 'road_id' => 'road', 'segment_id' => 'segment',
        'city_id' => 'city', 'from_city_id' => 'city', 'to_city_id' => 'city',
    ];
    $out = ['user' => [], 'audit' => [], 'road' => [], 'segment' => [], 'city' => []];
    foreach ($metas as $m) {
        foreach ($kinds as $key => $kind) {
            if (isset($m[$key]) && is_numeric($m[$key]) && (int)$m[$key] > 0) {
                $out[$kind][(int)$m[$key]] = true;
            }
        }
        if (isset($m['target_user_ids']) && is_array($m['target_user_ids'])) {
            foreach ($m['target_user_ids'] as $id) {
                if (is_numeric($id) && (int)$id > 0) {
                    $out['user'][(int)$id] = true;
                }
            }
        }
    }
    foreach ($out as $k => $set) {
        $out[$k] = array_keys($set);
    }
    return $out;
}

/**
 * Names for those ids. A kind that cannot be read (missing table, etc.) is left empty;
 * the page then shows "#12" instead of a name.
 *
 * @param  array{user:list<int>, audit:list<int>, road:list<int>, segment:list<int>, city:list<int>} $ids
 * @return array{user:array<int,string>, audit:array<int,string>, road:array<int,string>, segment:array<int,string>, city:array<int,string>}
 */
function activityLoadNames(PDO $pdo, array $ids): array
{
    $names = ['user' => [], 'audit' => [], 'road' => [], 'segment' => [], 'city' => []];
    $queries = [
        'user'    => 'SELECT id, name FROM users WHERE id IN (%s)',
        'audit'   => 'SELECT id, name FROM city_audits WHERE id IN (%s)',
        'road'    => 'SELECT id, name FROM roads WHERE id IN (%s)',
        'segment' => 'SELECT s.id, CONCAT(\'segment \', s.segment_number, \' of \', COALESCE(r.name, \'a road\')) AS name
                        FROM segments s LEFT JOIN roads r ON r.id = s.road_id WHERE s.id IN (%s)',
        'city'    => 'SELECT id, name FROM cities WHERE id IN (%s)',
    ];
    foreach ($queries as $kind => $sql) {
        $list = array_slice($ids[$kind] ?? [], 0, 500);
        if ($list === []) {
            continue;
        }
        try {
            $st = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($list), '?'))));
            $st->execute($list);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[$kind][(int)$r['id']] = (string)$r['name'];
            }
        } catch (Throwable $e) {
            error_log('activityLoadNames(' . $kind . ') failed: ' . $e->getMessage());
        }
    }
    return $names;
}

/** What was done, in a sentence, from the action and its meta. The person who did it is shown separately. */
function activityDescribe(string $action, array $meta, array $names): string
{
    $nm = static function (string $kind, $id) use ($names): string {
        if (!is_numeric($id) || (int)$id < 1) {
            return 'someone';
        }
        $n = $names[$kind][(int)$id] ?? null;
        if ($n !== null && $n !== '') {
            return $kind === 'segment' ? $n : '“' . $n . '”';
        }
        return '#' . (int)$id . ' (no longer exists)';
    };
    $who = static function ($id) use ($names): string {
        if (!is_numeric($id) || (int)$id < 1) {
            return 'someone';
        }
        return $names['user'][(int)$id] ?? ('user #' . (int)$id);
    };
    $str = static fn(string $key): string => isset($meta[$key]) && is_scalar($meta[$key]) ? trim((string)$meta[$key]) : '';
    $project = static function () use ($meta, $names, $str): string {
        $id = $meta['audit_id'] ?? null;
        $n  = is_numeric($id) ? ($names['audit'][(int)$id] ?? null) : null;
        $n  = $n ?? ($str('name') !== '' ? $str('name') : null);
        if ($n !== null) {
            return 'project “' . $n . '”';
        }
        return is_numeric($id) && (int)$id > 0 ? 'project #' . (int)$id . ' (no longer exists)' : 'a project';
    };
    $road = static function () use ($meta, $names, $str): string {
        $id = $meta['road_id'] ?? null;
        $n  = is_numeric($id) ? ($names['road'][(int)$id] ?? null) : null;
        $n  = $n ?? ($str('name') !== '' ? $str('name') : null);
        if ($n !== null) {
            return 'road “' . $n . '”';
        }
        return is_numeric($id) && (int)$id > 0 ? 'road #' . (int)$id . ' (no longer exists)' : 'a road';
    };
    $segment = static function () use ($meta, $names): string {
        $id = $meta['segment_id'] ?? null;
        if (is_numeric($id) && isset($names['segment'][(int)$id])) {
            return $names['segment'][(int)$id];
        }
        return is_numeric($id) && (int)$id > 0 ? 'segment #' . (int)$id . ' (no longer exists)' : 'a segment';
    };
    $targets = static function () use ($meta, $who): string {
        $ids = [];
        if (isset($meta['target_user_ids']) && is_array($meta['target_user_ids'])) {
            $ids = $meta['target_user_ids'];
        } elseif (isset($meta['target_user_id'])) {
            $ids = [$meta['target_user_id']];
        }
        $n = array_map($who, $ids);
        return $n === [] ? 'someone' : implode(', ', $n);
    };
    $ofProject = static fn(): string => ' in ' . $project() . '.';

    switch ($action) {
        case 'user_login':
            return 'Signed in.';
        case 'user_logout':
            return 'Signed out.';
        case 'user_login_failed':
            $why = ['wrong_password' => 'wrong password', 'unknown_email' => 'no account with this email', 'deactivated' => 'the account is deactivated'];
            $r = $why[$str('reason')] ?? 'sign-in refused';
            return 'Could not sign in as ' . ($str('email') !== '' ? $str('email') : 'an unknown email') . ': ' . $r . '.';
        case 'user_registered':
            return 'Created an account' . ($str('email') !== '' ? ' (' . $str('email') . ')' : '') . '.';
        case 'profile_updated':
            return match ($str('field')) {
                'email'   => 'Changed their email to ' . $str('email') . '.',
                'picture' => 'Changed their profile picture.',
                default   => 'Changed their profile details.',
            };
        case 'password_changed':
            return $str('source') === 'first_login'
                ? 'Chose a new password after signing in with a temporary one.'
                : 'Changed their own password.';
        case 'password_reset_requested':
            $e = $str('email') !== '' ? $str('email') : 'an email';
            return 'Asked for a password reset for ' . $e . (($meta['found'] ?? null) === false ? ' (no account with this email).' : '.');
        case 'password_reset_completed':
            return 'Set a new password using a reset link.';
        case 'user_password_reset':
            return 'Reset the password of ' . $who($meta['user_id'] ?? null) . '. A temporary password was issued.';
        case 'password_request_approved':
            return 'Approved the password request of ' . $targets() . '.';
        case 'password_request_dismissed':
            return 'Dismissed the password request of ' . $targets() . '.';
        case 'user_role_changed':
            $from = activityRoleLabel($str('from_role'));
            $to   = activityRoleLabel($str('to_role'));
            $in   = isset($meta['city_id']) && is_numeric($meta['city_id']) && isset($names['city'][(int)$meta['city_id']]) ? ' (' . $names['city'][(int)$meta['city_id']] . ')' : '';
            return 'Changed the role of ' . $who($meta['user_id'] ?? null) . ' from ' . ($from ?: 'none') . ' to ' . ($to ?: 'none') . $in . '.';
        case 'user_city_changed':
            $f = isset($meta['from_city_id']) && is_numeric($meta['from_city_id']) ? ($names['city'][(int)$meta['from_city_id']] ?? 'no city') : 'no city';
            $t = isset($meta['to_city_id'])   && is_numeric($meta['to_city_id'])   ? ($names['city'][(int)$meta['to_city_id']]   ?? 'no city') : 'no city';
            return 'Moved ' . $who($meta['user_id'] ?? null) . ' from ' . $f . ' to ' . $t . '.';
        case 'user_activated':
            return 'Activated the account of ' . $who($meta['user_id'] ?? null) . '.';
        case 'user_deactivated':
            $s = 'Deactivated the account of ' . $who($meta['user_id'] ?? null) . '.';
            if ($str('mode') === 'wait') {
                $s = 'Started deactivating ' . $who($meta['user_id'] ?? null) . '; it completes when they submit the segments in progress.';
            }
            return $s;
        case 'team_member_added':
            return 'Added to the team: ' . $targets() . '.';
        case 'team_member_removed':
            return 'Removed from the team: ' . $targets() . (!empty($meta['waiting']) ? ' (waiting for segments in progress).' : '.');
        case 'audit_created':
            $c = isset($meta['city_id']) && is_numeric($meta['city_id']) && isset($names['city'][(int)$meta['city_id']]) ? ' in ' . $names['city'][(int)$meta['city_id']] : '';
            $l = isset($meta['leader_user_id']) && is_numeric($meta['leader_user_id']) ? ' City Leader: ' . $who($meta['leader_user_id']) . '.' : '';
            return 'Created ' . $project() . $c . '.' . $l;
        case 'audit_updated':
            $ch = isset($meta['changed']) && is_array($meta['changed']) ? implode(', ', array_map('strval', $meta['changed'])) : '';
            $l  = '';
            if (isset($meta['leader_user_id']) && is_numeric($meta['leader_user_id'])) {
                $prev = isset($meta['previous_leader_user_id']) && is_numeric($meta['previous_leader_user_id']) ? ' (was ' . $who($meta['previous_leader_user_id']) . ')' : '';
                $l = ' New City Leader: ' . $who($meta['leader_user_id']) . $prev . '.';
            }
            return 'Edited ' . $project() . ($ch !== '' ? ': ' . $ch . '.' : '.') . $l;
        case 'audit_deleted':
            $facts = [];
            foreach (['roads' => 'road', 'segments' => 'segment', 'surveyors' => 'surveyor'] as $k => $w) {
                if (isset($meta[$k]) && is_numeric($meta[$k])) {
                    $facts[] = (int)$meta[$k] . ' ' . $w . ((int)$meta[$k] === 1 ? '' : 's');
                }
            }
            return 'Deleted ' . $project() . ($facts ? ' (' . implode(', ', $facts) . ')' : '') . '.'
                . ($str('reason') !== '' ? ' Reason: ' . $str('reason') : '');
        case 'audit_activated':
            return 'Started work on ' . $project() . '.';
        case 'audit_closed':
            return 'Finalised ' . $project() . '.';
        case 'audit_sent_to_admin':
            return 'Sent ' . $project() . ' to the Admin for approval.';
        case 'audit_approved':
            return 'Approved ' . $project() . '. It is now published.';
        case 'audit_returned':
            return 'Returned ' . $project() . ' to the City Leader.';
        case 'audit_unpublished':
            return 'Hid ' . $project() . ' from the home page.';
        case 'audit_republished':
            return 'Showed ' . $project() . ' on the home page again.';
        case 'audit_road_added':
            return 'Added ' . $road() . ' to ' . $project() . '.';
        case 'audit_road_removed':
            return 'Removed ' . $road() . ' from ' . $project() . '.';
        case 'audit_segment_added':
            return 'Added ' . $segment() . $ofProject();
        case 'audit_segment_removed':
            return 'Removed ' . $segment() . ' from ' . $project() . '.';
        case 'audit_segments_assigned':
            $n = isset($meta['segments']) && is_array($meta['segments']) ? count($meta['segments']) : (isset($meta['segments']) && is_numeric($meta['segments']) ? (int)$meta['segments'] : null);
            $what = $n !== null ? $n . ' segment' . ($n === 1 ? '' : 's') : 'segments';
            $to = isset($meta['surveyor_id']) && is_numeric($meta['surveyor_id']) ? $who($meta['surveyor_id']) : 'nobody (unassigned)';
            return 'Assigned ' . $what . ' of ' . $road() . ' to ' . $to . $ofProject();
        case 'audit_segment_approved':
            return 'Approved ' . $segment() . $ofProject();
        case 'audit_segment_sent_back':
            $to = isset($meta['new_surveyor_id']) && is_numeric($meta['new_surveyor_id']) ? ' to ' . $who($meta['new_surveyor_id']) : '';
            return 'Sent ' . $segment() . ' back for correction' . $to . $ofProject();
        case 'segment_submitted':
            return 'Submitted ' . $segment() . ($meta['audit_id'] ?? null ? $ofProject() : '.');
        case 'segment_edited':
            return 'Edited ' . $segment() . ($meta['audit_id'] ?? null ? $ofProject() : '.');
        case 'segment_completed':
            return 'Marked ' . $segment() . ' as completed.';
        case 'segment_reset':
            return 'Cleared the audit form of ' . $segment() . '.';
        case 'segment_unlocked':
            return 'Unlocked ' . $segment() . ' for editing.';
        case 'session_started':
            return 'Started an audit session on ' . $road() . '.';
        case 'session_closed':
            return 'Closed an audit session on ' . $road() . '.';
        case 'road_created':
            return 'Created ' . $road() . '.';
        case 'road_updated':
            return 'Edited ' . $road() . '.';
        case 'road_deleted':
            return 'Deleted ' . $road() . '.';
        case 'road_finalized':
            return 'Finalised ' . $road() . '.';
        case 'road_segments_saved':
            $n = isset($meta['segments']) && is_numeric($meta['segments']) ? ' (' . (int)$meta['segments'] . ' segments)' : '';
            return 'Saved the segments of ' . $road() . $n . '.';
        case 'road_group_created':
            return 'Added ' . ($str('name') !== '' ? '“' . $str('name') . '”' : 'a road') . ' to the road catalogue.';
        case 'road_group_deleted':
            return 'Removed ' . ($str('name') !== '' ? '“' . $str('name') . '”' : 'a road') . ' from the road catalogue.';
        case 'past_audit_added':
            return 'Added past audit ' . ($str('name') !== '' ? '“' . $str('name') . '”' : '') . ($str('city') !== '' ? ' (' . $str('city') . ')' : '') . '.';
        case 'past_audit_deleted':
            return 'Deleted past audit ' . ($str('name') !== '' ? '“' . $str('name') . '”' : '') . ($str('city') !== '' ? ' (' . $str('city') . ')' : '') . '.';
        case 'collaborator_added':
        case 'collaborator_updated':
        case 'collaborator_shown':
        case 'collaborator_hidden':
        case 'collaborator_removed':
            $verb = ['collaborator_added' => 'Added', 'collaborator_updated' => 'Edited', 'collaborator_shown' => 'Showed',
                     'collaborator_hidden' => 'Hid', 'collaborator_removed' => 'Removed'][$action];
            $n = $str('name') !== '' ? '“' . $str('name') . '”' : 'a collaborator';
            return $verb . ' collaborator ' . $n . ($action === 'collaborator_shown' ? ' on the home page.' : ($action === 'collaborator_hidden' ? ' from the home page.' : '.'));
        case 'export_downloaded':
            $kinds = [
                'road_report_excel' => 'the Excel report of a road audit', 'road_report_pdf' => 'the PDF report of a road audit',
                'city_excel' => 'the Excel export of a city', 'roads_excel' => 'the Excel list of roads', 'users_excel' => 'the Excel list of users',
            ];
            $what = $kinds[$str('type')] ?? 'a file';
            $extra = isset($meta['road_id']) && is_numeric($meta['road_id']) ? ' for ' . $road() : (isset($meta['city_id']) && is_numeric($meta['city_id']) && isset($names['city'][(int)$meta['city_id']]) ? ' for ' . $names['city'][(int)$meta['city_id']] : '');
            return 'Downloaded ' . $what . $extra . '.';
    }

    // An action this file does not know yet: show what was recorded rather than hide it.
    $bits = [];
    foreach ($meta as $k => $v) {
        if (is_scalar($v)) {
            $bits[] = $k . ': ' . $v;
        }
    }
    return $bits ? implode(', ', array_slice($bits, 0, 6)) : '';
}

/**
 * Finished entries for the page.
 *
 * @param  list<array{id:string, created_at:?string, user_id:?int, actor_name:?string, actor_role:?string, action:string, meta:array<string,mixed>, ip:string}> $rows
 * @param  array<string,array<int,string>> $names from activityLoadNames()
 * @return list<array<string,mixed>>
 */
function activityBuildEntries(array $rows, array $names): array
{
    $out = [];
    foreach ($rows as $r) {
        $info  = activityActionInfo($r['action']);
        $actor = $r['actor_name'];
        if ($actor === null || $actor === '') {
            $actor = $r['user_id'] === null ? 'Not signed in' : 'Deleted user #' . $r['user_id'];
        }
        $out[] = [
            'id'         => $r['id'],
            'when'       => activityWhen($r['created_at']),
            'created_at' => $r['created_at'],
            'actor_id'   => $r['user_id'],
            'actor_name' => $actor,
            'actor_role' => activityRoleLabel($r['actor_role']),
            'action'     => $r['action'],
            'label'      => $info['label'],
            'category'   => $info['category'],
            'tone'       => $info['tone'],
            'details'    => activityDescribe($r['action'], $r['meta'], $names),
            'ip'         => $r['ip'],
        ];
    }
    return $out;
}
