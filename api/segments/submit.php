<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/submit.php
//  POST (multipart/form-data) — full segment audit submission.
//
//  CHANGES IN THIS VERSION:
//    • Uses helpers/Validator.php for all input validation
//      (replaces the manual required-field loops and int casts)
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../helpers/SegmentAnswers.php';
require_once __DIR__ . '/../../repositories/AuditSessionRepository.php';
require_once __DIR__ . '/../../repositories/SurveyorWorkRepository.php';
require_once __DIR__ . '/../../helpers/WorkNotify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── CSRF verification ──────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Input validation via Validator ────────────────────────────
require_once __DIR__ . '/../../config/rate_limit.php';

$rl = checkAndRecordApiRequest($pdo, 'user:' . $CURRENT_USER_ID, 'segment_submit', 30, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

$v = Validator::make($_POST)
    ->required('session_id', 'segment_id')
    ->integer('session_id', 'segment_id')
    ->min('session_id', 1)
    ->min('segment_id', 1)
    ->required('start_landmark', 'end_landmark', 'gps_start', 'gps_end')
    ->maxLength('start_landmark', 255)
    ->maxLength('end_landmark', 255)
    ->regex('start_landmark', '/^[a-zA-Z0-9\s]+$/')
    ->regex('end_landmark', '/^[a-zA-Z0-9\s]+$/')
    ->maxLength('gps_start', 100)
    ->maxLength('gps_end', 100)
    ->regex('gps_start', '/^-?[0-9]{1,3}\.[0-9]+,\s*-?[0-9]{1,3}\.[0-9]+$/')
    ->regex('gps_end', '/^-?[0-9]{1,3}\.[0-9]+,\s*-?[0-9]{1,3}\.[0-9]+$/')
    ->maxLength('comments', 2000)
    ->json('intersections');

if ($v->fails()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

// ── Validated, safe values ────────────────────────────────────
$sessionId = (int)$_POST['session_id'];
$segmentId = (int)$_POST['segment_id'];
$editMode  = !empty($_POST['edit_mode']) && $_POST['edit_mode'] === '1';

try {
    $pdo->beginTransaction();

    // ── 1. Lock segment — prevent duplicate submissions ────────
    $stmtSeg = $pdo->prepare(
        'SELECT s.status, s.road_id, s.segment_number, s.length, r.creator_id, r.finalized_at
         FROM   segments s
         JOIN   roads r ON r.id = s.road_id
         WHERE  s.id = ?
         FOR UPDATE'
    );
    $stmtSeg->execute([$segmentId]);
    $segment = $stmtSeg->fetch(PDO::FETCH_ASSOC);

    if ($segment === false) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    if ($segment['finalized_at'] !== null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This road has been finalized and can no longer be edited.']);
        exit;
    }

    if ($segment['status'] === 'completed') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This segment has already been audited and is locked.']);
        exit;
    }

    // ── 2. Verify session ownership ────────────────────────────
    $stmtSess = $pdo->prepare(
        'SELECT id, road_id FROM audit_sessions
         WHERE  id = ? AND user_id = ? AND status = \'active\'
         LIMIT  1'
    );
    $stmtSess->execute([$sessionId, $CURRENT_USER_ID]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if ($session === false) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session not found or not owned by you.']);
        exit;
    }

    // ── 2b. City-audit roads: only the assigned surveyor may submit ──
    $blockReason = (new SurveyorWorkRepository($pdo))->submitBlockReason($segmentId, (int)$CURRENT_USER_ID);
    if ($blockReason !== null) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $blockReason]);
        exit;
    }

    // Ensure segment belongs to the session's road
    if ((int)$session['road_id'] !== (int)$segment['road_id']) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Segment does not belong to this session\'s road.']);
        exit;
    }

    // ── 2c. Every answer the score needs must be given ─────────
    // A blank form would otherwise get an invented score (the engine reads
    // a blank buffer zone as worst and a blank shade as best).
    $answerError = segmentAnswersError(
        $_POST,
        (float)($segment['length'] ?? 0),
        (array)(json_decode((string)($_POST['intersections'] ?? '[]'), true) ?: [])
    );
    if ($answerError !== null) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $answerError]);
        exit;
    }

    // ── 3. INSERT or UPDATE segment_audit ────────────────────
    $mainFields = [
        'start_landmark', 'end_landmark', 'gps_start', 'gps_end',
        'cycle_track_missing', 'missing_length', 'cyclist_use', 'better_surface',
        'surface_material', 'people_walking', 'signage_count', 'shade',
        'light_after_sunset', 'track_geometry', 'buffer_zone',
        'segment_width', 'comments',
    ];

    $mainValues = array_map(
        static fn(string $f) => isset($_POST[$f]) && trim((string)$_POST[$f]) !== ''
            ? trim((string)$_POST[$f])
            : null,
        $mainFields
    );

    // Integer fields must store 0 when blank, not NULL.
    $integerFields = ['signage_count'];
    foreach ($mainFields as $i => $f) {
        if (in_array($f, $integerFields, true) && $mainValues[$i] === null) {
            $mainValues[$i] = 0;
        }
    }

    // JSON multi-select fields
    $surfaceIssues  = json_encode(array_values(array_filter((array)($_POST['surface_issues']  ?? []))));
    $overheadIssues = json_encode(array_values(array_filter((array)($_POST['overhead_issues'] ?? []))));
    $footpathRating = json_encode(array_values(array_filter((array)($_POST['footpath_rating'] ?? []))));
    // Footpath score: sum the weight of each footpath_rating[] criterion
    // the surveyor actually checked. Weights mirror updateFootpathScore()
    // in js/form.js so the live preview shown while filling the form
    // matches what gets stored.
    $footpathWeights = [
        'minWidth'         => 30,
        'obstructionFree'  => 30,
        'continuous'       => 20,
        'disabledFriendly' => 15,
        'comfort'          => 5,
    ];
    $checkedFootpathCriteria = json_decode($footpathRating, true) ?? [];
    $footpathScore = 0;
    foreach ($footpathWeights as $key => $weight) {
        if (in_array($key, $checkedFootpathCriteria, true)) {
            $footpathScore += $weight;
        }
    }

    if ($editMode) {
        // ── EDIT: update the most-recent audit row ─────────────
        $stmtExisting = $pdo->prepare(
            'SELECT id FROM segment_audits WHERE segment_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmtExisting->execute([$segmentId]);
        $existingAudit = $stmtExisting->fetch(PDO::FETCH_ASSOC);

        if (!$existingAudit) {
            // Fallback: no prior record — treat as a fresh insert
            $editMode = false;
        } else {
            $auditId = (int)$existingAudit['id'];

            $setClauses = implode(', ', array_map(fn($f) => "{$f} = ?", $mainFields));
            $pdo->prepare(
                "UPDATE segment_audits
                    SET {$setClauses},
                        surface_issues = ?, overhead_issues = ?,
                        footpath_rating = ?, footpath_score = ?,
                        surveyor_id = ?, session_id = ?
                  WHERE id = ?"
            )->execute(array_merge(
                $mainValues,
                [$surfaceIssues, $overheadIssues, $footpathRating, $footpathScore,
                 $CURRENT_USER_ID, $sessionId, $auditId]
            ));

            $auditPublicId = 'SA-' . str_pad((string)$auditId, 4, '0', STR_PAD_LEFT);

            // Delete old obstructions and intersections so they
            // can be re-inserted cleanly from the edited form data.
            $pdo->prepare('DELETE FROM obstructions   WHERE audit_id = ?')->execute([$auditId]);
            $pdo->prepare('DELETE FROM intersections  WHERE audit_id = ?')->execute([$auditId]);
        }
    }

    if (!$editMode) {
        // ── FRESH INSERT ───────────────────────────────────────
        $placeholders = implode(',', array_fill(0, count($mainFields), '?'));
        $columns      = implode(',', $mainFields);

        $pdo->prepare(
            "INSERT INTO segment_audits (session_id, segment_id, surveyor_id, {$columns})
             VALUES (?, ?, ?, {$placeholders})"
        )->execute(array_merge([$sessionId, $segmentId, $CURRENT_USER_ID], $mainValues));

        $auditId = (int)$pdo->lastInsertId();

        // Generate public_id
        $auditPublicId = 'SA-' . str_pad((string)$auditId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE segment_audits SET public_id = ? WHERE id = ?')
            ->execute([$auditPublicId, $auditId]);

        $pdo->prepare(
            'UPDATE segment_audits
             SET surface_issues = ?, overhead_issues = ?, footpath_rating = ?, footpath_score = ?
             WHERE id = ?'
        )->execute([$surfaceIssues, $overheadIssues, $footpathRating, $footpathScore, $auditId]);
    }

    // ── 4. INSERT obstructions ─────────────────────────────────
    $obsCategories = segmentObstructionCategories();

    $obsStmt = $pdo->prepare(
        'INSERT INTO obstructions
           (audit_id, obstruction_category, obstruction_type,
            cyclist_slowed, partial_obstructions, total_obstructions)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($obsCategories as $category => $types) {
        foreach ($types as $type) {
            $slowed  = (int)($_POST["{$category}_{$type}_slowed"]  ?? 0);
            $partial = (int)($_POST["{$category}_{$type}_partial"] ?? 0);
            $total   = (int)($_POST["{$category}_{$type}_total"]   ?? 0);

            if ($slowed + $partial + $total > 0) {
                $obsStmt->execute([$auditId, $category, $type, $slowed, $partial, $total]);

                // Generate public_id for obstruction
                $obsId       = (int)$pdo->lastInsertId();
                $obsPublicId = 'OBS-' . str_pad((string)$obsId, 4, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE obstructions SET public_id = ? WHERE id = ?')
                    ->execute([$obsPublicId, $obsId]);
            }
        }
    }

    // ── 5. INSERT intersections ────────────────────────────────
    $intersections = json_decode($_POST['intersections'] ?? '[]', true);
    if (is_array($intersections) && !empty($intersections)) {
        $intStmt = $pdo->prepare(
            'INSERT INTO intersections
                (audit_id, intersection_num, gps_coords, landmark_name,
                off_ramp, on_ramp, markings, signage,
                traffic_calming, discontinuity, tapering, obstruction_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
    // Allowlist validation for intersection enum fields
    $intAllowlists = [
        'off_ramp'        => ['Comfortable', 'Uncomfortable', 'No Ramp'],
        'on_ramp'         => ['Comfortable', 'Uncomfortable', 'No Ramp'],
        'markings'        => ['Present', 'Absent'],
        'signage'         => ['Present', 'Absent'],
        'traffic_calming' => ['Present', 'Absent'],
    ];
    if (is_array($intersections)) {
        foreach ($intersections as $idx => $i) {
            foreach ($intAllowlists as $field => $allowed) {
                $val = $i[$field] ?? null;
                if ($val !== null && $val !== '' && !in_array($val, $allowed, true)) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'error'   => "Invalid value for intersection field '{$field}'.",
                    ]);
                    exit;
                }
            }
        }
    }
        foreach ($intersections as $idx => $i) {
            $intStmt->execute([
                $auditId,
                $idx + 1,
                $i['gps_coords']       ?? null,
                $i['landmark_name']    ?? null,
                $i['off_ramp']         ?? null,
                $i['on_ramp']          ?? null,
                $i['markings']         ?? null,
                $i['signage']          ?? null,
                $i['traffic_calming']  ?? null,
                $i['discontinuity']    ?? null,
                $i['tapering']         ?? null,
                $i['obstruction_type'] ?? null,
            ]);

            // Generate public_id for intersection
            $intId       = (int)$pdo->lastInsertId();
            $intPublicId = 'INT-' . str_pad((string)$intId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE intersections SET public_id = ? WHERE id = ?')
                ->execute([$intPublicId, $intId]);
        }
    }

    // ── 6. Mark segment completed ──────────────────────────────
    $pdo->prepare(
        'UPDATE segments SET status = \'completed\', completed_at = NOW() WHERE id = ?'
    )->execute([$segmentId]);

    // ── 6-assign. Mark the surveyor's assignment as submitted (city audits) ──
    (new SurveyorWorkRepository($pdo))->markSubmitted($segmentId, (int)$CURRENT_USER_ID);

    // ── 6a. Auto-complete the session if all its segments are now done ──
    // Mirrors api/segments/complete.php, which already does this on the
    // manual-completion path. submit.php (the real audit-submission path)
    // was missing this call, so sessions never flipped from 'active' to
    // 'completed' when a user finished a road through the normal form flow.
    $sessionRepo = new AuditSessionRepository($pdo);
    $sessionRepo->autoCompleteIfReady($sessionId);

    // ── 6b. Derive road-level Start/End from segment 1 / last segment ──
    // The Road Info page no longer asks the surveyor to guess the road's
    // start/end landmark up front — instead segment 1's start (captured
    // here) becomes the road's start, and the last segment's end becomes
    // the road's end, once each is actually audited.
    $segNumber    = (int)$segment['segment_number'];
    $roadIdForSeg = (int)$segment['road_id'];

    $startLandmark = trim((string)($_POST['start_landmark'] ?? ''));
    $endLandmark   = trim((string)($_POST['end_landmark']   ?? ''));
    $gpsStartVal   = trim((string)($_POST['gps_start']      ?? ''));
    $gpsEndVal     = trim((string)($_POST['gps_end']        ?? ''));

    if ($segNumber === 1 && $startLandmark !== '') {
        $pdo->prepare(
            'UPDATE roads SET start_point = ?, gps_start = ? WHERE id = ?'
        )->execute([$startLandmark, $gpsStartVal !== '' ? $gpsStartVal : null, $roadIdForSeg]);
    }

    $stmtMaxSeg = $pdo->prepare('SELECT MAX(segment_number) FROM segments WHERE road_id = ?');
    $stmtMaxSeg->execute([$roadIdForSeg]);
    $maxSegNumber = (int)$stmtMaxSeg->fetchColumn();

    if ($segNumber === $maxSegNumber && $endLandmark !== '') {
        $pdo->prepare(
            'UPDATE roads SET end_point = ?, gps_end = ? WHERE id = ?'
        )->execute([$endLandmark, $gpsEndVal !== '' ? $gpsEndVal : null, $roadIdForSeg]);
    }

    // ── 7. Log activity ────────────────────────────────────────
    ActivityLogger::log($pdo, $editMode ? ActivityLogger::SEGMENT_EDITED : ActivityLogger::SEGMENT_SUBMITTED, $CURRENT_USER_ID, [
        'audit_id'   => $auditId,
        'segment_id' => $segmentId,
        'session_id' => $sessionId,
    ]);

    // ── 8. COMMIT ──────────────────────────────────────────────
    $pdo->commit();

    // Tell the City Leader, and finish a removal that was waiting for this surveyor's last segment.
    // Never lets a notification problem fail a submission that is already saved.
    if (!$editMode) {
        workAfterSubmit($pdo, $segmentId, (int)$CURRENT_USER_ID);
    }

    echo json_encode([
        'success'   => true,
        'audit_id'  => $auditId,
        'public_id' => $auditPublicId,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api/segments/submit.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
