<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/activity_log.php
//  GET — the Activity Log, newest first (or oldest first with order=asc).
//
//  Filters (all optional, all applied on the server so they cover the whole log,
//  not just the rows already on screen):
//    category  group key from activityCategories()      action  one action code
//    user      a user id, or "none" for not signed in   role    admin|city_leader|surveyor
//    audit     a project id                             tone    "bad" = deletions and failures
//    from, to  YYYY-MM-DD, days in India time           q       words to find in the text shown
//    limit, offset, order
//
//  Admin: everything recorded in activity_log (sign-ins, password changes, user
//  changes, projects, roads, segments, downloads, home page edits) plus the
//  older audit_log trail (road catalogue create/delete), merged by time.
//  Ids in the records are shown as names.
//  Anyone else who reaches this (a City Leader): only the road catalogue entries
//  of their own city, as before.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/activity_log.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/ActivityLogView.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$isNationalAdmin = $CURRENT_USER_ROLE === 'admin';
$cityId          = $isNationalAdmin ? null : $CURRENT_USER_CITY_ID;
$limit           = max(1, min(500, (int)($_GET['limit'] ?? 200)));
$offset          = max(0, (int)($_GET['offset'] ?? 0));
$f               = activityFilterFromRequest($_GET);
$dir             = $f['order'] === 'asc' ? 'ASC' : 'DESC';

// A city_leader with no city assigned sees an empty log rather than
// falling through to every city's entries.
if (!$isNationalAdmin && $cityId === null) {
    echo json_encode(['success' => true, 'entries' => [], 'total' => 0, 'has_more' => false]);
    exit;
}

/**
 * Road catalogue trail (audit_log) in the same shape as activity_log rows.
 * $actions limits it to 'create' and/or 'delete'; null means both.
 *
 * @param  list<string>|null $actions
 * @param  array{0:?string,1:?string} $range UTC window
 */
$legacyRows = static function (PDO $pdo, ?int $cityId, int $take, ?array $actions = null, ?string $userKey = null, string $role = '', array $range = [null, null], string $dir = 'DESC') use ($f): array {
    $where = ['(:cid1 IS NULL OR rg.city_id = :cid2)'];
    $args  = ['cid1' => $cityId, 'cid2' => $cityId];
    if ($actions !== null) {
        if ($actions === []) {
            return [];
        }
        $where[] = 'al.action IN (' . implode(',', array_map(static fn($a) => $pdo->quote($a), $actions)) . ')';
    }
    if ($userKey !== null) {
        $where[] = $userKey === 'none' ? 'al.actor_id IS NULL' : 'al.actor_id = :uid';
        if ($userKey !== 'none') {
            $args['uid'] = (int)$userKey;
        }
    }
    if ($role !== '') {
        $where[] = 'u.role = :role';
        $args['role'] = $role;
    }
    if ($range[0] !== null) {
        $where[] = 'al.created_at >= :dfrom';
        $args['dfrom'] = $range[0];
    }
    if ($range[1] !== null) {
        $where[] = 'al.created_at < :dto';
        $args['dto'] = $range[1];
    }
    // LEFT JOIN so a city_leader still sees log entries whose road_group has since been
    // deleted (rg is NULL), but those are excluded for a city_leader (the city is
    // unknowable); the Admin sees everything.
    $stmt = $pdo->prepare(
        'SELECT al.id, al.actor_id, al.actor_name, u.role AS actor_role, al.action, al.road_group_id, al.road_group_name, al.created_at
           FROM audit_log al
           LEFT JOIN road_groups rg ON rg.id = al.road_group_id
           LEFT JOIN users u ON u.id = al.actor_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY al.created_at ' . $dir . ', al.id ' . $dir . '
          LIMIT ' . $take
    );
    $stmt->execute($args);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'         => 'l' . (int)$r['id'],
            'created_at' => (string)$r['created_at'],
            'user_id'    => $r['actor_id'] !== null ? (int)$r['actor_id'] : null,
            'actor_name' => $r['actor_name'] !== null ? (string)$r['actor_name'] : null,
            'actor_role' => $r['actor_role'] !== null ? (string)$r['actor_role'] : null,
            'action'     => $r['action'] === 'delete' ? 'road_group_deleted' : 'road_group_created',
            'meta'       => ['name' => (string)$r['road_group_name']],
            'ip'         => '',
        ];
    }
    return $rows;
};

if (!$isNationalAdmin) {
    $rows = $legacyRows($pdo, $cityId, 500);
    echo json_encode(['success' => true, 'entries' => activityBuildEntries($rows, []), 'total' => count($rows), 'has_more' => false]);
    exit;
}

// ── which actions the filters allow ─────────────────────────────
$in    = null;   // only these actions
$notIn = null;   // anything but these (the "Other" group = actions the page has no group for)
if ($f['action'] !== '') {
    $in = [$f['action']];
} elseif ($f['category'] === 'other') {
    $notIn = array_keys(activityActionMap());
} elseif ($f['category'] !== '') {
    $in = activityActionsFor($f['category'], '');
}
if ($f['tone'] === 'bad') {
    $bad = activityBadActions();
    $in  = $in === null ? ($notIn === null ? $bad : array_values(array_diff($bad, $notIn))) : array_values(array_intersect($in, $bad));
    $notIn = null;
}
$allowed = static fn(string $code): bool => ($in === null || in_array($code, $in, true)) && ($notIn === null || !in_array($code, $notIn, true));

// ── activity_log ────────────────────────────────────────────────
$range = activityDateRangeUtc($f['from'], $f['to']);
$where = [];
$args  = [];
if ($in !== null) {
    $where[] = $in === [] ? '1 = 0' : 'al.action IN (' . implode(',', array_fill(0, count($in), '?')) . ')';
    array_push($args, ...$in);
}
if ($notIn !== null) {
    $where[] = 'al.action NOT IN (' . implode(',', array_fill(0, count($notIn), '?')) . ')';
    array_push($args, ...$notIn);
}
if ($f['user'] === 'none') {
    $where[] = 'al.user_id IS NULL';
} elseif ($f['user'] !== null) {
    $where[] = 'al.user_id = ?';
    $args[]  = (int)$f['user'];
}
if ($f['role'] !== '') {
    $where[] = 'u.role = ?';
    $args[]  = $f['role'];
}
if ($f['audit'] > 0) {
    $where[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.audit_id')) AS UNSIGNED) = ?";
    $args[]  = $f['audit'];
}
if ($range[0] !== null) {
    $where[] = 'al.created_at >= ?';
    $args[]  = $range[0];
}
if ($range[1] !== null) {
    $where[] = 'al.created_at < ?';
    $args[]  = $range[1];
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$searching = $f['q'] !== '';
$take      = $searching ? 2000 : $offset + $limit;   // searching reads further back, then narrows by the words typed

$st = $pdo->prepare(
    'SELECT al.id, al.user_id, u.name AS actor_name, u.role AS actor_role, al.action, al.meta, al.ip_address, al.created_at
       FROM activity_log al
       LEFT JOIN users u ON u.id = al.user_id' . $whereSql . '
      ORDER BY al.created_at ' . $dir . ', al.id ' . $dir . '
      LIMIT ' . (int)$take
);
$st->execute($args);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $meta = [];
    if ($r['meta'] !== null && $r['meta'] !== '') {
        $decoded = json_decode((string)$r['meta'], true);
        $meta    = is_array($decoded) ? $decoded : [];
    }
    $rows[] = [
        'id'         => 'a' . (int)$r['id'],
        'created_at' => (string)$r['created_at'],
        'user_id'    => $r['user_id'] !== null ? (int)$r['user_id'] : null,
        'actor_name' => $r['actor_name'] !== null ? (string)$r['actor_name'] : null,
        'actor_role' => $r['actor_role'] !== null ? (string)$r['actor_role'] : null,
        'action'     => (string)$r['action'],
        'meta'       => $meta,
        'ip'         => (string)$r['ip_address'],
    ];
}
$countSt = $pdo->prepare('SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON u.id = al.user_id' . $whereSql);
$countSt->execute($args);
$total  = (int)$countSt->fetchColumn();
$capped = $searching && count($rows) >= $take;

// ── the older road catalogue trail (audit_log) ──────────────────
// It has no project, so a project filter leaves it out.
if ($f['audit'] === 0) {
    $legacyActions = [];
    if ($allowed('road_group_created')) {
        $legacyActions[] = 'create';
    }
    if ($allowed('road_group_deleted')) {
        $legacyActions[] = 'delete';
    }
    try {
        $legacy = $legacyRows($pdo, null, $take, $legacyActions, $f['user'], $f['role'], $range, $dir);
        $rows   = array_merge($rows, $legacy);
        $capped = $capped || ($searching && count($legacy) >= $take);
        if ($legacyActions !== []) {
            $lw  = ['al.action IN (' . implode(',', array_map(static fn($a) => $pdo->quote($a), $legacyActions)) . ')'];
            $la  = [];
            if ($f['user'] === 'none') {
                $lw[] = 'al.actor_id IS NULL';
            } elseif ($f['user'] !== null) {
                $lw[] = 'al.actor_id = ?';
                $la[] = (int)$f['user'];
            }
            if ($f['role'] !== '') {
                $lw[] = 'u.role = ?';
                $la[] = $f['role'];
            }
            if ($range[0] !== null) {
                $lw[] = 'al.created_at >= ?';
                $la[] = $range[0];
            }
            if ($range[1] !== null) {
                $lw[] = 'al.created_at < ?';
                $la[] = $range[1];
            }
            $lc = $pdo->prepare('SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.actor_id WHERE ' . implode(' AND ', $lw));
            $lc->execute($la);
            $total += (int)$lc->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('activity_log: audit_log not readable: ' . $e->getMessage());
    }
}

// Newest (or oldest) first; rows in the same second go by their id number, not as text ("a99" is before "a123").
usort($rows, static function (array $a, array $b) use ($f): int {
    $ka = [$a['created_at'], (int)substr((string)$a['id'], 1)];
    $kb = [$b['created_at'], (int)substr((string)$b['id'], 1)];
    return $f['order'] === 'asc' ? $ka <=> $kb : $kb <=> $ka;
});

if (!$searching) {
    $rows    = array_slice($rows, $offset, $limit);
    $names   = activityLoadNames($pdo, activityCollectIds(array_column($rows, 'meta')));
    $entries = activityBuildEntries($rows, $names);
} else {
    // Names are looked up a few hundred rows at a time, so every row can be read as the Admin reads it.
    $entries = [];
    foreach (array_chunk($rows, 200) as $chunk) {
        $names = activityLoadNames($pdo, activityCollectIds(array_column($chunk, 'meta')));
        foreach (activityBuildEntries($chunk, $names) as $e) {
            if (activityEntryMatches($e, $f['q'])) {
                $entries[] = $e;
            }
        }
    }
    $total   = count($entries);
    $entries = array_slice($entries, $offset, $limit);
}

echo json_encode([
    'success'  => true,
    'entries'  => $entries,
    'total'    => $total,
    'has_more' => $total > $offset + $limit,
    'capped'   => $capped,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
