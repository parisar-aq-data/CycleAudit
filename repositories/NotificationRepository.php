<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/NotificationRepository.php
//  In-app notifications (migration 017). Every method is scoped to
//  one user, so nobody can read or change another user's notifications.
// ═══════════════════════════════════════════════════════════════

class NotificationRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(int $userId, string $type, string $title, string $body, ?string $link = null): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $type, mb_substr($title, 0, 150), mb_substr($body, 0, 500), $link]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Tell each surveyor in $lost (surveyor id => segment count) that segments were taken
     * from them. Needs helpers/Notifications.php. One failing insert never stops the rest.
     *
     * @param array<int,int> $lost
     * @param string         $reason 'moved' | 'cleared' | 'removed'
     */
    public function tellUnassigned(array $lost, string $auditName, string $reason): void
    {
        foreach ($lost as $surveyorId => $count) {
            if ($count < 1) {
                continue;
            }
            try {
                $t = unassignmentNotificationText($auditName, (int)$count, $reason);
                $this->create((int)$surveyorId, NOTIFY_UNASSIGNED, $t['title'], $t['body'], 'dashboard.php');
            } catch (Throwable $e) {
                error_log('NotificationRepository::tellUnassigned failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Send one notification to several people. One failing insert never stops the rest,
     * and a failure here must never undo the action that caused it.
     *
     * @param list<int> $userIds
     */
    public function tellMany(array $userIds, string $type, string $title, string $body, ?string $link = null): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid < 1) {
                continue;
            }
            try {
                $this->create($uid, $type, $title, $body, $link);
            } catch (Throwable $e) {
                error_log('NotificationRepository::tellMany failed: ' . $e->getMessage());
            }
        }
    }

    /** @return list<int> every active Admin */
    public function activeAdminIds(): array
    {
        return array_map('intval', $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,int> surveyor id => how many segments they hold in this audit */
    public function surveyorsOfAudit(int $auditId): array
    {
        $q = $this->pdo->prepare('SELECT surveyor_id, COUNT(*) AS n FROM segment_assignments WHERE audit_id = ? GROUP BY surveyor_id');
        $q->execute([$auditId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['surveyor_id']] = (int)$r['n'];
        }
        return $out;
    }

    /** Newest unread notifications first.
     *  @return list<array{id:int,type:string,title:string,body:string,link:?string,created_at:string,is_read:bool}> */
    public function unread(int $userId, int $limit = 20): array
    {
        return $this->fetch($userId, $limit, true);
    }

    /** Newest notifications first, read ones included, so nothing disappears once it is read.
     *  @return list<array{id:int,type:string,title:string,body:string,link:?string,created_at:string,is_read:bool}> */
    public function recent(int $userId, int $limit = 30): array
    {
        return $this->fetch($userId, $limit, false);
    }

    /** @return list<array{id:int,type:string,title:string,body:string,link:?string,created_at:string,is_read:bool}> */
    private function fetch(int $userId, int $limit, bool $onlyUnread): array
    {
        $limit = max(1, min(50, $limit));
        $st = $this->pdo->prepare(
            'SELECT id, type, title, body, link, created_at, is_read FROM notifications
             WHERE user_id = ?' . ($onlyUnread ? ' AND is_read = 0' : '') . ' ORDER BY id DESC LIMIT ' . $limit
        );
        $st->execute([$userId]);
        return array_map(static fn(array $r): array => [
            'id'         => (int)$r['id'],
            'type'       => (string)$r['type'],
            'title'      => (string)$r['title'],
            'body'       => (string)$r['body'],
            'link'       => $r['link'] === null ? null : (string)$r['link'],
            'created_at' => (string)$r['created_at'],
            'is_read'    => (int)$r['is_read'] === 1,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Marks one of this user's notifications as read. */
    public function markRead(int $userId, int $id): bool
    {
        $st = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0');
        $st->execute([$id, $userId]);
        return $st->rowCount() > 0;
    }

    public function unreadCount(int $userId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    /** Marks every unread notification of this user as read; returns how many changed. */
    public function markAllRead(int $userId): int
    {
        $st = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return $st->rowCount();
    }
}
