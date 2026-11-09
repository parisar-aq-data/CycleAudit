<?php
declare(strict_types=1);

/**
 * services/ScoreService.php
 * Refactored to use ScoreHelpers class.
 * Main scoring orchestration logic.
 *
 * Issue 7/8 fix: calculateRoadScore() and calculateSegmentScoreDetailed()
 * now use batch queries — 3 queries total per road regardless of segment count,
 * down from 3 × N (or 6 × N for detailed). All score computation delegated
 * to _computeScoreFromData() which is pure PHP with no DB access.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/ScoreHelpers.php';

// ── Category weights (must match PDF) ────────────────────────
const WEIGHT_SAFETY      = 1.0;
const WEIGHT_COMFORT     = 1.25;
const WEIGHT_CONTINUITY  = 1.5;
const WEIGHT_TOTAL       = 3.75; // 1.0 + 1.25 + 1.5

// ── Backward compatibility: Function wrappers ─────────────────

function penaltyBufferZone(?string $bufferZone): float
{
    return ScoreHelpers::bufferZone($bufferZone);
}

function penaltyLight(?string $light): float
{
    return ScoreHelpers::lightAfterDark($light);
}

function penaltyTrafficCalming(int $absentCount): float
{
    return ScoreHelpers::trafficCalming($absentCount);
}

function penaltyPartialObstructions(float $count): float
{
    return ScoreHelpers::partialObstructions($count);
}

function penaltyMissingRamps(int $missingRampCount): float
{
    return ScoreHelpers::missingRamps($missingRampCount);
}

function penaltyMissingSignage(int $absentSignCount): float
{
    return ScoreHelpers::missingSignage($absentSignCount);
}

function penaltyTotalObstructions(float $count): float
{
    return ScoreHelpers::totalObstructions($count);
}

function penaltySurface(?string $material): float
{
    return ScoreHelpers::surface($material);
}

function penaltyCyclistSlowed(float $count): float
{
    return ScoreHelpers::cyclistSlowed($count);
}

function penaltyShade(?string $shade): float
{
    return ScoreHelpers::shade($shade);
}

function applyMissingLength(float $rawScore, float $missingLen, float $presentLen, float $totalLen): float
{
    return ScoreHelpers::applyMissingLength($rawScore, $missingLen, $presentLen, $totalLen);
}

function scoreToCondition(float $score): string
{
    return ScoreHelpers::scoreToCondition($score);
}

function penaltyToCondition(float $score): string
{
    return ScoreHelpers::scoreToCondition($score);
}

function conditionColour(string $condition): string
{
    return ScoreHelpers::conditionColor($condition);
}

function ratingColour(string $rating): string
{
    return ScoreHelpers::conditionColor($rating);
}

/**
 * Calculate the full score breakdown for one segment audit.
 * Fires 3 queries: audit row, obstructions aggregate, intersections list.
 * For scoring multiple segments on the same road use calculateRoadScore()
 * which batches all three queries across all segments in one pass.
 */
function calculateSegmentScore(int $segmentAuditId, PDO $pdo): array
{
    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone,
                sa.light_after_sunset,
                sa.shade,
                sa.surface_material,
                sa.cycle_track_missing,
                sa.missing_length,
                s.length AS segment_length,
                r.name   AS road_name
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         JOIN   roads    r ON r.id = s.road_id
         WHERE  sa.id = ?
         LIMIT  1'
    );
    $stmtAudit->execute([$segmentAuditId]);
    $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

    if ($audit === false) {
        throw new \InvalidArgumentException(
            "ScoreService: segment_audit id={$segmentAuditId} not found."
        );
    }

    $stmtObs = $pdo->prepare(
        'SELECT COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id = ?'
    );
    $stmtObs->execute([$segmentAuditId]);
    $obs = $stmtObs->fetch(PDO::FETCH_ASSOC);

    $stmtInt = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id = ?'
    );
    $stmtInt->execute([$segmentAuditId]);
    $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

    return _computeScoreFromData($audit, $obs, $intersections);
}

/**
 * Calculate length-weighted road score.
 *
 * FIXED (Issue 7): Was N+1 — called calculateSegmentScore() per segment
 * which fired 3 queries each. Now uses exactly 3 batch queries for the
 * entire road regardless of segment count, then computes in PHP.
 *
 * Query plan:
 *   Q1 — all latest segment_audits for the road (1 query)
 *   Q2 — all obstructions for those audit IDs (1 query)
 *   Q3 — all intersections for those audit IDs (1 query)
 */
function calculateRoadScore(int $roadId, PDO $pdo): ?array
{
    // ── Q1: Fetch all latest segment audits for this road ─────
    $stmt = $pdo->prepare(
        'SELECT s.id      AS segment_id,
                s.length,
                sa.id     AS audit_id,
                sa.buffer_zone,
                sa.light_after_sunset,
                sa.shade,
                sa.surface_material,
                sa.cycle_track_missing,
                sa.missing_length,
                s.length  AS segment_length,
                r.name    AS road_name
         FROM   segments s
         JOIN   segment_audits sa
                ON  sa.segment_id = s.id
                AND sa.id = (
                      SELECT MAX(sa2.id)
                      FROM   segment_audits sa2
                      WHERE  sa2.segment_id = s.id
                    )
         JOIN   roads r ON r.id = s.road_id
         WHERE  s.road_id = ?
         ORDER  BY s.segment_number ASC'
    );
    $stmt->execute([$roadId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    // Index rows by audit_id for fast lookup
    $auditIds  = array_column($rows, 'audit_id');
    $auditMap  = array_column($rows, null, 'audit_id');

    // ── Q2: Batch-fetch obstructions for all audit IDs ────────
    $placeholders = implode(',', array_fill(0, count($auditIds), '?'));
    $stmtObs = $pdo->prepare(
        "SELECT audit_id,
                COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id IN ({$placeholders})
         GROUP  BY audit_id"
    );
    $stmtObs->execute($auditIds);
    $obsMap = [];
    foreach ($stmtObs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $obsMap[(int)$row['audit_id']] = $row;
    }

    // ── Q3: Batch-fetch intersections for all audit IDs ───────
    $stmtInt = $pdo->prepare(
        "SELECT audit_id, off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id IN ({$placeholders})"
    );
    $stmtInt->execute($auditIds);
    $intMap = [];
    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $intMap[(int)$row['audit_id']][] = $row;
    }

    // ── Compute scores in PHP from in-memory data ─────────────
    $totalLength        = 0.0;
    $weightedFinal      = 0.0;
    $weightedSafety     = 0.0;
    $weightedContinuity = 0.0;
    $weightedComfort    = 0.0;

    foreach ($rows as $row) {
        $auditId = (int)$row['audit_id'];
        $obs     = $obsMap[$auditId] ?? ['partial' => 0, 'total' => 0, 'slowed' => 0];
        $ints    = $intMap[$auditId]  ?? [];

        $seg = _computeScoreFromData($row, $obs, $ints);
        $len = max(0.0, (float)$row['length']);

        $totalLength        += $len;
        $weightedFinal      += $seg['final']            * $len;
        $weightedSafety     += $seg['safety_score']     * $len;
        $weightedContinuity += $seg['continuity_score'] * $len;
        $weightedComfort    += $seg['comfort_score']    * $len;
    }

    if ($totalLength <= 0.0) {
        return null;
    }

    $roadScore      = round($weightedFinal      / $totalLength, 2);
    $roadSafety     = round($weightedSafety     / $totalLength, 2);
    $roadContinuity = round($weightedContinuity / $totalLength, 2);
    $roadComfort    = round($weightedComfort    / $totalLength, 2);

    $condition = ScoreHelpers::scoreToCondition($roadScore);

    return [
        'score'            => $roadScore,
        'condition'        => $condition,
        'rating'           => $condition,
        'safety_score'     => $roadSafety,
        'continuity_score' => $roadContinuity,
        'comfort_score'    => $roadComfort,
        'segment_count'    => count($rows),
    ];
}

/**
 * Length-weighted score across MULTIPLE road ids at once (a whole
 * road_group — i.e. every duplicate `roads` row that represents the
 * same real-world road). Identical batching/computation approach to
 * calculateRoadScore(), just with `WHERE s.road_id IN (...)` instead
 * of `= ?`, so a road_group with several member roads gets one
 * correctly length-weighted score instead of the caller having to
 * average per-road scores (which would weight by road count, not by
 * actual audited length).
 *
 * Used by the city-wide score sheet export (api/reports/export-city-excel.php)
 * — one call per road_group, plus one call across every road_group's
 * combined ids for the city total row.
 */
function calculateRoadGroupScore(array $roadIds, PDO $pdo): ?array
{
    $roadIds = array_values(array_unique(array_map('intval', $roadIds)));
    if (empty($roadIds)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($roadIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT s.id      AS segment_id,
                s.length,
                sa.id     AS audit_id,
                sa.buffer_zone,
                sa.light_after_sunset,
                sa.shade,
                sa.surface_material,
                sa.cycle_track_missing,
                sa.missing_length,
                s.length  AS segment_length,
                r.name    AS road_name
         FROM   segments s
         JOIN   segment_audits sa
                ON  sa.segment_id = s.id
                AND sa.id = (
                      SELECT MAX(sa2.id)
                      FROM   segment_audits sa2
                      WHERE  sa2.segment_id = s.id
                    )
         JOIN   roads r ON r.id = s.road_id
         WHERE  s.road_id IN ({$placeholders})
         ORDER  BY s.segment_number ASC"
    );
    $stmt->execute($roadIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $auditIds = array_column($rows, 'audit_id');

    $obsPlaceholders = implode(',', array_fill(0, count($auditIds), '?'));
    $stmtObs = $pdo->prepare(
        "SELECT audit_id,
                COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id IN ({$obsPlaceholders})
         GROUP  BY audit_id"
    );
    $stmtObs->execute($auditIds);
    $obsMap = [];
    foreach ($stmtObs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $obsMap[(int)$row['audit_id']] = $row;
    }

    $stmtInt = $pdo->prepare(
        "SELECT audit_id, off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id IN ({$obsPlaceholders})"
    );
    $stmtInt->execute($auditIds);
    $intMap = [];
    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $intMap[(int)$row['audit_id']][] = $row;
    }

    $totalLength        = 0.0;
    $weightedFinal      = 0.0;
    $weightedSafety     = 0.0;
    $weightedContinuity = 0.0;
    $weightedComfort    = 0.0;

    foreach ($rows as $row) {
        $auditId = (int)$row['audit_id'];
        $obs     = $obsMap[$auditId] ?? ['partial' => 0, 'total' => 0, 'slowed' => 0];
        $ints    = $intMap[$auditId]  ?? [];

        $seg = _computeScoreFromData($row, $obs, $ints);
        $len = max(0.0, (float)$row['length']);

        $totalLength        += $len;
        $weightedFinal      += $seg['final']            * $len;
        $weightedSafety     += $seg['safety_score']     * $len;
        $weightedContinuity += $seg['continuity_score'] * $len;
        $weightedComfort    += $seg['comfort_score']    * $len;
    }

    if ($totalLength <= 0.0) {
        return null;
    }

    $roadScore = round($weightedFinal / $totalLength, 2);

    return [
        'score'            => $roadScore,
        'condition'        => ScoreHelpers::scoreToCondition($roadScore),
        'safety_score'     => round($weightedSafety     / $totalLength, 2),
        'continuity_score' => round($weightedContinuity / $totalLength, 2),
        'comfort_score'    => round($weightedComfort    / $totalLength, 2),
        'segment_count'    => count($rows),
    ];
}

/**
 * Per-segment final scores for MANY roads at once, using each segment's
 * LATEST audit only (same "latest audit wins" rule as calculateRoadScore()
 * and calculateRoadGroupScore(), so every surface in the app agrees).
 *
 * Batched: 3 queries total (audits, obstructions, intersections)
 * regardless of how many roads/segments/cities are involved — replaces
 * the old public-stats approach of calling calculateSegmentScore() once
 * per audit row (N+1).
 *
 * Segments with no audit are simply absent from the result.
 *
 * @param  int[] $roadIds  roads.id values to include
 * @return array<int, array{road_id:int, segment_id:int, length:float, final:float}>
 */
function loadLatestSegmentScores(array $roadIds, PDO $pdo): array
{
    $roadIds = array_values(array_unique(array_map('intval', $roadIds)));
    if (empty($roadIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($roadIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT s.id      AS segment_id,
                s.road_id AS road_id,
                s.length,
                sa.id     AS audit_id,
                sa.buffer_zone,
                sa.light_after_sunset,
                sa.shade,
                sa.surface_material,
                sa.cycle_track_missing,
                sa.missing_length,
                s.length  AS segment_length,
                r.name    AS road_name
         FROM   segments s
         JOIN   segment_audits sa
                ON  sa.segment_id = s.id
                AND sa.id = (
                      SELECT MAX(sa2.id)
                      FROM   segment_audits sa2
                      WHERE  sa2.segment_id = s.id
                    )
         JOIN   roads r ON r.id = s.road_id
         WHERE  s.road_id IN ({$ph})"
    );
    $stmt->execute($roadIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [];
    }

    $auditIds = array_column($rows, 'audit_id');
    $aph      = implode(',', array_fill(0, count($auditIds), '?'));

    $stmtObs = $pdo->prepare(
        "SELECT audit_id,
                COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id IN ({$aph})
         GROUP  BY audit_id"
    );
    $stmtObs->execute($auditIds);
    $obsMap = [];
    foreach ($stmtObs->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $obsMap[(int)$o['audit_id']] = $o;
    }

    $stmtInt = $pdo->prepare(
        "SELECT audit_id, off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id IN ({$aph})"
    );
    $stmtInt->execute($auditIds);
    $intMap = [];
    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $intMap[(int)$i['audit_id']][] = $i;
    }

    $out = [];
    foreach ($rows as $row) {
        $auditId = (int)$row['audit_id'];
        $seg = _computeScoreFromData(
            $row,
            $obsMap[$auditId] ?? ['partial' => 0, 'total' => 0, 'slowed' => 0],
            $intMap[$auditId] ?? []
        );
        $out[] = [
            'road_id'    => (int)$row['road_id'],
            'segment_id' => (int)$row['segment_id'],
            'length'     => max(0.0, (float)$row['length']),
            'final'      => (float)$seg['final'],
        ];
    }
    return $out;
}

/**
 * Detailed breakdown with parameter-level scores.
 *
 * FIXED (Issue 8): Previously called calculateSegmentScore() first (3 queries)
 * then re-ran all 3 queries independently — 6 queries total per call.
 * Now fetches data once and passes it to both _computeScoreFromData()
 * and the parameter breakdown — 3 queries total.
 */
function calculateSegmentScoreDetailed(int $segmentAuditId, PDO $pdo): array
{
    // ── Fetch all data once ───────────────────────────────────
    $stmtAudit = $pdo->prepare(
        'SELECT sa.buffer_zone, sa.light_after_sunset, sa.shade,
                sa.surface_material, sa.cycle_track_missing, sa.missing_length,
                s.length AS segment_length, r.name AS road_name
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         JOIN   roads r ON r.id = s.road_id
         WHERE  sa.id = ? LIMIT 1'
    );
    $stmtAudit->execute([$segmentAuditId]);
    $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

    if ($audit === false) {
        throw new \InvalidArgumentException(
            "ScoreService: segment_audit id={$segmentAuditId} not found."
        );
    }

    $stmtObs = $pdo->prepare(
        'SELECT COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions WHERE audit_id = ?'
    );
    $stmtObs->execute([$segmentAuditId]);
    $obs = $stmtObs->fetch(PDO::FETCH_ASSOC);

    $stmtInt = $pdo->prepare(
        'SELECT off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections WHERE audit_id = ?'
    );
    $stmtInt->execute([$segmentAuditId]);
    $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

    // ── Compute base scores from fetched data (no extra queries) ─
    $base = _computeScoreFromData($audit, $obs, $intersections);

    // ── Build parameter-level breakdown from same data ─────────
    $missingRampCount   = 0;
    $missingSignCount   = 0;
    $absentCalmingCount = 0;

    foreach ($intersections as $i) {
        $offRamp = strtolower(trim($i['off_ramp'] ?? ''));
        $onRamp  = strtolower(trim($i['on_ramp']  ?? ''));
        if ($offRamp === 'no ramp' || $onRamp === 'no ramp') $missingRampCount++;

        $markings = strtolower(trim($i['markings'] ?? ''));
        $signage  = strtolower(trim($i['signage']  ?? ''));
        if ($markings === 'absent' || $signage === 'absent') $missingSignCount++;

        $calming = strtolower(trim($i['traffic_calming'] ?? ''));
        if ($calming === 'absent') $absentCalmingCount++;
    }

    $params = [
        'safety' => [
            'buffer_zone'      => ScoreHelpers::bufferZone($audit['buffer_zone'] ?? null),
            'light_after_dark' => ScoreHelpers::lightAfterDark($audit['light_after_sunset'] ?? null),
            'traffic_calming'  => ScoreHelpers::trafficCalming($absentCalmingCount),
            'partial_obs'      => ScoreHelpers::partialObstructions((float)($obs['partial'] ?? 0)),
        ],
        'continuity' => [
            'missing_ramps'   => ScoreHelpers::missingRamps($missingRampCount),
            'missing_signage' => ScoreHelpers::missingSignage($missingSignCount),
            'total_obs'       => ScoreHelpers::totalObstructions((float)($obs['total'] ?? 0)),
        ],
        'comfort' => [
            'surface'        => ScoreHelpers::surface($audit['surface_material'] ?? null),
            'cyclist_slowed' => ScoreHelpers::cyclistSlowed((float)($obs['slowed'] ?? 0)),
            'shade'          => ScoreHelpers::shade($audit['shade'] ?? null),
        ],
    ];

    return array_merge($base, ['parameters' => $params]);
}

/**
 * Batch-compute condition scores for an arbitrary set of segment_audit IDs.
 * Same 3-query batching pattern as calculateRoadScore() (Issue 7 fix),
 * generalised to a mixed set of audits that may span multiple roads —
 * used by the "My Audits" personal history list, where sorting/filtering
 * by condition needs every candidate segment's score, not just one road's.
 *
 * @param  int[] $auditIds
 * @return array<int, array{final:float, condition:string, rating:string}> keyed by audit_id
 */
function calculateScoresForAuditIds(array $auditIds, PDO $pdo): array
{
    $auditIds = array_values(array_unique(array_map('intval', $auditIds)));
    if (empty($auditIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($auditIds), '?'));

    // ── Q1: audit rows + segment length + road name ────────────
    $stmt = $pdo->prepare(
        "SELECT sa.id AS audit_id,
                sa.buffer_zone, sa.light_after_sunset, sa.shade,
                sa.surface_material, sa.cycle_track_missing, sa.missing_length,
                s.length AS segment_length,
                r.name   AS road_name
         FROM   segment_audits sa
         JOIN   segments s ON s.id = sa.segment_id
         JOIN   roads    r ON r.id = s.road_id
         WHERE  sa.id IN ({$placeholders})"
    );
    $stmt->execute($auditIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    // ── Q2: batch obstructions ──────────────────────────────────
    $stmtObs = $pdo->prepare(
        "SELECT audit_id,
                COALESCE(SUM(partial_obstructions), 0) AS partial,
                COALESCE(SUM(total_obstructions),   0) AS total,
                COALESCE(SUM(cyclist_slowed),        0) AS slowed
         FROM   obstructions
         WHERE  audit_id IN ({$placeholders})
         GROUP  BY audit_id"
    );
    $stmtObs->execute($auditIds);
    $obsMap = [];
    foreach ($stmtObs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $obsMap[(int)$row['audit_id']] = $row;
    }

    // ── Q3: batch intersections ─────────────────────────────────
    $stmtInt = $pdo->prepare(
        "SELECT audit_id, off_ramp, on_ramp, markings, signage, traffic_calming
         FROM   intersections
         WHERE  audit_id IN ({$placeholders})"
    );
    $stmtInt->execute($auditIds);
    $intMap = [];
    foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $intMap[(int)$row['audit_id']][] = $row;
    }

    // ── Compute in PHP, keyed by audit_id ───────────────────────
    $result = [];
    foreach ($rows as $row) {
        $auditId = (int)$row['audit_id'];
        $obs     = $obsMap[$auditId] ?? ['partial' => 0, 'total' => 0, 'slowed' => 0];
        $ints    = $intMap[$auditId] ?? [];
        $result[$auditId] = _computeScoreFromData($row, $obs, $ints);
    }

    return $result;
}

/**
 * Pure computation — no DB queries.
 * Used by unit tests and batch scoring (calculateRoadScore).
 *
 * @param array $audit  Row from segment_audits JOIN segments JOIN roads
 * @param array $obs    Aggregated obstructions row (partial, total, slowed)
 * @param array $ints   Array of intersection rows
 */
function _computeScoreFromData(array $audit, array $obs, array $ints): array
{
    $partialObs    = (float)($obs['partial'] ?? 0);
    $totalObs      = (float)($obs['total']   ?? 0);
    $cyclistSlowed = (float)($obs['slowed']  ?? 0);

    $missingRampCount   = 0;
    $missingSignCount   = 0;
    $absentCalmingCount = 0;

    foreach ($ints as $i) {
        $offRamp = strtolower(trim($i['off_ramp']  ?? ''));
        $onRamp  = strtolower(trim($i['on_ramp']   ?? ''));
        if ($offRamp === 'no ramp' || $onRamp === 'no ramp') {
            $missingRampCount++;
        }

        $markings = strtolower(trim($i['markings'] ?? ''));
        $signage  = strtolower(trim($i['signage']  ?? ''));
        if ($markings === 'absent' || $signage === 'absent') {
            $missingSignCount++;
        }

        $calming = strtolower(trim($i['traffic_calming'] ?? ''));
        if ($calming === 'absent') {
            $absentCalmingCount++;
        }
    }

    $totalLen   = max(1.0, (float)($audit['segment_length'] ?? 500));
    $missingLen = 0.0;
    if (($audit['cycle_track_missing'] ?? '') === 'Yes') {
        $missingLen = max(0.0, (float)($audit['missing_length'] ?? 0));
    }
    $missingLen = min($missingLen, $totalLen);
    $presentLen = $totalLen - $missingLen;
    $allMissing = ($presentLen <= 0.0);

    // SAFETY
    if ($allMissing) {
        $safetyRaw = 100.0;
    } else {
        $safetyRaw = (
            ScoreHelpers::bufferZone($audit['buffer_zone'] ?? null) +
            ScoreHelpers::lightAfterDark($audit['light_after_sunset'] ?? null) +
            ScoreHelpers::trafficCalming($absentCalmingCount) +
            ScoreHelpers::partialObstructions($partialObs)
        ) / 4.0;
    }
    $safetyScore = ScoreHelpers::applyMissingLength($safetyRaw, $missingLen, $presentLen, $totalLen);

    // CONTINUITY
    if ($allMissing) {
        $continuityRaw = 100.0;
    } else {
        $continuityRaw = (
            ScoreHelpers::missingRamps($missingRampCount) +
            ScoreHelpers::missingSignage($missingSignCount) +
            ScoreHelpers::totalObstructions($totalObs)
        ) / 3.0;
    }
    $continuityScore = ScoreHelpers::applyMissingLength($continuityRaw, $missingLen, $presentLen, $totalLen);

    // COMFORT
    if ($allMissing) {
        $comfortRaw = 100.0;
    } else {
        $comfortRaw = (
            ScoreHelpers::surface($audit['surface_material'] ?? null) +
            ScoreHelpers::cyclistSlowed($cyclistSlowed) +
            ScoreHelpers::shade($audit['shade'] ?? null)
        ) / 3.0;
    }
    $comfortScore = ScoreHelpers::applyMissingLength($comfortRaw, $missingLen, $presentLen, $totalLen);

    // WEIGHTED FINAL
    $finalScore = (
        $safetyScore     * WEIGHT_SAFETY     +
        $comfortScore    * WEIGHT_COMFORT     +
        $continuityScore * WEIGHT_CONTINUITY
    ) / WEIGHT_TOTAL;

    $finalScore = round(max(0.0, min(100.0, $finalScore)), 2);
    $condition  = ScoreHelpers::scoreToCondition($finalScore);

    return [
        'safety_score'     => round($safetyScore,     2),
        'continuity_score' => round($continuityScore, 2),
        'comfort_score'    => round($comfortScore,    2),
        'final'            => $finalScore,
        'condition'        => $condition,
        'rating'           => $condition,
    ];
}
