<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/AuditReviewRepository.php
//  City Leader review loop: approve or send back submitted segments,
//  close the audit, send it to the Admin. Also the Admin's decision:
//  approve the audit or return it to the City Leader with a note.
//  SQL is kept portable (MySQL in production, SQLite in tests).
//  User-facing problems are thrown as DomainException (safe to show).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/AuditReview.php';
require_once __DIR__ . '/../helpers/TeamRoster.php';

class AuditReviewRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{total:int,unassigned:int,assigned:int,submitted:int,needs_revisit:int,approved:int} */
    public function counts(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sa.status AS assignment_status
               FROM roads r
               JOIN segments s ON s.road_id = r.id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
              WHERE r.audit_id = ?'
        );
        $stmt->execute([$auditId]);
        return auditReviewCounts(array_map(
            static fn($v) => $v === false || $v === null ? null : (string)$v,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ));
    }

    /**
     * Segments waiting for review (submitted), oldest first, each with the
     * latest audit row so the reviewer can see what was recorded.
     *
     * @return list<array<string,mixed>>
     */
    public function submissions(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.id AS assignment_id, sa.segment_id, sa.surveyor_id, sa.submitted_at,
                    u.name AS surveyor_name,
                    s.segment_number, s.length,
                    r.id AS road_id, r.name AS road_name,
                    (SELECT MAX(x.id) FROM segment_audits x WHERE x.segment_id = s.id) AS latest_audit_id
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE sa.audit_id = ? AND sa.status = 'submitted'
              ORDER BY sa.submitted_at ASC, sa.id ASC"
        );
        $stmt->execute([$auditId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $detail = $this->pdo->prepare(
            'SELECT cycle_track_missing, missing_length, cyclist_use, surface_material, segment_width,
                    shade, buffer_zone, signage_count, comments
               FROM segment_audits WHERE id = ?'
        );
        foreach ($rows as &$row) {
            $row['data'] = [];
            if ($row['latest_audit_id'] !== null) {
                $detail->execute([(int)$row['latest_audit_id']]);
                $row['data'] = $detail->fetch(PDO::FETCH_ASSOC) ?: [];
            }
        }
        unset($row);
        return $rows;
    }

    /** Segments the City Leader sent back that have not been resubmitted yet. @return list<array<string,mixed>> */
    public function sentBack(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.segment_id, sa.review_note, sa.reviewed_at,
                    u.name AS surveyor_name, s.segment_number, r.name AS road_name
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE sa.audit_id = ? AND sa.status = 'needs_revisit'
              ORDER BY r.name ASC, s.segment_number ASC"
        );
        $stmt->execute([$auditId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Active surveyors the leader may pick for "assign to a different surveyor": their own team when
     *  the city has several City Leaders, the whole city otherwise. @return list<array{id:int,name:string}> */
    public function citySurveyors(int $cityId, ?int $leaderId = null): array
    {
        [$scope, $scopeArgs] = teamScope($this->pdo, $cityId, $leaderId);
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM users WHERE role = 'surveyor' AND is_active = 1 AND city_id = ?" . $scope . " ORDER BY name ASC"
        );
        $stmt->execute(array_merge([$cityId], $scopeArgs));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireOpen(array $audit): void
    {
        if (!in_array($audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true)) {
            throw new DomainException('This audit is not open for review.');
        }
    }

    /** The assignment row of a submitted segment in this audit, or an error. */
    private function submittedAssignment(array $audit, int $segmentId): array
    {
        $q = $this->pdo->prepare(
            'SELECT sa.id, sa.surveyor_id, sa.status, s.road_id
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
              WHERE sa.audit_id = ? AND sa.segment_id = ?'
        );
        $q->execute([(int)$audit['id'], $segmentId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new DomainException('That segment is not part of this audit.');
        }
        if ($row['status'] !== 'submitted') {
            throw new DomainException('That segment is not waiting for review.');
        }
        return $row;
    }

    /** Review has begun: move an active audit to in_review. */
    private function markInReview(int $auditId): void
    {
        $this->pdo->prepare("UPDATE city_audits SET status = 'in_review' WHERE id = ? AND status = 'active'")
            ->execute([$auditId]);
    }

    public function approve(array $audit, int $segmentId, int $reviewerId): void
    {
        $this->requireOpen($audit);
        $a = $this->submittedAssignment($audit, $segmentId);

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare(
                "UPDATE segment_assignments
                    SET status = 'approved', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?, review_note = NULL
                  WHERE id = ? AND status = 'submitted'"
            );
            $upd->execute([$reviewerId, (int)$a['id']]);
            if ($upd->rowCount() === 0) {
                throw new DomainException('That segment was already reviewed. Reload the page.');
            }
            $this->markInReview((int)$audit['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Send a submitted segment back for re-audit. The segment becomes pending
     * again so the form opens; the surveyor sees "Needs revisit" with the note.
     * $newSurveyorId (optional) hands the segment to a different surveyor.
     */
    public function sendBack(array $audit, int $segmentId, int $reviewerId, string $note, ?int $newSurveyorId = null): void
    {
        $this->requireOpen($audit);
        $clean = auditReviewCleanNote($note);
        if ($clean['error'] !== null) {
            throw new DomainException($clean['error']);
        }
        $a = $this->submittedAssignment($audit, $segmentId);

        $surveyorId = (int)$a['surveyor_id'];
        if ($newSurveyorId === null || $newSurveyorId === $surveyorId) {
            $own = $this->pdo->prepare('SELECT is_active FROM users WHERE id = ?');
            $own->execute([$surveyorId]);
            if ((int)$own->fetchColumn() !== 1) {
                throw new DomainException('That surveyor has left the team. Choose another active surveyor to re-audit this segment.');
            }
        }
        if ($newSurveyorId !== null && $newSurveyorId !== $surveyorId) {
            require_once __DIR__ . '/SurveyorRemovalRepository.php';
            if ((new SurveyorRemovalRepository($this->pdo))->isPending($newSurveyorId)) {
                throw new DomainException('That surveyor is being removed. Choose another surveyor.');
            }
            $u = $this->pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE id = ? AND role = 'surveyor' AND is_active = 1 AND city_id = ?"
            );
            $u->execute([$newSurveyorId, (int)$audit['city_id']]);
            if ((int)$u->fetchColumn() === 0) {
                throw new DomainException('Choose an active surveyor of this city.');
            }
            [$scope, $scopeArgs] = teamScope(
                $this->pdo, (int)$audit['city_id'],
                isset($audit['leader_user_id']) ? (int)$audit['leader_user_id'] : null
            );
            if ($scope !== '') {
                $t = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?' . $scope);
                $t->execute(array_merge([$newSurveyorId], $scopeArgs));
                if ((int)$t->fetchColumn() === 0) {
                    throw new DomainException('That surveyor is not in your team. Add them on the My team page first.');
                }
            }
            $surveyorId = $newSurveyorId;
        }

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare(
                "UPDATE segment_assignments
                    SET status = 'needs_revisit', surveyor_id = ?, reviewed_at = CURRENT_TIMESTAMP,
                        reviewed_by = ?, review_note = ?
                  WHERE id = ? AND status = 'submitted'"
            );
            $upd->execute([$surveyorId, $reviewerId, $clean['note'], (int)$a['id']]);
            if ($upd->rowCount() === 0) {
                throw new DomainException('That segment was already reviewed. Reload the page.');
            }
            // The audit data is kept; only the segment is opened up again.
            $this->pdo->prepare("UPDATE segments SET status = 'pending', completed_at = NULL WHERE id = ?")
                ->execute([$segmentId]);
            $this->reopenLatestSession((int)$a['road_id'], $surveyorId);
            $this->markInReview((int)$audit['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Re-open the surveyor's latest completed session on the road so the form can be used again. */
    private function reopenLatestSession(int $roadId, int $userId): void
    {
        $q = $this->pdo->prepare(
            "SELECT id FROM audit_sessions WHERE road_id = ? AND user_id = ? AND status = 'completed'
              ORDER BY id DESC LIMIT 1"
        );
        $q->execute([$roadId, $userId]);
        $id = $q->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare("UPDATE audit_sessions SET status = 'active', completed_at = NULL WHERE id = ?")
                ->execute([(int)$id]);
        }
    }

    /** Close the audit: every segment approved. Status becomes finalised. */
    public function close(array $audit): void
    {
        $counts = $this->counts((int)$audit['id']);
        $why    = auditReviewCloseBlockReason((string)$audit['status'], $counts);
        if ($why !== null) {
            throw new DomainException($why);
        }
        $upd = $this->pdo->prepare(
            "UPDATE city_audits SET status = 'finalised' WHERE id = ? AND status IN ('active', 'in_review')"
        );
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already closed.');
        }
    }

    /** Send a closed audit and its report to the Admin. */
    public function sendToAdmin(array $audit): void
    {
        if ($audit['status'] !== 'finalised') {
            throw new DomainException('Close the audit before sending it to the Admin.');
        }
        $upd = $this->pdo->prepare("UPDATE city_audits SET status = 'awaiting_approval' WHERE id = ? AND status = 'finalised'");
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already sent.');
        }
    }

    // ── Admin decision (admin) ─────────────────────────

    /**
     * The Admin's last note on an audit and when it was decided (null note when none).
     *
     * @return array{admin_note:?string,admin_decided_at:?string}
     */
    public function adminDecision(int $auditId): array
    {
        try {
            $q = $this->pdo->prepare('SELECT admin_note, admin_decided_at FROM city_audits WHERE id = ?');
            $q->execute([$auditId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $row = false;   // migration 014 not run yet: pages keep working, just without notes
        }
        return [
            'admin_note'       => ($row !== false && $row['admin_note'] !== null && $row['admin_note'] !== '') ? (string)$row['admin_note'] : null,
            'admin_decided_at' => $row !== false ? ($row['admin_decided_at'] ?? null) : null,
        ];
    }

    /**
     * Admin notes for several audits at once: audit_id => note (audits without a note are left out).
     *
     * @return array<int,string>
     */
    public function adminNotesForCity(int $cityId): array
    {
        $out = [];
        try {
            $q = $this->pdo->prepare("SELECT id, admin_note FROM city_audits WHERE city_id = ? AND admin_note IS NOT NULL AND admin_note <> ''");
            $q->execute([$cityId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $out;   // migration 014 not run yet
        }
        foreach ($rows as $r) {
            $out[(int)$r['id']] = (string)$r['admin_note'];
        }
        return $out;
    }

    /**
     * Audits waiting for the Admin, oldest first, with city name and segment counts.
     *
     * @return list<array<string,mixed>>
     */
    public function awaitingApproval(): array
    {
        $rows = $this->pdo->query(
            "SELECT a.id, a.name, a.audit_year, a.city_id, c.name AS city_name, a.updated_at,
                    COUNT(DISTINCT r.id) AS road_count,
                    COUNT(s.id)          AS segment_count
               FROM city_audits a
               JOIN cities c ON c.id = a.city_id
               LEFT JOIN roads r    ON r.audit_id = a.id
               LEFT JOIN segments s ON s.road_id  = r.id
              WHERE a.status = 'awaiting_approval'
              GROUP BY a.id, a.name, a.audit_year, a.city_id, c.name, a.updated_at
              ORDER BY a.updated_at ASC, a.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['road_count']    = (int)$r['road_count'];
            $r['segment_count'] = (int)$r['segment_count'];
        }
        unset($r);
        return $rows;
    }

    /** Admin approves an audit that is waiting for approval: it becomes published. */
    public function approveAudit(array $audit, int $adminId): void
    {
        if ($audit['status'] !== AUDIT_ADMIN_DECIDABLE_STATUS) {
            throw new DomainException('This audit is not waiting for approval.');
        }
        $upd = $this->pdo->prepare(
            "UPDATE city_audits
                SET status = 'published', admin_note = NULL, admin_decided_at = CURRENT_TIMESTAMP, admin_decided_by = ?
              WHERE id = ? AND status = 'awaiting_approval'"
        );
        $upd->execute([$adminId, (int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already decided. Reload the page.');
        }
    }

    /**
     * Admin hides a published audit from the public pages. The audit stays approved ("published"),
     * so it does not come back to the Admin as something to approve; Publish again undoes it.
     */
    public function unpublishAudit(array $audit, int $adminId): void
    {
        if ($audit['status'] !== 'published') {
            throw new DomainException('This audit is not published.');
        }
        $upd = $this->pdo->prepare(
            "UPDATE city_audits SET public_hidden_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status = 'published' AND public_hidden_at IS NULL"
        );
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit is already hidden from the home page. Reload the page.');
        }
    }

    /** Admin shows a hidden audit on the public pages again. */
    public function republishAudit(array $audit): void
    {
        if ($audit['status'] !== 'published') {
            throw new DomainException('This audit is not published.');
        }
        $upd = $this->pdo->prepare(
            "UPDATE city_audits SET public_hidden_at = NULL
              WHERE id = ? AND status = 'published' AND public_hidden_at IS NOT NULL"
        );
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit is already on the home page. Reload the page.');
        }
    }

    /**
     * Admin returns an audit to the City Leader with a required note saying what to change.
     * The audit goes back to in_review: the City Leader can send segments back to surveyors,
     * then close the audit and send it to the Admin again.
     */
    public function returnAudit(array $audit, int $adminId, string $note): void
    {
        if ($audit['status'] !== AUDIT_ADMIN_DECIDABLE_STATUS) {
            throw new DomainException('This audit is not waiting for approval.');
        }
        $clean = auditReviewCleanNote($note, 'the City Leader');
        if ($clean['error'] !== null) {
            throw new DomainException($clean['error']);
        }
        $upd = $this->pdo->prepare(
            "UPDATE city_audits
                SET status = 'in_review', admin_note = ?, admin_decided_at = CURRENT_TIMESTAMP, admin_decided_by = ?
              WHERE id = ? AND status = 'awaiting_approval'"
        );
        $upd->execute([$clean['note'], $adminId, (int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already decided. Reload the page.');
        }
    }
}
