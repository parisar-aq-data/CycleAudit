<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/CityAuditRepository.php
//  City audits and the roads / segments that belong to them.
//  Road rows made here follow the same shape as the surveyor flow
//  (roads + segments), tied to the audit through roads.audit_id.
//  User-facing problems are thrown as DomainException (safe to show).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/CityAudit.php';
require_once __DIR__ . '/../helpers/TeamRoster.php';

class CityAuditRepository
{
    /** Audit statuses in which roads can still be added or removed, and mistaken segments deleted.
     *  Work already done on a road or segment still protects it (see removeRoad / removeSegment). */
    public const EDITABLE_STATUSES = ['draft', 'active', 'in_review'];

    /** Audit statuses in which segments can still be (re)assigned to surveyors. */
    public const ASSIGNABLE_STATUSES = ['draft', 'active', 'in_review'];

    public function __construct(private PDO $pdo) {}

    /** @param array{name:string,state:string,audit_date:string,audit_year:int,programme_info:?string} $clean */
    public function createAudit(int $cityId, int $userId, array $clean, ?int $leaderUserId = null): int
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO city_audits (city_id, state, name, audit_year, audit_date, programme_info, created_by, leader_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $cityId, $clean['state'], $clean['name'], $clean['audit_year'], $clean['audit_date'],
                $clean['programme_info'], $userId, $leaderUserId,
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('An audit with this name and year already exists for this city.');
            }
            throw $e;
        }
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listForCity(int $cityId, ?int $leaderUserId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.name, a.state, a.audit_year, a.audit_date, a.status, a.created_at,
                    COUNT(DISTINCT r.id) AS road_count,
                    COUNT(s.id)          AS segment_count,
                    SUM(CASE WHEN s.status = \'completed\' THEN 1 ELSE 0 END) AS done_count
               FROM city_audits a
               LEFT JOIN roads r    ON r.audit_id = a.id
               LEFT JOIN segments s ON s.road_id  = r.id
              WHERE a.city_id = ? AND a.status <> \'voided\'' . ($leaderUserId !== null ? ' AND a.leader_user_id = ?' : '') . '
              GROUP BY a.id, a.name, a.state, a.audit_year, a.audit_date, a.status, a.created_at
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute($leaderUserId !== null ? [$cityId, $leaderUserId] : [$cityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $auditId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.city_id, a.state, a.name, a.audit_year, a.audit_date, a.programme_info,
                    a.status, a.created_by, a.created_at, a.leader_user_id,
                    c.name AS city_name, u.name AS created_by_name, l.name AS leader_name
               FROM city_audits a
               JOIN cities c ON c.id = a.city_id
               LEFT JOIN users u ON u.id = a.created_by
               LEFT JOIN users l ON l.id = a.leader_user_id
              WHERE a.id = ?'
        );
        $stmt->execute([$auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Who leads this audit, for the report: the assigned City Leader's name and organisation.
     * Null when no City Leader is assigned. The organisation is null when the leader has none on file.
     *
     * @param array<string,mixed> $audit  row from find()
     * @return array{name:string, organisation:?string}|null
     */
    public function leaderDetails(array $audit): ?array
    {
        if (empty($audit['leader_user_id'])) {
            return null;
        }
        $q = $this->pdo->prepare('SELECT name, organisation FROM users WHERE id = ?');
        $q->execute([(int)$audit['leader_user_id']]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if ($u === false || trim((string)$u['name']) === '') {
            return null;
        }
        $org = trim((string)($u['organisation'] ?? ''));
        return ['name' => trim((string)$u['name']), 'organisation' => $org !== '' ? $org : null];
    }

    /** The Admin picks (or clears, with null) the City Leader a project belongs to. */
    public function setLeader(int $auditId, ?int $leaderUserId): void
    {
        $this->pdo->prepare('UPDATE city_audits SET leader_user_id = ? WHERE id = ?')->execute([$leaderUserId, $auditId]);
    }

    /** The Admin deleted this project (kept in the database as 'voided', but gone from every screen). */
    public function isDeleted(array $audit): bool
    {
        return ($audit['status'] ?? '') === 'voided';
    }

    /**
     * Admin edit of a project's name, audit date (the year follows it) and programme info.
     * The city and state stay as they are: the roads and surveyors belong to the city.
     *
     * @param array<string,mixed> $audit  row from find()
     * @param array<string,mixed> $clean  from cityAuditValidate()
     * @return list<string>  what changed, e.g. ['name', 'programme info'] (empty = nothing changed)
     */
    public function updateAudit(array $audit, array $clean): array
    {
        if ($this->isDeleted($audit)) {
            throw new DomainException('This project was deleted.');
        }
        $changed = [];
        if ((string)$audit['name'] !== (string)$clean['name']) {
            $changed[] = 'name';
        }
        if ((string)($audit['audit_date'] ?? '') !== (string)$clean['audit_date']) {
            $changed[] = 'audit date';
        }
        if ((string)($audit['programme_info'] ?? '') !== (string)($clean['programme_info'] ?? '')) {
            $changed[] = 'programme info';
        }
        if (!$changed) {
            return [];
        }
        try {
            $this->pdo->prepare(
                'UPDATE city_audits SET name = ?, audit_year = ?, audit_date = ?, programme_info = ?
                  WHERE id = ? AND status <> \'voided\''
            )->execute([
                $clean['name'], $clean['audit_year'], $clean['audit_date'], $clean['programme_info'], (int)$audit['id'],
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('Another project in this city already has this name and year.');
            }
            throw $e;
        }
        return $changed;
    }

    /**
     * What deleting the project stops: roads, segments, segments already worked on, and the
     * surveyors holding segments.
     *
     * @return array{roads:int,segments:int,started:int,surveyors:int}
     */
    public function deletionImpact(int $auditId): array
    {
        $q = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT r.id) AS roads, COUNT(s.id) AS segs,
                    SUM(CASE WHEN s.status <> 'pending' THEN 1 ELSE 0 END) AS started
               FROM roads r LEFT JOIN segments s ON s.road_id = r.id WHERE r.audit_id = ?"
        );
        $q->execute([$auditId]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $u = $this->pdo->prepare('SELECT COUNT(DISTINCT surveyor_id) FROM segment_assignments WHERE audit_id = ?');
        $u->execute([$auditId]);
        return [
            'roads'     => (int)($row['roads'] ?? 0),
            'segments'  => (int)($row['segs'] ?? 0),
            'started'   => (int)($row['started'] ?? 0),
            'surveyors' => (int)$u->fetchColumn(),
        ];
    }

    /** Ids of the surveyors holding segments in this project. @return list<int> */
    public function assignedSurveyorIds(int $auditId): array
    {
        $q = $this->pdo->prepare('SELECT DISTINCT surveyor_id FROM segment_assignments WHERE audit_id = ? ORDER BY surveyor_id');
        $q->execute([$auditId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Admin delete: the project is marked 'voided'. Its roads, segments and audit data are kept in
     * the database (nothing is lost for good), but it disappears from every list and all work on it
     * stops. The name is changed so the same name can be used again for a new project.
     */
    public function deleteAudit(array $audit): void
    {
        if ($this->isDeleted($audit)) {
            throw new DomainException('This project was already deleted.');
        }
        $suffix = ' [deleted #' . (int)$audit['id'] . ']';
        $name   = mb_substr((string)$audit['name'], 0, 150 - mb_strlen($suffix)) . $suffix;
        $upd = $this->pdo->prepare('UPDATE city_audits SET status = \'voided\', name = ? WHERE id = ? AND status <> \'voided\'');
        $upd->execute([$name, (int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This project was already deleted.');
        }
    }

    public function isEditable(array $audit): bool
    {
        return in_array($audit['status'], self::EDITABLE_STATUSES, true);
    }

    public function canAssign(array $audit): bool
    {
        return in_array($audit['status'], self::ASSIGNABLE_STATUSES, true);
    }

    /** City roads not yet in this audit.
     *  @return list<array{id:int,canonical_name:string}> */
    public function availableRoadGroups(int $cityId, int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rg.id, rg.canonical_name
               FROM road_groups rg
              WHERE rg.city_id = ? AND rg.is_flagged = 0
                AND NOT EXISTS (SELECT 1 FROM roads r
                                 WHERE r.audit_id = ? AND r.road_group_id = rg.id)
              ORDER BY rg.canonical_name ASC'
        );
        $stmt->execute([$cityId, $auditId]);
        return array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'canonical_name' => (string)$r['canonical_name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** Roads of an audit, each with its segments.
     *  @return list<array<string,mixed>> */
    public function roadsWithSegments(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, name, road_group_id, total_length, segment_length, finalized_at
               FROM roads WHERE audit_id = ? ORDER BY name ASC, id ASC'
        );
        $stmt->execute([$auditId]);
        $roads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$roads) {
            return [];
        }

        $ids  = array_map(static fn(array $r): int => (int)$r['id'], $roads);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $segs = $this->pdo->prepare(
            "SELECT s.id AS segment_id, s.road_id, s.segment_number, s.start_distance, s.end_distance,
                    s.length, s.status, sa.surveyor_id AS assigned_to, u.name AS assigned_name
               FROM segments s
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE s.road_id IN ($in) ORDER BY s.road_id ASC, s.segment_number ASC"
        );
        $segs->execute($ids);
        $byRoad = [];
        foreach ($segs->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $byRoad[(int)$s['road_id']][] = $s;
        }
        foreach ($roads as &$r) {
            $r['segments'] = $byRoad[(int)$r['id']] ?? [];
        }
        unset($r);
        return $roads;
    }

    /**
     * Add a city road to an audit and generate its segments.
     *
     * @param array<string,mixed> $audit  row from find()
     * @return array{road_id:int, public_id:string, segment_count:int}
     */
    public function addRoad(array $audit, int $userId, int $roadGroupId, float $totalLength, float $segmentLength): array
    {
        if (!$this->isEditable($audit)) {
            throw new DomainException('Roads can no longer be added to this audit.');
        }

        $g = $this->pdo->prepare(
            'SELECT id, canonical_name FROM road_groups
              WHERE id = ? AND city_id = ? AND is_flagged = 0'
        );
        $g->execute([$roadGroupId, (int)$audit['city_id']]);
        $group = $g->fetch(PDO::FETCH_ASSOC);
        if ($group === false) {
            throw new DomainException('That road was not found in this city.');
        }

        $plan = cityAuditSegmentPlan($totalLength, $segmentLength);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO roads
                   (public_id, creator_id, name, road_group_id, audit_id,
                    total_length, segment_method, segment_length)
                 VALUES (?, ?, ?, ?, ?, ?, \'auto\', ?)'
            )->execute([
                'T' . bin2hex(random_bytes(8)), $userId, (string)$group['canonical_name'],
                (int)$group['id'], (int)$audit['id'], $totalLength, $segmentLength,
            ]);
            $roadId   = (int)$this->pdo->lastInsertId();
            $publicId = 'ROAD-' . str_pad((string)$roadId, 4, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE roads SET public_id = ? WHERE id = ?')
                      ->execute([$publicId, $roadId]);

            $ins = $this->pdo->prepare(
                'INSERT INTO segments
                   (public_id, road_id, segment_number, start_label, end_label,
                    start_distance, end_distance, length)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $upd = $this->pdo->prepare('UPDATE segments SET public_id = ? WHERE id = ?');
            foreach ($plan as $seg) {
                $ins->execute([
                    'SEG-R' . $roadId . 'N' . $seg['segment_number'], $roadId, $seg['segment_number'],
                    $seg['start_label'], $seg['end_label'],
                    $seg['start_distance'], $seg['end_distance'], $seg['length'],
                ]);
                $segId = (int)$this->pdo->lastInsertId();
                $upd->execute(['SEG-' . str_pad((string)$segId, 4, '0', STR_PAD_LEFT), $segId]);
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('That road is already in this audit.');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['road_id' => $roadId, 'public_id' => $publicId, 'segment_count' => count($plan)];
    }

    /**
     * Add one segment to the end of a road that is already in the audit.
     * It starts where the road's last segment ends, takes the next segment number and the road's
     * total length grows with it. Allowed while the audit is editable and the road is not finalised.
     *
     * @param array<string,mixed> $audit  row from find()
     * @return array{road_id:int, segment_id:int, segment_number:int, total_length:float}
     */
    public function addSegment(array $audit, int $userId, int $roadId, float $length): array
    {
        if (!$this->isEditable($audit)) {
            throw new DomainException('Segments can no longer be added to this audit.');
        }
        if ($length < CITY_AUDIT_MIN_SEGMENT_LENGTH) {
            throw new DomainException('Segment length must be at least 10 metres.');
        }
        $length = round($length, 2);

        $q = $this->pdo->prepare('SELECT id, finalized_at, total_length FROM roads WHERE id = ? AND audit_id = ?');
        $q->execute([$roadId, (int)$audit['id']]);
        $road = $q->fetch(PDO::FETCH_ASSOC);
        if ($road === false) {
            throw new DomainException('That road is not part of this audit.');
        }
        if ($road['finalized_at'] !== null) {
            throw new DomainException('This road is already finalised, so segments cannot be added.');
        }

        $this->pdo->beginTransaction();
        try {
            $m = $this->pdo->prepare(
                'SELECT COUNT(*), COALESCE(MAX(segment_number), 0), COALESCE(MAX(end_distance), 0)
                   FROM segments WHERE road_id = ?'
            );
            $m->execute([$roadId]);
            [$count, $lastNo, $lastEnd] = $m->fetch(PDO::FETCH_NUM);
            if ((int)$count >= CITY_AUDIT_MAX_SEGMENTS) {
                throw new DomainException('This road already has the maximum number of segments.');
            }
            $start = round((float)$lastEnd, 2);
            $end   = round($start + $length, 2);
            $no    = (int)$lastNo + 1;
            if ($end > CITY_AUDIT_MAX_ROAD_LENGTH) {
                throw new DomainException('The road cannot be longer than ' . CITY_AUDIT_MAX_ROAD_LENGTH . ' metres.');
            }

            $this->pdo->prepare(
                'INSERT INTO segments
                   (public_id, road_id, segment_number, start_label, end_label,
                    start_distance, end_distance, length)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'SEG-R' . $roadId . 'N' . $no, $roadId, $no,
                cityAuditDistanceLabel($start), cityAuditDistanceLabel($end),
                $start, $end, $length,
            ]);
            $segId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE segments SET public_id = ? WHERE id = ?')
                      ->execute(['SEG-' . str_pad((string)$segId, 4, '0', STR_PAD_LEFT), $segId]);

            $sum = $this->pdo->prepare('SELECT COALESCE(SUM(length), 0) FROM segments WHERE road_id = ?');
            $sum->execute([$roadId]);
            $total = round((float)$sum->fetchColumn(), 2);
            $this->pdo->prepare('UPDATE roads SET total_length = ? WHERE id = ?')->execute([$total, $roadId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['road_id' => $roadId, 'segment_id' => $segId, 'segment_number' => $no, 'total_length' => $total];
    }

    /** Remove a road (and its segments) from an audit while nothing has been audited on it. */
    public function removeRoad(array $audit, int $roadId): void
    {
        if (!$this->isEditable($audit)) {
            throw new DomainException('Roads can no longer be removed from this audit.');
        }
        $r = $this->pdo->prepare('SELECT id, finalized_at FROM roads WHERE id = ? AND audit_id = ?');
        $r->execute([$roadId, (int)$audit['id']]);
        $road = $r->fetch(PDO::FETCH_ASSOC);
        if ($road === false) {
            throw new DomainException('That road is not part of this audit.');
        }
        if ($road['finalized_at'] !== null) {
            throw new DomainException('This road is already finalised and cannot be removed.');
        }
        $started = $this->pdo->prepare(
            'SELECT COUNT(*) FROM segments WHERE road_id = ? AND status <> \'pending\''
        );
        $started->execute([$roadId]);
        if ((int)$started->fetchColumn() > 0) {
            throw new DomainException('Auditing has already started on this road, so it cannot be removed.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM segment_assignments WHERE segment_id IN (SELECT id FROM segments WHERE road_id = ?)'
            )->execute([$roadId]);
            $this->pdo->prepare('DELETE FROM segments WHERE road_id = ?')->execute([$roadId]);
            $this->pdo->prepare('DELETE FROM roads WHERE id = ?')->execute([$roadId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Delete one segment that was created by mistake, only while nothing has been audited on it.
     * Its surveyor assignment goes with it. The road's total length follows the segments left,
     * and the remaining segments keep their numbers. If it was the road's last segment, the
     * road is removed too.
     *
     * @param array<string,mixed> $audit  row from find()
     * @return array{road_id:int, remaining:int}
     */
    public function removeSegment(array $audit, int $segmentId): array
    {
        if (!$this->isEditable($audit)) {
            throw new DomainException('Segments can no longer be deleted from this audit.');
        }
        $q = $this->pdo->prepare(
            'SELECT s.id, s.road_id, s.status, r.finalized_at
               FROM segments s JOIN roads r ON r.id = s.road_id
              WHERE s.id = ? AND r.audit_id = ?'
        );
        $q->execute([$segmentId, (int)$audit['id']]);
        $seg = $q->fetch(PDO::FETCH_ASSOC);
        if ($seg === false) {
            throw new DomainException('That segment is not part of this audit.');
        }
        if ($seg['finalized_at'] !== null) {
            throw new DomainException('This road is already finalised, so its segments cannot be deleted.');
        }
        if ((string)$seg['status'] !== 'pending') {
            throw new DomainException('Auditing has already started on this segment, so it cannot be deleted.');
        }
        $roadId = (int)$seg['road_id'];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM segment_assignments WHERE segment_id = ?')->execute([$segmentId]);
            $this->pdo->prepare('DELETE FROM segments WHERE id = ?')->execute([$segmentId]);
            $left = $this->pdo->prepare('SELECT COUNT(*), COALESCE(SUM(length), 0) FROM segments WHERE road_id = ?');
            $left->execute([$roadId]);
            [$remaining, $sum] = array_map('floatval', $left->fetch(PDO::FETCH_NUM));
            if ((int)$remaining === 0) {
                $this->pdo->prepare('DELETE FROM roads WHERE id = ?')->execute([$roadId]);
            } else {
                $this->pdo->prepare('UPDATE roads SET total_length = ? WHERE id = ?')->execute([$sum, $roadId]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['road_id' => $roadId, 'remaining' => (int)$remaining];
    }

    // ── Surveyor assignment (Slice 3) ─────────────────────────────

    /** Active surveyors a City Leader may assign: their own team when the city has several
     *  City Leaders, every surveyor of the city otherwise. $leaderId null = no team limit.
     *  @return list<array{id:int,name:string}> */
    public function assignableSurveyors(int $cityId, ?int $leaderId = null): array
    {
        [$scope, $scopeArgs] = teamScope($this->pdo, $cityId, $leaderId);
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM users
              WHERE role = 'surveyor' AND is_active = 1 AND city_id = ?" . $scope . "
              ORDER BY name ASC, id ASC"
        );
        $stmt->execute(array_merge([$cityId], $scopeArgs));
        return array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function requireCitySurveyor(int $cityId, int $surveyorId, ?int $leaderId = null): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users
              WHERE id = ? AND role = 'surveyor' AND is_active = 1 AND city_id = ?"
        );
        $stmt->execute([$surveyorId, $cityId]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new DomainException('That surveyor is not an active surveyor in this city.');
        }
        require_once __DIR__ . '/SurveyorRemovalRepository.php';
        if ((new SurveyorRemovalRepository($this->pdo))->isPending($surveyorId)) {
            throw new DomainException('That surveyor is being removed and cannot be given new segments.');
        }
        [$scope, $scopeArgs] = teamScope($this->pdo, $cityId, $leaderId);
        if ($scope !== '') {
            $t = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?' . $scope);
            $t->execute(array_merge([$surveyorId], $scopeArgs));
            if ((int)$t->fetchColumn() === 0) {
                throw new DomainException('That surveyor is not in your team. Add them on the My team page first.');
            }
        }
    }

    /**
     * Give the segments to one surveyor, or clear them when $surveyorId is null.
     * Only pending segments of this audit can change.
     *
     * @param array<string,mixed> $audit      row from find()
     * @param list<int>           $segmentIds
     * @return int number of segments updated
     */
    public function assignSegments(array $audit, int $byUserId, array $segmentIds, ?int $surveyorId): int
    {
        if (!$this->canAssign($audit)) {
            throw new DomainException('Surveyors can no longer be assigned in this audit.');
        }
        $ids = array_values(array_unique(array_map('intval', $segmentIds)));
        if (!$ids) {
            throw new DomainException('Select at least one segment.');
        }
        if ($surveyorId !== null) {
            $this->requireCitySurveyor(
                (int)$audit['city_id'],
                $surveyorId,
                isset($audit['leader_user_id']) ? (int)$audit['leader_user_id'] : null
            );
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $q  = $this->pdo->prepare(
            "SELECT s.id, s.status FROM segments s
               JOIN roads r ON r.id = s.road_id
              WHERE r.audit_id = ? AND s.id IN ($in)"
        );
        $q->execute(array_merge([(int)$audit['id']], $ids));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new DomainException('Some of those segments are not part of this audit.');
        }
        foreach ($rows as $row) {
            if ($row['status'] !== 'pending') {
                throw new DomainException('Auditing has already started on a segment, so it cannot be reassigned.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM segment_assignments WHERE segment_id IN ($in)")->execute($ids);
            if ($surveyorId !== null) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, assigned_by)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($ids as $segId) {
                    $ins->execute([(int)$audit['id'], $segId, $surveyorId, $byUserId]);
                }
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('Someone changed these segments at the same time. Reload and try again.');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return count($ids);
    }

    /**
     * Give every pending segment of a road to one surveyor (null clears them).
     * Segments where auditing already started are left alone and counted as skipped.
     *
     * @return array{assigned:int, skipped:int}
     */
    public function assignRoad(array $audit, int $byUserId, int $roadId, ?int $surveyorId): array
    {
        $r = $this->pdo->prepare('SELECT id FROM roads WHERE id = ? AND audit_id = ?');
        $r->execute([$roadId, (int)$audit['id']]);
        if ($r->fetch(PDO::FETCH_ASSOC) === false) {
            throw new DomainException('That road is not part of this audit.');
        }
        $s = $this->pdo->prepare('SELECT id, status FROM segments WHERE road_id = ? ORDER BY segment_number ASC');
        $s->execute([$roadId]);
        $pending = [];
        $skipped = 0;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'pending') {
                $pending[] = (int)$row['id'];
            } else {
                $skipped++;
            }
        }
        if (!$pending) {
            throw new DomainException('Auditing has already started on every segment of this road.');
        }
        $n = $this->assignSegments($audit, $byUserId, $pending, $surveyorId);
        return ['assigned' => $n, 'skipped' => $skipped];
    }

    /**
     * Who holds each of these segments right now (segments with nobody are left out).
     *
     * @param list<int> $segmentIds
     * @return array<int,int> segment id => surveyor id
     */
    public function assigneesOfSegments(int $auditId, array $segmentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $segmentIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q  = $this->pdo->prepare("SELECT segment_id, surveyor_id FROM segment_assignments WHERE audit_id = ? AND segment_id IN ($in)");
        $q->execute(array_merge([$auditId], $ids));
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['segment_id']] = (int)$row['surveyor_id'];
        }
        return $out;
    }

    /** Ids of the segments of one road of this audit (only the pending ones when asked).
     *  @return list<int> */
    public function segmentIdsOfRoad(int $auditId, int $roadId, bool $pendingOnly = false): array
    {
        $q = $this->pdo->prepare(
            'SELECT s.id FROM segments s JOIN roads r ON r.id = s.road_id
              WHERE r.audit_id = ? AND r.id = ?' . ($pendingOnly ? " AND s.status = 'pending'" : '') . ' ORDER BY s.segment_number ASC'
        );
        $q->execute([$auditId, $roadId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{total:int, assigned:int} segment counts for the whole audit */
    public function assignmentCounts(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(s.id) AS total, COUNT(sa.id) AS assigned
               FROM roads r
               JOIN segments s ON s.road_id = r.id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
              WHERE r.audit_id = ?'
        );
        $stmt->execute([$auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (int)($row['total'] ?? 0), 'assigned' => (int)($row['assigned'] ?? 0)];
    }

    /**
     * Move a draft audit to active. At least one segment must have a surveyor, and every
     * assigned segment must have an active surveyor of this city. The rest can be assigned
     * after activation. Roads and segments can still be added or removed later, as long as
     * no auditing has started on them.
     */
    public function activate(array $audit): void
    {
        if ($audit['status'] !== 'draft') {
            throw new DomainException('Only a draft audit can be activated.');
        }
        $c = $this->assignmentCounts((int)$audit['id']);
        if ($c['total'] === 0) {
            throw new DomainException('Add at least one road before activating this audit.');
        }
        if ($c['assigned'] < 1) {
            throw new DomainException('Assign at least one segment to a surveyor before activating this audit.');
        }

        $bad = $this->pdo->prepare(
            "SELECT COUNT(*) FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
                                AND u.role = 'surveyor' AND u.is_active = 1 AND u.city_id = ?
              WHERE r.audit_id = ? AND u.id IS NULL"
        );
        $bad->execute([(int)$audit['city_id'], (int)$audit['id']]);
        if ((int)$bad->fetchColumn() > 0) {
            throw new DomainException('Some segments are assigned to a surveyor who is no longer active in this city. Reassign them first.');
        }

        $upd = $this->pdo->prepare("UPDATE city_audits SET status = 'active' WHERE id = ? AND status = 'draft'");
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already activated.');
        }
    }
}
