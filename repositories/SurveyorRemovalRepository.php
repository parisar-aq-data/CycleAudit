<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/SurveyorRemovalRepository.php
//  Removing a surveyor who still has assigned segments
//  (rule: helpers/SurveyorRemoval.php, columns: migration 031).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/SurveyorRemoval.php';

class SurveyorRemovalRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Everything a surveyor is assigned, with what is needed to tell started from not started.
     *
     * @return list<array<string,mixed>>
     */
    public function openAssignments(int $userId): array
    {
        $q = $this->pdo->prepare(
            "SELECT sa.id AS assignment_id, sa.segment_id, sa.audit_id, sa.status AS assignment_status,
                    a.name AS audit_name, a.status AS audit_status, a.leader_user_id,
                    s.status AS segment_status, s.road_id,
                    (SELECT COUNT(*) FROM audit_sessions au
                      WHERE au.user_id = sa.surveyor_id AND au.road_id = s.road_id AND au.status = 'active') AS active_sessions
               FROM segment_assignments sa
               JOIN city_audits a ON a.id = sa.audit_id
               JOIN segments s    ON s.id = sa.segment_id
              WHERE sa.surveyor_id = ?"
        );
        $q->execute([$userId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{release:list<array<string,mixed>>, keep:list<array<string,mixed>>} */
    public function plan(int $userId): array
    {
        return surveyorRemovalClassify($this->openAssignments($userId));
    }

    /**
     * Give the not-started segments back (they become unassigned). Only rows that are still
     * 'assigned' are touched, so a segment that was submitted a moment ago is never taken.
     *
     * @param array{release:list<array<string,mixed>>, keep:list<array<string,mixed>>} $plan
     */
    public function releaseUnstarted(array $plan): int
    {
        $n   = 0;
        $del = $this->pdo->prepare("DELETE FROM segment_assignments WHERE id = ? AND status = 'assigned'");
        foreach ($plan['release'] as $r) {
            $del->execute([(int)$r['assignment_id']]);
            $n += $del->rowCount();
        }
        return $n;
    }

    /** Is a removal waiting for this surveyor to finish? (false when migration 031 is not applied) */
    public function pending(int $userId): ?array
    {
        try {
            $q = $this->pdo->prepare('SELECT removal_action, removal_requested_by FROM users WHERE id = ? AND removal_pending_at IS NOT NULL');
            $q->execute([$userId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
        return $row === false ? null : [
            'action'       => (string)$row['removal_action'],
            'requested_by' => $row['removal_requested_by'] !== null ? (int)$row['removal_requested_by'] : null,
        ];
    }

    public function isPending(int $userId): bool
    {
        return $this->pending($userId) !== null;
    }

    /** Remember that the removal waits for the started segments. */
    public function schedule(int $userId, string $action, int $requestedBy): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE users SET removal_pending_at = CURRENT_TIMESTAMP, removal_action = ?, removal_requested_by = ? WHERE id = ?'
            )->execute([$action, $requestedBy, $userId]);
        } catch (PDOException $e) {
            throw new DomainException('This removal needs database update 031 (migration 031_surveyor_removal.sql). Run it, then try again.');
        }
    }

    /** Forget a waiting removal (the surveyor was added back or reactivated). */
    public function cancel(int $userId): void
    {
        try {
            $this->pdo->prepare('UPDATE users SET removal_pending_at = NULL, removal_action = NULL, removal_requested_by = NULL WHERE id = ?')
                ->execute([$userId]);
        } catch (PDOException $e) {
            // migration 031 not applied: nothing to forget
        }
    }

    /** The removal itself, for a surveyor with nothing in progress. */
    public function finalise(int $userId, string $action, ?int $leaderId): bool
    {
        if ($action === REMOVAL_ACTION_DEACTIVATE) {
            $up = $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
            $up->execute([$userId]);
        } else {
            $up = $leaderId !== null
                ? $this->pdo->prepare('UPDATE users SET team_leader_id = NULL WHERE id = ? AND team_leader_id = ?')
                : $this->pdo->prepare('UPDATE users SET team_leader_id = NULL WHERE id = ?');
            $up->execute($leaderId !== null ? [$userId, $leaderId] : [$userId]);
        }
        $this->cancel($userId);
        return $up->rowCount() > 0;
    }

    /**
     * Called after a surveyor submits a segment or an audit goes away: if a removal is waiting
     * and nothing of theirs is still in progress, carry it out.
     *
     * @return array{action:string, requested_by:?int, name:string, leaders:list<int>}|null  null when nothing happened
     */
    public function completeIfDone(int $userId): ?array
    {
        $pending = $this->pending($userId);
        if ($pending === null) {
            return null;
        }
        $plan = $this->plan($userId);
        if ($plan['keep']) {
            return null;
        }
        $n = $this->pdo->prepare('SELECT name FROM users WHERE id = ?');
        $n->execute([$userId]);
        $name = (string)($n->fetchColumn() ?: 'The surveyor');

        // Whatever is still "assigned" here was never started, so it goes back too.
        $this->releaseUnstarted($plan);
        $this->finalise($userId, $pending['action'], $pending['requested_by']);

        return [
            'action'       => $pending['action'],
            'requested_by' => $pending['requested_by'],
            'name'         => $name,
            'leaders'      => $this->leaderIdsOf($userId),
        ];
    }

    /** City Leaders of the audits this surveyor has worked in. @return list<int> */
    public function leaderIdsOf(int $userId): array
    {
        $q = $this->pdo->prepare(
            'SELECT DISTINCT a.leader_user_id FROM segment_assignments sa JOIN city_audits a ON a.id = sa.audit_id
              WHERE sa.surveyor_id = ? AND a.leader_user_id IS NOT NULL'
        );
        $q->execute([$userId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Is this surveyor in this City Leader's team right now? */
    public function isOnTeamOf(int $userId, int $leaderId, int $cityId): bool
    {
        $q = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE id = ? AND role = 'surveyor' AND is_active = 1 AND city_id = ? AND team_leader_id = ?"
        );
        $q->execute([$userId, $cityId, $leaderId]);
        return (int)$q->fetchColumn() > 0;
    }
}
