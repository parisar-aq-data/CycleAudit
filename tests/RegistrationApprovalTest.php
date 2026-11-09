<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Registration.php';
require_once __DIR__ . '/../helpers/CityLeaderGate.php';

/** Sign-up approval (security review H1), on in-memory SQLite. */
class RegistrationApprovalTest extends TestCase
{
    private function db(bool $withColumn = true): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, phone TEXT, organisation TEXT,
            home_city TEXT, home_state TEXT, role TEXT NOT NULL DEFAULT \'surveyor\', city_id INTEGER NULL,
            created_at TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1'
            . ($withColumn ? ", approval_status TEXT NOT NULL DEFAULT 'approved'" : '') . ')');
        $pdo->exec("INSERT INTO cities VALUES (1, 'Pune'), (2, 'Nashik')");
        return $pdo;
    }

    /** Pune has 10 (pending) and 11 (pending); Nashik has 20 (pending); 30 is plain deactivated; 40 is a pending City Leader row. */
    private function seeded(): PDO
    {
        $pdo = $this->db();
        $pdo->exec("INSERT INTO users (id, name, role, city_id, created_at, is_active, approval_status) VALUES
            (10, 'Pune One',   'surveyor',    1, '2026-11-01 10:00:00', 0, 'pending'),
            (11, 'Pune Two',   'surveyor',    1, '2026-11-01 09:00:00', 0, 'pending'),
            (20, 'Nashik One', 'surveyor',    2, '2026-11-02 10:00:00', 0, 'pending'),
            (30, 'Deactivated','surveyor',    1, '2026-10-01 10:00:00', 0, 'approved'),
            (40, 'Odd One',    'city_leader', 1, '2026-11-03 10:00:00', 0, 'pending'),
            (50, 'Active Ann', 'surveyor',    1, '2026-10-05 10:00:00', 1, 'approved')");
        return $pdo;
    }

    private function ids(array $rows): array
    {
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    private function rowState(PDO $pdo, int $id): array
    {
        $r = $pdo->query("SELECT is_active, approval_status FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        return [(int)$r['is_active'], $r['approval_status']];
    }

    public function test_it_detects_whether_migration_032_has_run(): void
    {
        $this->assertFalse(usersHaveApprovalStatus($this->db(false)));
        $this->assertTrue(usersHaveApprovalStatus($this->db(true)));
    }

    public function test_pending_means_inactive_and_tagged_pending(): void
    {
        $this->assertTrue(isPendingApproval(['is_active' => 0, 'approval_status' => 'pending']));
        $this->assertFalse(isPendingApproval(['is_active' => 0, 'approval_status' => 'approved']), 'plain deactivated');
        $this->assertFalse(isPendingApproval(['is_active' => 0, 'approval_status' => 'rejected']), 'rejected');
        $this->assertFalse(isPendingApproval(['is_active' => 1, 'approval_status' => 'pending']), 'already switched on');
        $this->assertFalse(isPendingApproval(['is_active' => 0]), 'column missing = old behaviour');
    }

    public function test_an_admin_sees_every_waiting_surveyor_oldest_first(): void
    {
        $this->assertSame([11, 10, 20], $this->ids(signupRequestsOpenFor($this->seeded(), 'admin', null)));
    }

    public function test_a_city_leader_sees_only_their_own_city(): void
    {
        $pdo = $this->seeded();
        $this->assertSame([11, 10], $this->ids(signupRequestsOpenFor($pdo, 'city_leader', 1)));
        $this->assertSame([20],     $this->ids(signupRequestsOpenFor($pdo, 'city_leader', 2)));
        $this->assertSame([],       signupRequestsOpenFor($pdo, 'city_leader', null), 'a leader with no city sees nobody');
    }

    public function test_surveyors_and_unknown_roles_see_nothing_and_nothing_without_the_column(): void
    {
        $pdo = $this->seeded();
        $this->assertSame([], signupRequestsOpenFor($pdo, 'surveyor', 1));
        $this->assertSame([], signupRequestsOpenFor($pdo, '', null));
        $this->assertSame([], signupRequestsOpenFor($this->db(false), 'admin', null));
    }

    public function test_approving_switches_the_person_on(): void
    {
        $pdo = $this->seeded();
        $res = signupRequestDecide($pdo, 10, 'approve', 'city_leader', 1);
        $this->assertTrue($res['ok']);
        $this->assertSame([1, 'approved'], $this->rowState($pdo, 10));
        $this->assertSame([0, 'pending'], $this->rowState($pdo, 11), 'others untouched');
    }

    public function test_rejecting_keeps_the_person_out_and_closes_the_request(): void
    {
        $pdo = $this->seeded();
        $this->assertTrue(signupRequestDecide($pdo, 11, 'reject', 'admin', null)['ok']);
        $this->assertSame([0, 'rejected'], $this->rowState($pdo, 11));
        $this->assertSame([10], $this->ids(signupRequestsOpenFor($pdo, 'city_leader', 1)));
    }

    public function test_a_leader_cannot_decide_another_citys_request_and_it_looks_like_it_does_not_exist(): void
    {
        $pdo = $this->seeded();
        $res = signupRequestDecide($pdo, 20, 'approve', 'city_leader', 1);
        $this->assertFalse($res['ok']);
        $this->assertSame(404, $res['http']);
        $this->assertSame([0, 'pending'], $this->rowState($pdo, 20));
        $this->assertFalse(signupRequestDecide($pdo, 20, 'approve', 'city_leader', null)['ok'], 'leader with no city');
    }

    public function test_only_pending_surveyors_can_be_decided(): void
    {
        $pdo = $this->seeded();
        foreach ([30 => 'plain deactivated', 40 => 'not a surveyor', 50 => 'already active', 999 => 'unknown'] as $id => $why) {
            $res = signupRequestDecide($pdo, $id, 'approve', 'admin', null);
            $this->assertFalse($res['ok'], $why);
            $this->assertSame(404, $res['http'], $why);
        }
        $this->assertSame([0, 'approved'], $this->rowState($pdo, 30), 'deactivated user is NOT switched on');
        $this->assertSame([0, 'pending'],  $this->rowState($pdo, 40));
    }

    public function test_surveyors_and_bad_actions_get_nowhere(): void
    {
        $pdo = $this->seeded();
        $this->assertFalse(signupRequestDecide($pdo, 10, 'approve', 'surveyor', 1)['ok']);
        $this->assertFalse(signupRequestDecide($pdo, 10, 'delete', 'admin', null)['ok']);
        $this->assertSame([0, 'pending'], $this->rowState($pdo, 10));
    }

    public function test_a_second_decision_on_the_same_request_is_refused(): void
    {
        $pdo = $this->seeded();
        $this->assertTrue(signupRequestDecide($pdo, 10, 'approve', 'admin', null)['ok']);
        $second = signupRequestDecide($pdo, 10, 'reject', 'city_leader', 1);
        $this->assertFalse($second['ok']);
        $this->assertSame([1, 'approved'], $this->rowState($pdo, 10), 'the first decision stands');
    }

    public function test_decisions_do_nothing_without_the_column(): void
    {
        $pdo = $this->db(false);
        $pdo->exec("INSERT INTO users (id, role, city_id, is_active) VALUES (1, 'surveyor', 1, 0)");
        $this->assertFalse(signupRequestDecide($pdo, 1, 'approve', 'admin', null)['ok']);
        $this->assertSame('0', (string)$pdo->query('SELECT is_active FROM users WHERE id = 1')->fetchColumn());
    }

    public function test_reactivating_from_the_users_page_clears_pending_and_rejected_only(): void
    {
        $pdo = $this->seeded();
        $pdo->exec("UPDATE users SET approval_status = 'rejected' WHERE id = 11");
        markUserApproved($pdo, 10);
        markUserApproved($pdo, 11);
        markUserApproved($pdo, 20 + 0);
        $this->assertSame('approved', $this->rowState($pdo, 10)[1]);
        $this->assertSame('approved', $this->rowState($pdo, 11)[1]);
        markUserApproved($this->db(false), 1);   // no column: must not throw
        $this->assertTrue(true);
    }

    public function test_city_leaders_may_open_the_signup_api_and_nothing_next_to_it(): void
    {
        $this->assertTrue(cityLeaderMayAccess('/api/admin/signup_requests.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/signup_requests.php.bak'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/surveyors.php'), 'the Users list stays Admin-only');
    }
}
