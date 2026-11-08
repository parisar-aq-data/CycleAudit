<?php
declare(strict_types=1);

// ---------------------------------------------------------------
//  helpers/PrimaryAdmin.php
//  The original Admin (the first Admin account, the one with the lowest id)
//  keeps control. Admins added later have full powers over everyone else, but
//  they cannot deactivate, demote, move or reset the password of the original
//  Admin. The original Admin can still manage them.
//
//  If the original Admin is ever locked out, use scripts/make_admin_reset_sql.php
//  (run by whoever has access to the database).
// ---------------------------------------------------------------

/** Id of the original Admin, or null when there is no admin account. */
function primaryAdminId(PDO $pdo): ?int
{
    $v = $pdo->query("SELECT MIN(id) FROM users WHERE role = 'admin'")->fetchColumn();
    return ($v === false || $v === null) ? null : (int)$v;
}

/**
 * May this actor act on this target? Returns an error message, or null when
 * allowed. Only the original Admin may act on the original Admin's account.
 */
function primaryAdminProtectionError(int $actorId, ?int $primaryId, int $targetId): ?string
{
    if ($primaryId !== null && $targetId === $primaryId && $actorId !== $primaryId) {
        return 'This is the original Admin account. Only the original Admin can change it.';
    }
    return null;
}
