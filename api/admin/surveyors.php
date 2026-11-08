<?php
declare(strict_types=1);
header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    error_log('api/admin/surveyors.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});
require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/PrimaryAdmin.php';
require_once __DIR__ . '/../../helpers/Cities.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/RoleRules.php';
require_once __DIR__ . '/../../helpers/WorkNotify.php';

$isNationalAdmin = $CURRENT_USER_ROLE === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // admin sees everyone; city_leader sees only users in their
    // own city (a city_leader with no city_id set sees nobody, rather
    // than silently falling through to "see everyone").
    $where  = '';
    $params = [];
    if (!$isNationalAdmin) {
        $where  = 'WHERE u.city_id = ?';
        $params = [$CURRENT_USER_CITY_ID];
    }

    $stmt = $pdo->prepare(
        "SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            u.role,
            u.city_id,
            c.name AS city_name,
            u.organisation,
            u.home_city,
            u.home_state,
            u.profile_picture,
            u.is_active,
            u.last_login,
            u.created_at,
            (SELECT COUNT(*) FROM roads r WHERE r.creator_id = u.id) AS roads_created,
            (SELECT COUNT(*) FROM segment_audits sa WHERE sa.surveyor_id = u.id) AS segments_audited,
            (SELECT MAX(sa2.created_at) FROM segment_audits sa2 WHERE sa2.surveyor_id = u.id) AS last_audit_at
         FROM users u
         LEFT JOIN cities c ON c.id = u.city_id
         $where
        ORDER BY u.is_active DESC, u.role = 'admin' DESC, u.role = 'city_leader' DESC, u.name ASC"
    );
    $stmt->execute($params);
    $surveyors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $primaryAdminId = primaryAdminId($pdo);
    foreach ($surveyors as &$s) {
        $s['id']               = (int)$s['id'];
        $s['city_id']          = $s['city_id'] !== null ? (int)$s['city_id'] : null;
        $s['roads_created']    = (int)$s['roads_created'];
        $s['segments_audited'] = (int)$s['segments_audited'];
        $s['is_active']        = (bool)$s['is_active'];
        $s['is_current_user']  = $s['id'] === $CURRENT_USER_ID;
        $s['is_primary_admin'] = $primaryAdminId !== null && $s['id'] === $primaryAdminId;
        $s['has_profile_picture'] = $s['profile_picture'] !== null && $s['profile_picture'] !== '';
        unset($s['profile_picture']);
    }
    unset($s);
    // Who is waiting to be removed (needs migration 031; without it nobody is).
    $waiting = [];
    try {
        $waiting = array_flip(array_map('intval', $pdo->query('SELECT id FROM users WHERE removal_pending_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        $waiting = [];
    }
    foreach ($surveyors as &$s) {
        $s['removal_pending'] = isset($waiting[$s['id']]);
    }
    unset($s);
    echo json_encode([
        'success'   => true,
        'surveyors' => $surveyors,
        // Only Admins can move users, so only they need the list.
        'cities'    => $isNationalAdmin ? listCities($pdo) : [],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF verification ──────────────────────────────────────
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if ($targetId === false || $targetId === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $check = $pdo->prepare("SELECT role, is_active, city_id, home_city FROM users WHERE id = ?");
    $check->execute([$targetId]);
    $target = $check->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    // Only the original Admin may change the original Admin's account.
    $protected = primaryAdminProtectionError($CURRENT_USER_ID, primaryAdminId($pdo), (int)$targetId);
    if ($protected !== null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $protected]);
        exit;
    }

    // A city_leader may only act on users within their own city, and
    // may never act on an admin account (theirs or anyone else's).
    if (!$isNationalAdmin) {
        if ($target['city_id'] === null || (int)$target['city_id'] !== $CURRENT_USER_CITY_ID) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only manage users in your own city.']);
            exit;
        }
        if (!canManageUser($CURRENT_USER_ROLE, (string)$target['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'City Leaders can only manage surveyors.']);
            exit;
        }
    }

    // -- Reset password (Admin: anyone else; City Leader: surveyors of their own city) --
    if (($body['action'] ?? '') === 'reset_password') {
        require_once __DIR__ . '/../../helpers/StaffPasswordReset.php';
        $resetError = staffPasswordResetError(
            $CURRENT_USER_ROLE,
            $CURRENT_USER_ID,
            $CURRENT_USER_CITY_ID,
            (int)$targetId,
            (string)$target['role'],
            $target['city_id'] !== null ? (int)$target['city_id'] : null,
            (int)$target['is_active'] === 1
        );
        if ($resetError !== null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $resetError]);
            exit;
        }

        $tempPassword = tempPasswordGenerate();
        staffPasswordResetApply($pdo, (int)$targetId, $tempPassword);
        // The password itself is never logged.
        error_log('Password reset for user ' . $targetId . ' by user ' . $CURRENT_USER_ID . ' (' . $CURRENT_USER_ROLE . ').');
        try {
            ActivityLogger::log($pdo, ActivityLogger::USER_PASSWORD_RESET, $CURRENT_USER_ID, ['user_id' => $targetId]);
        } catch (Throwable $e) {
            error_log('Activity log for password reset failed: ' . $e->getMessage());
        }

        header('Cache-Control: no-store');
        echo json_encode(['success' => true, 'temp_password' => $tempPassword]);
        exit;
    }

    // ── City change (admin only) ───────────────────────
    if (array_key_exists('city_id', $body)) {
        if (!$isNationalAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only an Admin can move a user to another city.']);
            exit;
        }
        [$newCityId, $cityError] = validateUserCityChange(listCities($pdo), (string)$target['role'], $body['city_id']);
        if ($cityError !== null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $cityError]);
            exit;
        }
        $oldCityId = $target['city_id'] !== null ? (int)$target['city_id'] : null;
        if ($oldCityId !== $newCityId) {
            // A surveyor leaving a city: what they have not started goes back to unassigned; if
            // they have started segments they must finish them first, so the move is refused for now.
            $movedPlan = ['release' => [], 'keep' => []];
            if ((string)$target['role'] === 'surveyor') {
                $remover   = new SurveyorRemovalRepository($pdo);
                $movedPlan = $remover->plan($targetId);
                if ($movedPlan['keep']) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'This surveyor has ' . count($movedPlan['keep'])
                        . ' segment(s) in progress in their current city. Move them after they have submitted.']);
                    exit;
                }
                $remover->releaseUnstarted($movedPlan);
                $remover->cancel($targetId);
            }
            $pdo->prepare('UPDATE users SET city_id = ? WHERE id = ?')->execute([$newCityId, $targetId]);
            if ((string)$target['role'] === 'surveyor') {
                $pdo->prepare('UPDATE users SET team_leader_id = NULL WHERE id = ?')->execute([$targetId]);
                $cn = $pdo->prepare('SELECT name FROM cities WHERE id = ?');
                $cn->execute([$newCityId]);
                $un = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $un->execute([$targetId]);
                workNotifyRemoval($pdo, ['id' => $targetId, 'name' => (string)($un->fetchColumn() ?: 'The surveyor')],
                    $movedPlan, 'now', REMOVAL_ACTION_TEAM, (string)($cn->fetchColumn() ?: 'another city'));
            }
            ActivityLogger::log($pdo, ActivityLogger::USER_CITY_CHANGED, $CURRENT_USER_ID, [
                'user_id'      => $targetId,
                'from_city_id' => $oldCityId,
                'to_city_id'   => $newCityId,
            ]);
        }
        echo json_encode(['success' => true, 'city_id' => $newCityId]);
        exit;
    }

    // ── Role change (promote/demote) ───────────────────────────
    if (array_key_exists('role', $body)) {
        $newRole = $body['role'];

        // Only the Admin changes roles (so only the
        // Admin creates City Leaders), and a City Leader needs a city.
        $adminCount    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $currentCityId = $target['city_id'] !== null ? (int)$target['city_id'] : null;
        $newCityId     = $currentCityId;
        $cityAdded     = false;

        // A person with no city yet is put in the city they chose at sign-up when
        // they are made City Leader. The city is added if it is new to the app; if
        // the change is refused below, that addition is undone.
        $pdo->beginTransaction();
        try {
            if ($newRole === 'city_leader' && $CURRENT_USER_ROLE === 'admin') {
                [$newCityId, $cityAdded] = cityForPromotion($pdo, $currentCityId, $target['home_city'] ?? null);
            }
            if ($newCityId !== null && $newCityId !== $currentCityId) {
                try {
                    cityAdoptSignups($pdo, $newCityId);   // the city's other sign-ups join it too
                } catch (Throwable $e) {
                    error_log('city sign-up placement failed: ' . $e->getMessage());
                }
            }

            $roleError = validateRoleChange(
                $CURRENT_USER_ROLE,
                (string)$target['role'],
                $newCityId,
                $newRole,
                $targetId === $CURRENT_USER_ID,
                $adminCount
            );
            if ($roleError !== null) {
                $pdo->rollBack();
                http_response_code($CURRENT_USER_ROLE === 'admin' ? 400 : 403);
                echo json_encode(['success' => false, 'error' => $roleError]);
                exit;
            }

            if ($newCityId !== $currentCityId) {
                $pdo->prepare("UPDATE users SET role = ?, city_id = ? WHERE id = ?")->execute([$newRole, $newCityId, $targetId]);
            } else {
                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);
            }
            // Someone who stops being a City Leader no longer holds projects: they go back to the Admin to reassign.
            if ((string)$target['role'] === 'city_leader' && $newRole !== 'city_leader') {
                $pdo->prepare("UPDATE city_audits SET leader_user_id = NULL WHERE leader_user_id = ?")->execute([$targetId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ((string)$target['role'] !== $newRole) {
            ActivityLogger::log($pdo, ActivityLogger::USER_ROLE_CHANGED, $CURRENT_USER_ID, [
                'user_id' => $targetId, 'from_role' => (string)$target['role'], 'to_role' => $newRole, 'city_id' => $newCityId,
            ]);
        }

        $cityName = null;
        if ($newCityId !== null) {
            $nameStmt = $pdo->prepare('SELECT name FROM cities WHERE id = ?');
            $nameStmt->execute([$newCityId]);
            $cityName = $nameStmt->fetchColumn() ?: null;
        }
        if ($newCityId !== $currentCityId) {
            try {
                ActivityLogger::log($pdo, ActivityLogger::USER_CITY_CHANGED, $CURRENT_USER_ID, [
                    'user_id'      => $targetId,
                    'from_city_id' => $currentCityId,
                    'to_city_id'   => $newCityId,
                    'source'       => 'signup_city',
                ]);
            } catch (Throwable $e) {
                error_log('Activity log for city assignment failed: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success'    => true,
            'city_id'    => $newCityId,
            'city_name'  => $cityName,
            'city_added' => $cityAdded,
        ]);
        exit;
    }

    // ── Active / inactive toggle ────────────────────────────────
    if (array_key_exists('is_active', $body)) {
        $active = $body['is_active'];
        if (!is_bool($active)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request.']);
            exit;
        }

        if (!$active && $targetId === $CURRENT_USER_ID) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot deactivate your own account.']);
            exit;
        }

        if (!$active && $target['role'] === 'admin') {
            $activeAdminCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
            )->fetchColumn();
            if ($activeAdminCount <= 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot deactivate the last remaining active admin.']);
                exit;
            }
        }

        // Reactivating (or cancelling a waiting removal) forgets any removal that was waiting.
        if ($active) {
            $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$targetId]);
            (new SurveyorRemovalRepository($pdo))->cancel($targetId);
            ActivityLogger::log($pdo, ActivityLogger::USER_ACTIVATED, $CURRENT_USER_ID, ['user_id' => $targetId]);
            echo json_encode(['success' => true, 'mode' => 'active']);
            exit;
        }

        // Deactivating a surveyor who still has assigned segments: what they have not started
        // goes back to unassigned now; what they have started they finish first, then they are
        // deactivated automatically (helpers/SurveyorRemoval.php).
        if ((string)$target['role'] === 'surveyor' && (int)$target['is_active'] === 1) {
            $remover = new SurveyorRemovalRepository($pdo);
            $nm = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
            $nm->execute([$targetId]);
            $who = $nm->fetch(PDO::FETCH_ASSOC) ?: ['id' => $targetId, 'name' => 'The surveyor'];
            if ($remover->isPending($targetId)) {
                echo json_encode(['success' => true, 'mode' => 'wait', 'pending' => true,
                    'message' => $who['name'] . ' is already waiting to be deactivated. It happens when they submit the segments in progress.']);
                exit;
            }
            $plan = $remover->plan($targetId);
            $mode = surveyorRemovalOutcome($plan);
            try {
                $pdo->beginTransaction();
                if ($mode === 'wait') {
                    $remover->schedule($targetId, REMOVAL_ACTION_DEACTIVATE, (int)$CURRENT_USER_ID);
                }
                $released = $remover->releaseUnstarted($plan);
                if ($mode === 'now') {
                    $remover->finalise($targetId, REMOVAL_ACTION_DEACTIVATE, null);
                }
                $pdo->commit();
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                http_response_code(503);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            workNotifyRemoval($pdo, $who, $plan, $mode, REMOVAL_ACTION_DEACTIVATE);
            ActivityLogger::log($pdo, ActivityLogger::USER_DEACTIVATED, $CURRENT_USER_ID, [
                'user_id' => $targetId, 'mode' => $mode, 'released' => $released, 'in_progress' => count($plan['keep']),
            ]);
            echo json_encode(['success' => true, 'mode' => $mode, 'released' => $released, 'in_progress' => count($plan['keep']),
                'message' => surveyorRemovalMessage((string)$who['name'], REMOVAL_ACTION_DEACTIVATE, $mode, $released, count($plan['keep']))]);
            exit;
        }

        $update = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $update->execute([0, $targetId]);
        ActivityLogger::log($pdo, ActivityLogger::USER_DEACTIVATED, $CURRENT_USER_ID, ['user_id' => $targetId, 'mode' => 'now']);

        echo json_encode(['success' => true, 'mode' => 'now']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
