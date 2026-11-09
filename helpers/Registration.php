<?php
declare(strict_types=1);

// ───────────────────────────────────────────────────────────────
//  helpers/Registration.php
//  Sign-up approval (security review H1).
//
//  A person who signs up themselves is created INACTIVE with
//  users.approval_status = 'pending' (migration 032). They cannot sign in until
//  the City Leader of their city, or an Admin, approves them (dashboard panel
//  "Sign-up requests", or the Users page for Admins). Rejected sign-ups stay
//  inactive with approval_status = 'rejected'.
// ───────────────────────────────────────────────────────────────

const APPROVAL_PENDING          = 'pending';
const APPROVAL_APPROVED         = 'approved';
const APPROVAL_REJECTED         = 'rejected';
const REGISTER_OK_MAX_PER_HOUR  = 10;   // new accounts per network per hour

/** True once migration 032 has run. Anything that needs the column asks first. */
function usersHaveApprovalStatus(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT approval_status FROM users WHERE 1 = 0') !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/** A users row that is inactive only because nobody has approved it yet. */
function isPendingApproval(array $user): bool
{
    return (int)($user['is_active'] ?? 1) === 0
        && (string)($user['approval_status'] ?? APPROVAL_APPROVED) === APPROVAL_PENDING;
}

/** Clear the pending/rejected tag when an Admin reactivates the person (no-op otherwise). */
function markUserApproved(PDO $pdo, int $userId): void
{
    if (!usersHaveApprovalStatus($pdo)) {
        return;
    }
    $pdo->prepare("UPDATE users SET approval_status = 'approved' WHERE id = ? AND approval_status IN ('pending', 'rejected')")
        ->execute([$userId]);
}

/**
 * Sign-ups waiting for this approver, oldest first.
 * Admin: everyone. City Leader: only people who named their own city. Anyone else: nobody.
 *
 * @return list<array<string,mixed>>
 */
function signupRequestsOpenFor(PDO $pdo, string $role, ?int $cityId): array
{
    if (!usersHaveApprovalStatus($pdo)) {
        return [];
    }
    $sql = "SELECT u.id, u.name, u.email, u.phone, u.organisation, u.home_city, u.home_state,
                   u.city_id, c.name AS city_name, u.created_at
              FROM users u
              LEFT JOIN cities c ON c.id = u.city_id
             WHERE u.approval_status = 'pending' AND u.is_active = 0 AND u.role = 'surveyor'";
    $params = [];
    if ($role === 'admin') {
        // everyone
    } elseif ($role === 'city_leader' && $cityId !== null) {
        $sql    .= ' AND u.city_id = ?';
        $params[] = $cityId;
    } else {
        return [];
    }
    $sql .= ' ORDER BY u.created_at ASC, u.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Approve or reject one sign-up. The decision is made here, from the signed-in role, never
 * from anything the page sends. A request that is not this approver's gets the same answer as
 * one that does not exist.
 *
 * @return array{ok:bool, http:int, error?:string, user_id?:int}
 */
function signupRequestDecide(PDO $pdo, int $id, string $action, string $role, ?int $cityId): array
{
    $notFound = ['ok' => false, 'http' => 404, 'error' => 'Request not found.'];
    if (!in_array($action, ['approve', 'reject'], true) || !usersHaveApprovalStatus($pdo)) {
        return $notFound;
    }

    $st = $pdo->prepare("SELECT id, role, city_id FROM users WHERE id = ? AND approval_status = 'pending' AND is_active = 0 LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['role'] !== 'surveyor') {
        return $notFound;
    }

    $mine = $role === 'admin'
        || ($role === 'city_leader' && $cityId !== null && (int)$row['city_id'] === $cityId);
    if (!$mine) {
        return $notFound;
    }

    $up = $pdo->prepare("UPDATE users SET is_active = ?, approval_status = ? WHERE id = ? AND approval_status = 'pending'");
    $up->execute([$action === 'approve' ? 1 : 0, $action === 'approve' ? APPROVAL_APPROVED : APPROVAL_REJECTED, $id]);
    if ($up->rowCount() !== 1) {
        return ['ok' => false, 'http' => 409, 'error' => 'Someone else has already decided this request.'];
    }
    return ['ok' => true, 'http' => 200, 'user_id' => $id];
}
