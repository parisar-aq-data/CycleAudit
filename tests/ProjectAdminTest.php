<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../helpers/CityAudit.php';
require_once __DIR__ . '/../helpers/Notifications.php';
require_once __DIR__ . '/../helpers/ProjectStatus.php';
require_once __DIR__ . '/../helpers/SurveyorWork.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/SurveyorWorkRepository.php';
require_once __DIR__ . '/../repositories/CityDashboardRepository.php';

/** One City Leader per city, and the Admin editing / deleting a project. In-memory SQLite shaped like the live schema. */
class ProjectAdminTest extends TestCase
{
    private PDO $pdo;
    private CityAuditRepository $repo;
    private array $clean = ['name' => 'Pune Audit', 'state' => 'Maharashtra', 'audit_year' => 2026, 'audit_date' => '2026-10-04', 'programme_info' => null];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT NOT NULL DEFAULT \'surveyor\',
            is_active INTEGER NOT NULL DEFAULT 1, city_id INTEGER NULL, email TEXT NOT NULL DEFAULT \'\')');
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, city_id INTEGER NOT NULL,
            state TEXT NOT NULL, name TEXT NOT NULL, audit_year INTEGER NOT NULL, audit_date TEXT NULL, programme_info TEXT NULL,
            status TEXT NOT NULL DEFAULT \'draft\', created_by INTEGER NOT NULL, leader_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (city_id, audit_year, name))');
        $this->pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, audit_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY AUTOINCREMENT, road_id INTEGER NOT NULL, segment_number INTEGER NOT NULL,
            length REAL NOT NULL DEFAULT 250, status TEXT NOT NULL DEFAULT \'pending\')');
        $this->pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER NOT NULL,
            segment_id INTEGER NOT NULL UNIQUE, surveyor_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'assigned\', assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at TEXT NULL, reviewed_at TEXT NULL, reviewed_by INTEGER NULL, review_note TEXT NULL)');
        $this->pdo->exec('CREATE TABLE audit_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, road_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT \'active\')');
        $this->pdo->exec("INSERT INTO cities VALUES (1,'Pune'),(2,'Mumbai')");
        $this->pdo->exec("INSERT INTO users (id, name, role, is_active, city_id) VALUES
            (1,'Root','admin',1,NULL),(2,'Nick','city_leader',1,1),(10,'Asha','surveyor',1,1),(11,'Ravi','surveyor',1,1),(20,'Mona','city_leader',1,2)");
        $this->repo = new CityAuditRepository($this->pdo);
    }

    /** Pune audit with 2 roads / 3 segments; Asha holds two, Ravi one; one segment already started. */
    private function projectWithWork(): array
    {
        $a = $this->repo->find($this->repo->createAudit(1, 1, $this->clean));
        $this->pdo->exec("INSERT INTO roads (id, name, audit_id) VALUES (1,'F.C. Road',{$a['id']}),(2,'PMC Road',{$a['id']})");
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number, status) VALUES (1,1,1,'completed'),(2,1,2,'pending'),(3,2,1,'pending')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, assigned_by) VALUES
            ({$a['id']},1,10,2),({$a['id']},2,10,2),({$a['id']},3,11,2)");
        $this->pdo->exec("UPDATE city_audits SET status = 'active' WHERE id = {$a['id']}");
        return $this->repo->find((int)$a['id']);
    }

    // ── a city can have several City Leaders; each project has one ──

    public function test_only_a_city_leader_of_the_projects_city_can_be_assigned(): void
    {
        $leader = ['role' => 'city_leader', 'is_active' => 1, 'city_id' => 1];
        $this->assertNull(projectLeaderError($leader, 1));
        $this->assertStringContainsString('Only a City Leader', (string)projectLeaderError(['role' => 'surveyor'] + $leader, 1));
        $this->assertStringContainsString('another city', (string)projectLeaderError($leader, 2));
        $this->assertStringContainsString('inactive', (string)projectLeaderError(['is_active' => 0] + $leader, 1));
        $this->assertSame('That person was not found.', projectLeaderError(null, 1));
    }

    public function test_a_city_leader_opens_only_projects_assigned_to_them(): void
    {
        $audit = ['city_id' => 1, 'leader_user_id' => 5];
        $this->assertTrue(projectOpenableBy($audit, 'city_leader', 5, 1));
        $this->assertFalse(projectOpenableBy($audit, 'city_leader', 6, 1), 'another leader in the same city');
        $this->assertFalse(projectOpenableBy(['city_id' => 1, 'leader_user_id' => null] + $audit, 'city_leader', 5, 1), 'unassigned');
        $this->assertFalse(projectOpenableBy($audit, 'city_leader', 5, 2), 'other city');
        $this->assertFalse(projectOpenableBy($audit, 'surveyor', 5, 1));
        $this->assertTrue(projectOpenableBy(['city_id' => 1, 'leader_user_id' => null], 'admin', 1, null));
    }

    public function test_leaders_of_a_city_lists_every_active_leader(): void
    {
        $this->assertSame([2], array_column(cityLeadersOf($this->pdo, 1), 'id'));
        $this->assertSame([20], array_column(cityLeadersOf($this->pdo, 2), 'id'));
    }

    public function test_a_project_can_be_assigned_and_filtered_by_leader(): void
    {
        $id = $this->repo->createAudit(1, 1, ['name' => 'P', 'state' => 'MH', 'audit_date' => '2026-01-01', 'audit_year' => 2026, 'programme_info' => null], 2);
        $this->assertSame(2, (int)$this->repo->find($id)['leader_user_id']);
        $this->assertCount(1, array_filter($this->repo->listForCity(1, 2), fn($r) => (int)$r['id'] === $id));
        $this->assertCount(0, array_filter($this->repo->listForCity(1, 99), fn($r) => (int)$r['id'] === $id));
        $this->repo->setLeader($id, null);
        $this->assertNull($this->repo->find($id)['leader_user_id']);
    }

    // ── editing a project ───────────────────────────────────────

    public function test_edit_changes_name_date_and_programme_info_and_reports_what_changed(): void
    {
        $a = $this->projectWithWork();
        $new = ['name' => 'Pune Cycle Audit', 'state' => 'Maharashtra', 'audit_year' => 2027, 'audit_date' => '2027-01-15', 'programme_info' => 'Funded by X'];
        $this->assertSame(['name', 'audit date', 'programme info'], $this->repo->updateAudit($a, $new));
        $b = $this->repo->find((int)$a['id']);
        $this->assertSame(['Pune Cycle Audit', 2027, '2027-01-15', 'Funded by X'], [$b['name'], (int)$b['audit_year'], $b['audit_date'], $b['programme_info']]);
        $this->assertSame('active', $b['status'], 'editing never changes the status');
        $this->assertSame(1, (int)$b['city_id']);
    }

    public function test_edit_with_nothing_new_changes_nothing(): void
    {
        $a = $this->projectWithWork();
        $this->assertSame([], $this->repo->updateAudit($a, $this->clean));
    }

    public function test_edit_can_add_programme_info_that_was_forgotten(): void
    {
        $a = $this->projectWithWork();
        $this->assertSame(['programme info'], $this->repo->updateAudit($a, ['programme_info' => 'Added later'] + $this->clean));
        $this->assertSame('Added later', $this->repo->find((int)$a['id'])['programme_info']);
    }

    public function test_edit_to_a_name_already_used_in_the_city_and_year_is_refused(): void
    {
        $a = $this->projectWithWork();
        $this->repo->createAudit(1, 1, ['name' => 'Other'] + $this->clean);
        try {
            $this->repo->updateAudit($a, ['name' => 'Other'] + $this->clean);
            $this->fail('duplicate name should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has this name', $e->getMessage());
        }
        $this->assertSame('Pune Audit', $this->repo->find((int)$a['id'])['name']);
    }

    // ── deleting a project ──────────────────────────────────────

    public function test_deletion_impact_counts_roads_segments_started_and_surveyors(): void
    {
        $a = $this->projectWithWork();
        $this->assertSame(['roads' => 2, 'segments' => 3, 'started' => 1, 'surveyors' => 2], $this->repo->deletionImpact((int)$a['id']));
        $this->assertSame([10, 11], $this->repo->assignedSurveyorIds((int)$a['id']));
    }

    public function test_delete_marks_it_voided_keeps_the_data_and_hides_it_from_the_city_list(): void
    {
        $a = $this->projectWithWork();
        $this->assertSame(1, count($this->repo->listForCity(1)));
        $this->repo->deleteAudit($a);
        $b = $this->repo->find((int)$a['id']);
        $this->assertSame('voided', $b['status']);
        $this->assertTrue($this->repo->isDeleted($b));
        $this->assertSame([], $this->repo->listForCity(1));
        $this->assertSame([], (new CityDashboardRepository($this->pdo))->auditSummaries(1));
        $this->assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn(), 'the data is kept');
    }

    public function test_a_deleted_projects_name_can_be_used_again(): void
    {
        $a = $this->projectWithWork();
        $this->repo->deleteAudit($a);
        $again = $this->repo->createAudit(1, 1, $this->clean);
        $this->assertGreaterThan((int)$a['id'], $again);
        $this->assertStringContainsString('[deleted #' . $a['id'] . ']', (string)$this->repo->find((int)$a['id'])['name']);
    }

    public function test_deleting_twice_or_editing_a_deleted_project_is_refused(): void
    {
        $a = $this->projectWithWork();
        $this->repo->deleteAudit($a);
        $gone = $this->repo->find((int)$a['id']);
        foreach ([fn() => $this->repo->deleteAudit($gone), fn() => $this->repo->updateAudit($gone, ['name' => 'Zzz'] + $this->clean)] as $act) {
            try {
                $act();
                $this->fail('should be refused');
            } catch (DomainException $e) {
                $this->assertStringContainsString('deleted', $e->getMessage());
            }
        }
    }

    // ── work stops on a deleted project ─────────────────────────

    public function test_surveyor_work_stops_with_a_clear_message_when_the_project_is_deleted(): void
    {
        $a = $this->projectWithWork();
        $work = new SurveyorWorkRepository($this->pdo);
        $this->assertFalse($work->projectDeletedForRoad(1));
        $this->assertFalse($work->projectDeletedForSegment(2));
        $this->assertSame(2, count($work->forSurveyor(10)));
        $this->assertNull($work->submitBlockReason(2, 10));

        $this->repo->deleteAudit($a);

        $this->assertTrue($work->projectDeletedForRoad(1));
        $this->assertTrue($work->projectDeletedForSegment(2));
        $this->assertSame([], $work->forSurveyor(10), 'it leaves the surveyor dashboard');
        $this->assertSame(PROJECT_DELETED_MESSAGE, $work->submitBlockReason(2, 10));
        $this->assertFalse($work->mayOpenRoad(1, 10));
        $this->assertFalse($work->mayOpenSegment(2, 10));
    }

    public function test_the_deleted_message_helper_and_wording(): void
    {
        $this->assertTrue(projectIsDeleted(['status' => 'voided']));
        $this->assertFalse(projectIsDeleted(['status' => 'active']));
        $this->assertFalse(projectIsDeleted(null));
        $this->assertSame(PROJECT_DELETED_MESSAGE, surveyorSubmitBlockReason(1, 'voided', 10, 'assigned', 10));
        $this->assertStringContainsString('deleted by the Admin', PROJECT_DELETED_MESSAGE);
    }

    public function test_project_roads_of_other_projects_are_unaffected(): void
    {
        $a = $this->projectWithWork();
        $b = $this->repo->find($this->repo->createAudit(1, 1, ['name' => 'Second'] + $this->clean));
        $this->pdo->exec("INSERT INTO roads (id, name, audit_id) VALUES (9,'Other Road',{$b['id']})");
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number) VALUES (90,9,1)");
        $this->repo->deleteAudit($a);
        $work = new SurveyorWorkRepository($this->pdo);
        $this->assertFalse($work->projectDeletedForRoad(9));
        $this->assertFalse($work->projectDeletedForSegment(90));
    }

    // ── wording ─────────────────────────────────────────────────

    public function test_notification_wording(): void
    {
        $d = projectDeletedNotificationText('Pune Audit');
        $this->assertSame('Project deleted', $d['title']);
        $this->assertStringContainsString('"Pune Audit"', $d['body']);
        $this->assertStringContainsString('Work on it has stopped', $d['body']);

        // With a reason, the reason is in the message; without one the message is unchanged.
        $r = projectDeletedNotificationText('Pune Audit', 'Duplicate of the Bengaluru audit');
        $this->assertStringContainsString('Reason: Duplicate of the Bengaluru audit', $r['body']);
        $this->assertStringNotContainsString('Reason:', $d['body']);
        $long = projectDeletedNotificationText(str_repeat('A', 150), str_repeat('b', 200));
        $this->assertLessThanOrEqual(500, mb_strlen($long['body']));

        $this->assertStringContainsString('name of the project', projectUpdatedNotificationText('X', ['name'])['body']);
        $this->assertStringContainsString('name, audit date and programme info', projectUpdatedNotificationText('X', ['name', 'audit date', 'programme info'])['body']);

        $this->assertSame('You are now City Leader', cityLeaderChangedNotificationText('Pune', true)['title']);
        $this->assertStringContainsString('Surveyor', cityLeaderChangedNotificationText('Pune', false)['body']);
    }

    // ── reason for deleting ─────────────────────────────────────

    public function test_delete_reason_rules(): void
    {
        $this->assertSame('Duplicate project', projectDeleteReasonClean("  Duplicate \n\t project \x07 "));
        $this->assertSame('', projectDeleteReasonClean(null));
        $this->assertSame('', projectDeleteReasonClean(['x']));
        $this->assertIsString(projectDeleteReasonError(''));
        $this->assertIsString(projectDeleteReasonError('abcd'));
        $this->assertNull(projectDeleteReasonError('abcde'));
        $this->assertNull(projectDeleteReasonError(str_repeat('x', 200)));
        $this->assertIsString(projectDeleteReasonError(str_repeat('x', 201)));
    }
}
