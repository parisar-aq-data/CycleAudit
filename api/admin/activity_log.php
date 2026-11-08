<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/activity_log.php
//  GET ?limit=200&offset=0 — the Activity Log, newest first.
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

// A city_leader with no city assigned sees an empty log rather than
// falling through to every city's entries.
if (!$isNationalAdmin && $cityId === null) {
    echo json_encode(['success' => true, 'entries' => [], 'total' => 0, 'has_more' => false]);
    exit;
}

/** Road catalogue trail (audit_log) in the same shape as activity_log rows. */
$legacyRows = static function (PDO $pdo, ?int $cityId, int $take): array {
    // LEFT JOIN so a city_leader still sees log entries whose road_group has since been
    // deleted (rg is NULL), but those are excluded for a city_leader (the city is
    // unknowable); the Admin sees everything.
    $stmt = $pdo->prepare(
        'SELECT al.id, al.actor_id, al.actor_name, al.action, al.road_group_id, al.road_group_name, al.created_at
           FROM audit_log al
           LEFT JOIN road_groups rg ON rg.id = al.road_group_id
          WHERE (:cid1 IS NULL OR rg.city_id = :cid2)
          ORDER BY al.created_at DESC, al.id DESC
          LIMIT ' . $take
    );
    $stmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'         => 'l' . (int)$r['id'],
            'created_at' => (string)$r['created_at'],
            'user_id'    => $r['actor_id'] !== null ? (int)$r['actor_id'] : null,
            'actor_name' => $r['actor_name'] !== null ? (string)$r['actor_name'] : null,
            'actor_role' => null,
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

$need  = $offset + $limit;
$rows  = [];
$total = 0;

$st = $pdo->query(
    'SELECT al.id, al.user_id, u.name AS actor_name, u.role AS actor_role, al.action, al.meta, al.ip_address, al.created_at
       FROM activity_log al
       LEFT JOIN users u ON u.id = al.user_id
      ORDER BY al.created_at DESC, al.id DESC
      LIMIT ' . (int)$need
);
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
$total += (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();

try {
    $rows  = array_merge($rows, $legacyRows($pdo, null, $need));
    $total += (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
} catch (Throwable $e) {
    error_log('activity_log: audit_log not readable: ' . $e->getMessage());
}

usort($rows, static function (array $a, array $b): int {
    return [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']];
});
$rows = array_slice($rows, $offset, $limit);

$names = activityLoadNames($pdo, activityCollectIds(array_column($rows, 'meta')));

echo json_encode([
    'success'  => true,
    'entries'  => activityBuildEntries($rows, $names),
    'total'    => $total,
    'has_more' => $total > $offset + $limit,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
