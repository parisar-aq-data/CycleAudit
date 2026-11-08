<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/WorkNotify.php';

/**
 * Removing a surveyor who still has segments: not started goes back, started stays until
 * submitted, and the right people are told. Pure rules plus the repository on in-memory SQLite.
 */
class SurveyorRemovalTest extends TestCase
{
    private PDO $pdo;
    private SurveyorRemovalRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT NOT NULL DEFAULT \'surveyor\',
            is_active INTEGER NOT NULL DEFAULT 1, city_id INTEGER NULL, team_leader_id INTEGER NULL,
            removal_pending_at TEXT NULL, removal_action TEXT NULL, removal_requested_by INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, name TEXT, status TEXT, leader_user_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, audit_id INTEGER)');
        $this->pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER, status TEXT NOT NULL DEFAULT \'pending\')');
        $this->pdo->exec('CREATE TABLE audit_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, road_id INTEGER, status TEXT)');
        $this->pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER, segment_id INTEGER UNIQUE,
            surveyor_id INTEGER, status TEXT NOT NULL DEFAULT \'assigned\')');
        $this->pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL,
            title TEXT NOT NULL, body TEXT NOT NULL DEFAULT \'\', link TEXT NULL, is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec("INSERT INTO users (id, name, role, city_id, team_leader_id) VALUES
            (2,'Lata Leader','city_leader',1,NULL), (10,'Asha','surveyor',1,2), (11,'Ravi','surveyor',1,2)");
        $this->pdo->exec("INSERT INTO city_audits VALUES (1,'Pune 2026','active',2)");
        $this->pdo->exec('INSERT INTO roads VALUES (1,1),(2,1)');
        $this->repo = new SurveyorRemovalRepository($this->pdo);
    }

    /** Asha: seg 1 started (road 1, active session), seg 2 same road not started status but session counts, seg 3 on road 2 untouched. */
    private function ashaWithStartedAndUnstarted(): void
    {
        $this->pdo->exec("INSERT INTO segments VALUES (1,1,'completed'),(2,1,'pending'),(3,2,'pending'),(4,2,'pending')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, status) VALUES
            (1,1,10,'assigned'), (1,3,10,'assigned'), (1,4,10,'submitted')");
        $this->pdo->exec("INSERT INTO audit_sessions (user_id, road_id, status) VALUES (10,1,'active')");
    }

    private function notes(int $userId): array
    {
        $q = $this->pdo->prepare('SELECT type, title, body FROM notifications WHERE user_id = ? ORDER BY id');
        $q->execute([$userId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── the rule ────────────────────────────────────────────────

    public function test_started_work_stays_and_unstarted_work_goes_back(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $plan = $this->repo->plan(10);
        $this->assertSame([1], array_map(fn($r) => (int)$r['segment_id'], $plan['keep']));
        $this->assertSame([3], array_map(fn($r) => (int)$r['segment_id'], $plan['release']));   // submitted seg 4 is ignored
        $this->assertSame('wait', surveyorRemovalOutcome($plan));
    }

    public function test_nothing_started_means_removal_now(): void
    {
        $this->pdo->exec("INSERT INTO segments VALUES (3,2,'pending')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id) VALUES (1,3,10)");
        $plan = $this->repo->plan(10);
        $this->assertSame('now', surveyorRemovalOutcome($plan));
        $this->assertCount(1, $plan['release']);
    }

    public function test_a_segment_sent_back_for_a_revisit_counts_as_in_progress(): void
    {
        $this->pdo->exec("INSERT INTO segments VALUES (3,2,'pending')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, status) VALUES (1,3,10,'needs_revisit')");
        $this->assertSame('wait', surveyorRemovalOutcome($this->repo->plan(10)));
    }

    public function test_finished_published_or_deleted_audits_never_hold_a_removal(): void
    {
        $this->pdo->exec("INSERT INTO city_audits VALUES (2,'Old','published',2),(3,'Gone','voided',2)");
        $this->pdo->exec('INSERT INTO roads VALUES (3,2),(4,3)');
        $this->pdo->exec("INSERT INTO segments VALUES (5,3,'completed'),(6,4,'completed')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, status) VALUES (2,5,10,'assigned'),(3,6,10,'assigned')");
        $plan = $this->repo->plan(10);
        $this->assertSame([], $plan['keep']);
        $this->assertSame([], $plan['release']);
    }

    // ── doing it ────────────────────────────────────────────────

    public function test_release_only_takes_rows_that_are_still_assigned(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $plan = $this->repo->plan(10);
        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 3");   // submitted a moment ago
        $this->assertSame(0, $this->repo->releaseUnstarted($plan));
        $this->assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_assignments')->fetchColumn());
    }

    public function test_waiting_removal_finishes_when_the_last_started_segment_is_submitted(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $plan = $this->repo->plan(10);
        $this->repo->schedule(10, REMOVAL_ACTION_TEAM, 2);
        $this->repo->releaseUnstarted($plan);
        $this->assertTrue($this->repo->isPending(10));
        $this->assertNull($this->repo->completeIfDone(10), 'segment 1 is still in progress');
        $this->assertSame(2, (int)$this->pdo->query('SELECT team_leader_id FROM users WHERE id = 10')->fetchColumn());

        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 1");
        $done = $this->repo->completeIfDone(10);
        $this->assertNotNull($done);
        $this->assertSame('Asha', $done['name']);
        $this->assertNull($this->pdo->query('SELECT team_leader_id FROM users WHERE id = 10')->fetchColumn());
        $this->assertFalse($this->repo->isPending(10));
        $this->assertSame(1, (int)$this->pdo->query('SELECT is_active FROM users WHERE id = 10')->fetchColumn(), 'team removal keeps the account');
    }

    public function test_deactivation_waits_then_deactivates(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $this->repo->schedule(10, REMOVAL_ACTION_DEACTIVATE, 2);
        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 1");
        $this->pdo->exec("UPDATE audit_sessions SET status = 'completed'");
        $this->pdo->exec("DELETE FROM segment_assignments WHERE segment_id = 3");
        $this->assertNotNull($this->repo->completeIfDone(10));
        $this->assertSame(0, (int)$this->pdo->query('SELECT is_active FROM users WHERE id = 10')->fetchColumn());
    }

    public function test_cancel_forgets_a_waiting_removal(): void
    {
        $this->repo->schedule(10, REMOVAL_ACTION_TEAM, 2);
        $this->repo->cancel(10);
        $this->assertFalse($this->repo->isPending(10));
        $this->assertNull($this->repo->completeIfDone(10));
    }

    public function test_without_migration_031_nothing_is_pending_and_scheduling_says_why(): void
    {
        $this->pdo->exec('ALTER TABLE users DROP COLUMN removal_pending_at');
        $this->assertFalse($this->repo->isPending(10));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/031/');
        $this->repo->schedule(10, REMOVAL_ACTION_TEAM, 2);
    }

    public function test_team_membership_check(): void
    {
        $this->assertTrue($this->repo->isOnTeamOf(10, 2, 1));
        $this->assertFalse($this->repo->isOnTeamOf(10, 99, 1));
        $this->assertFalse($this->repo->isOnTeamOf(10, 2, 7));
    }

    // ── who is told what ────────────────────────────────────────

    public function test_removal_with_work_in_progress_tells_surveyor_and_leader(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $plan = $this->repo->plan(10);
        $this->repo->releaseUnstarted($plan);
        workNotifyRemoval($this->pdo, ['id' => 10, 'name' => 'Asha'], $plan, 'wait', REMOVAL_ACTION_TEAM);

        $s = $this->notes(10);
        $this->assertCount(1, $s);
        $this->assertStringContainsString('1 segment you already started', $s[0]['body']);
        $this->assertStringContainsString('1 segment you had not started was taken off', $s[0]['body']);

        $l = $this->notes(2);
        $this->assertCount(2, $l);
        $this->assertSame('Segments are unassigned again', $l[0]['title']);
        $this->assertStringContainsString('Assign it to another surveyor', $l[0]['body']);
        $this->assertSame('Removal waiting for work in progress', $l[1]['title']);
    }

    public function test_completed_removal_tells_surveyor_requester_and_leaders_once(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $this->repo->schedule(10, REMOVAL_ACTION_TEAM, 2);
        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 1");
        $this->pdo->exec("DELETE FROM segment_assignments WHERE segment_id = 3");
        workFinishRemovalIfDone($this->pdo, 10);
        $this->assertSame('Removal complete', $this->notes(10)[0]['title']);
        $this->assertCount(1, $this->notes(2), 'leader and requester are the same person: told once');
        $this->assertSame('Surveyor removed', $this->notes(2)[0]['title']);
    }

    public function test_submitting_tells_the_leader_and_finishes_the_removal(): void
    {
        $this->ashaWithStartedAndUnstarted();
        $this->repo->schedule(10, REMOVAL_ACTION_TEAM, 2);
        $this->pdo->exec("DELETE FROM segment_assignments WHERE segment_id = 3");
        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 1");
        $this->pdo->exec("UPDATE audit_sessions SET status = 'completed'");
        workAfterSubmit($this->pdo, 1, 10);
        $titles = array_column($this->notes(2), 'title');
        $this->assertContains('Segment submitted', $titles);
        $this->assertContains('Surveyor removed', $titles);
        $this->assertNull($this->pdo->query('SELECT team_leader_id FROM users WHERE id = 10')->fetchColumn());
    }

    public function test_review_notifications_go_to_the_right_surveyor(): void
    {
        $audit = ['id' => 1, 'name' => 'Pune 2026'];
        workNotifyReview($this->pdo, $audit, 10, 'approve', '', null);
        $this->assertSame('Segment approved', $this->notes(10)[0]['title']);

        workNotifyReview($this->pdo, $audit, 10, 'send_back', 'Photo is blurry', null);
        $this->assertStringContainsString('Photo is blurry', $this->notes(10)[1]['body']);

        workNotifyReview($this->pdo, $audit, 10, 'send_back', 'Redo it', 11);
        $this->assertSame('Assignment changed', $this->notes(10)[2]['title']);
        $this->assertStringContainsString('gave you a segment to re-audit', $this->notes(11)[0]['body']);
    }

    public function test_audit_milestones_reach_surveyors_admins_and_the_leader(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name, role, city_id) VALUES (1,'Root','admin',NULL)");
        $this->pdo->exec("INSERT INTO segments VALUES (1,1,'pending'),(2,1,'pending')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id) VALUES (1,1,10),(1,2,10)");
        $audit = ['id' => 1, 'name' => 'Pune 2026', 'city_name' => 'Pune', 'leader_user_id' => 2];

        workNotifyActivated($this->pdo, $audit);
        $this->assertStringContainsString('2 segments', $this->notes(10)[0]['body']);
        workNotifyClosed($this->pdo, $audit);
        $this->assertSame('Audit closed', $this->notes(10)[1]['title']);
        workNotifySentToAdmin($this->pdo, $audit);
        $this->assertSame('Audit waiting for approval', $this->notes(1)[0]['title']);
        workNotifyAdminDecision($this->pdo, $audit, 'return', 'Fix the intro');
        $this->assertStringContainsString('Fix the intro', $this->notes(2)[0]['body']);
        workNotifyAdminDecision($this->pdo, $audit, 'approve', '');
        $this->assertSame('Audit approved', $this->notes(2)[1]['title']);
    }

    public function test_message_for_the_person_who_asked(): void
    {
        $this->assertStringContainsString('2 segments in progress', surveyorRemovalMessage('Asha', REMOVAL_ACTION_TEAM, 'wait', 1, 2));
        $this->assertStringContainsString('1 segment they had not started is unassigned again', surveyorRemovalMessage('Asha', REMOVAL_ACTION_TEAM, 'wait', 1, 2));
        $this->assertSame('Asha was removed from your team.', surveyorRemovalMessage('Asha', REMOVAL_ACTION_TEAM, 'now', 0, 0));
    }
}
