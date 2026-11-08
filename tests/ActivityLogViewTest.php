<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ActivityLogger.php';
require_once __DIR__ . '/../helpers/ActivityLogView.php';

/** The Activity Log page: every recorded action reads as a sentence, with names instead of ids. */
class ActivityLogViewTest extends TestCase
{
    // ── nothing can be logged that the page cannot show ─────────

    public function test_every_logger_constant_has_a_label_and_group(): void
    {
        $map = activityActionMap();
        $cats = activityCategories();
        foreach ((new ReflectionClass(ActivityLogger::class))->getConstants() as $name => $action) {
            $this->assertArrayHasKey($action, $map, "ActivityLogger::$name ('$action') has no label in activityActionMap()");
            $this->assertArrayHasKey($map[$action][1], $cats, "'$action' is in an unknown group");
        }
    }

    public function test_every_constant_used_in_the_code_exists(): void
    {
        // An undefined constant is a fatal error that would stop the request it was meant to record.
        $defined = array_keys((new ReflectionClass(ActivityLogger::class))->getConstants());
        $root = dirname(__DIR__);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            preg_match_all('/ActivityLogger::([A-Z][A-Z_]+)\b/', (string)file_get_contents($path), $m);
            foreach ($m[1] as $const) {
                $seen++;
                $this->assertContains($const, $defined, "$path uses ActivityLogger::$const, which does not exist");
            }
        }
        $this->assertGreaterThan(40, $seen);
    }

    // ── wording ─────────────────────────────────────────────────

    public function test_project_deleted_shows_name_reason_and_size(): void
    {
        $t = activityDescribe('audit_deleted', ['audit_id' => 5, 'name' => 'Pune Audit', 'reason' => 'Duplicate', 'roads' => 1, 'segments' => 3, 'surveyors' => 2], []);
        $this->assertSame('Deleted project “Pune Audit” (1 road, 3 segments, 2 surveyors). Reason: Duplicate', $t);
    }

    public function test_ids_become_names_and_missing_ones_say_so(): void
    {
        $names = ['user' => [7 => 'Asha'], 'audit' => [5 => '002'], 'road' => [], 'segment' => [9 => 'segment 2 of MG Road'], 'city' => [1 => 'Pune', 2 => 'Bengaluru']];
        $this->assertSame('Reset the password of Asha. A temporary password was issued.', activityDescribe('user_password_reset', ['user_id' => 7], $names));
        $this->assertSame('Approved segment 2 of MG Road in project “002”.', activityDescribe('audit_segment_approved', ['audit_id' => 5, 'segment_id' => 9], $names));
        $this->assertSame('Moved Asha from Pune to Bengaluru.', activityDescribe('user_city_changed', ['user_id' => 7, 'from_city_id' => 1, 'to_city_id' => 2], $names));
        $this->assertSame('Changed the role of user #8 from Surveyor to City Leader (Pune).', activityDescribe('user_role_changed', ['user_id' => 8, 'from_role' => 'surveyor', 'to_role' => 'city_leader', 'city_id' => 1], $names));
        $this->assertSame('Created road #44 (no longer exists).', activityDescribe('road_created', ['road_id' => 44], $names));
        $this->assertSame('Created a road.', activityDescribe('road_created', [], $names));
    }

    public function test_sign_in_and_password_events(): void
    {
        $this->assertSame('Could not sign in as a@b.in: wrong password.', activityDescribe('user_login_failed', ['email' => 'a@b.in', 'reason' => 'wrong_password'], []));
        $this->assertStringContainsString('no account', activityDescribe('user_login_failed', ['email' => 'x@y.in', 'reason' => 'unknown_email'], []));
        $this->assertSame('Changed their own password.', activityDescribe('password_changed', ['source' => 'profile'], []));
        $this->assertStringContainsString('temporary', activityDescribe('password_changed', ['source' => 'first_login'], []));
        $this->assertStringContainsString('no account with this email', activityDescribe('password_reset_requested', ['email' => 'x@y.in', 'found' => false], []));
    }

    public function test_downloads_say_what_was_downloaded(): void
    {
        $this->assertSame('Downloaded the Excel list of users.', activityDescribe('export_downloaded', ['type' => 'users_excel'], []));
        $this->assertSame('Downloaded the PDF report of a road audit for road “MG Road”.', activityDescribe('export_downloaded', ['type' => 'road_report_pdf', 'road_id' => 3], ['road' => [3 => 'MG Road']]));
    }

    public function test_an_unknown_action_still_shows_what_was_recorded(): void
    {
        $info = activityActionInfo('something_new');
        $this->assertSame('Something new', $info['label']);
        $this->assertSame('other', $info['category']);
        $this->assertSame('a: 1, b: x', activityDescribe('something_new', ['a' => 1, 'b' => 'x', 'nested' => [1]], []));
    }

    public function test_tone_colours(): void
    {
        $this->assertSame('bad', activityActionInfo('audit_deleted')['tone']);
        $this->assertSame('bad', activityActionInfo('user_deactivated')['tone']);
        $this->assertSame('bad', activityActionInfo('user_login_failed')['tone']);
        $this->assertSame('good', activityActionInfo('audit_activated')['tone']);
        $this->assertSame('good', activityActionInfo('user_login')['tone']);
        $this->assertSame('neutral', activityActionInfo('user_password_reset')['tone']);
    }

    // ── ids and names ───────────────────────────────────────────

    public function test_collects_ids_by_kind_without_duplicates(): void
    {
        $ids = activityCollectIds([
            ['user_id' => 3, 'audit_id' => 5, 'from_city_id' => 1, 'to_city_id' => '2'],
            ['target_user_ids' => [3, 4], 'audit_id' => 5, 'segment_id' => 9, 'road_id' => 0],
            ['name' => 'x'],
        ]);
        $this->assertSame([3, 4], $ids['user']);
        $this->assertSame([5], $ids['audit']);
        $this->assertSame([1, 2], $ids['city']);
        $this->assertSame([9], $ids['segment']);
        $this->assertSame([], $ids['road']);
    }

    public function test_loads_names_from_the_database(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1,'Asha'),(2,'Ravi')");
        $pdo->exec("INSERT INTO city_audits VALUES (5,'002')");
        $pdo->exec("INSERT INTO roads VALUES (3,'MG Road')");
        $pdo->exec("INSERT INTO cities VALUES (1,'Pune')");
        $names = activityLoadNames($pdo, ['user' => [1, 2, 99], 'audit' => [5], 'road' => [3], 'segment' => [], 'city' => [1]]);
        $this->assertSame([1 => 'Asha', 2 => 'Ravi'], $names['user']);
        $this->assertSame([5 => '002'], $names['audit']);
        $this->assertSame([3 => 'MG Road'], $names['road']);
        $this->assertSame([1 => 'Pune'], $names['city']);
    }

    // ── finished entries ────────────────────────────────────────

    public function test_entries_name_the_actor_even_when_unknown(): void
    {
        $rows = [
            ['id' => 'a1', 'created_at' => '2026-11-08 12:00:00', 'user_id' => null, 'actor_name' => null, 'actor_role' => null, 'action' => 'user_login_failed', 'meta' => ['email' => 'x@y.in', 'reason' => 'unknown_email'], 'ip' => '1.2.3.4'],
            ['id' => 'a2', 'created_at' => '2026-11-08 12:01:00', 'user_id' => 12, 'actor_name' => null, 'actor_role' => null, 'action' => 'user_logout', 'meta' => [], 'ip' => ''],
            ['id' => 'a3', 'created_at' => '2026-11-08 12:02:00', 'user_id' => 1, 'actor_name' => 'Admin', 'actor_role' => 'admin', 'action' => 'audit_deleted', 'meta' => ['name' => 'P', 'reason' => 'dup'], 'ip' => '5.6.7.8'],
        ];
        $e = activityBuildEntries($rows, []);
        $this->assertSame('Not signed in', $e[0]['actor_name']);
        $this->assertSame('Deleted user #12', $e[1]['actor_name']);
        $this->assertSame('Admin', $e[2]['actor_name']);
        $this->assertSame('Admin', $e[2]['actor_role']);
        $this->assertSame('account', $e[0]['category']);
        $this->assertSame('8 Nov 2026, 5:30 PM', $e[0]['when']);   // 12:00 UTC is 17:30 in India
    }

    // ── filters ─────────────────────────────────────────────────

    public function test_filter_request_keeps_good_values_and_drops_bad_ones(): void
    {
        $f = activityFilterFromRequest([
            'category' => 'passwords', 'action' => 'not_an_action', 'user' => '7', 'role' => 'surveyor',
            'audit' => '5', 'from' => '2026-11-08', 'to' => '2026-11-01', 'q' => '  Pune  ', 'tone' => 'bad', 'order' => 'asc',
        ]);
        $this->assertSame('passwords', $f['category']);
        $this->assertSame('', $f['action']);            // unknown action is dropped
        $this->assertSame('7', $f['user']);
        $this->assertSame('surveyor', $f['role']);
        $this->assertSame(5, $f['audit']);
        $this->assertSame(['2026-11-01', '2026-11-08'], [$f['from'], $f['to']]);   // swapped into order
        $this->assertSame('Pune', $f['q']);
        $this->assertSame('bad', $f['tone']);
        $this->assertSame('asc', $f['order']);

        $junk = activityFilterFromRequest(['category' => 'x', 'user' => "1; DROP TABLE users", 'role' => 'root', 'audit' => '-3', 'from' => 'yesterday', 'order' => 'sideways', 'q' => ['a']]);
        $this->assertSame('', $junk['category']);
        $this->assertNull($junk['user']);
        $this->assertSame('', $junk['role']);
        $this->assertSame(0, $junk['audit']);
        $this->assertNull($junk['from']);
        $this->assertSame('desc', $junk['order']);
        $this->assertSame('', $junk['q']);

        $this->assertSame('none', activityFilterFromRequest(['user' => 'none'])['user']);
    }

    public function test_a_day_in_india_becomes_the_matching_utc_window(): void
    {
        // India is 5h30 ahead of UTC: 8 Nov in India starts at 18:30 UTC on 7 Nov.
        $this->assertSame(['2026-11-07 18:30:00', '2026-11-08 18:30:00'], activityDateRangeUtc('2026-11-08', '2026-11-08'));
        $this->assertSame(['2026-11-07 18:30:00', null], activityDateRangeUtc('2026-11-08', null));
        $this->assertSame([null, '2026-11-02 18:30:00'], activityDateRangeUtc(null, '2026-11-02'));
        $this->assertSame([null, null], activityDateRangeUtc('2026-02-31', 'junk'));
    }

    public function test_group_and_quick_view_action_lists(): void
    {
        $passwords = activityActionsFor('passwords', '');
        $this->assertContains('password_changed', $passwords);
        $this->assertNotContains('user_login', $passwords);
        $this->assertSame(['user_login'], activityActionsFor('', 'user_login'));
        $this->assertSame([], activityActionsFor('', 'nope'));

        $bad = activityBadActions();
        foreach (['audit_deleted', 'user_login_failed', 'user_deactivated', 'road_deleted'] as $a) {
            $this->assertContains($a, $bad);
        }
        $this->assertNotContains('user_login', $bad);
        $this->assertNotContains('audit_created', $bad);
    }

    public function test_search_needs_every_word_somewhere_in_the_row(): void
    {
        $e = ['actor_name' => 'Bengaluru_Leader', 'actor_role' => 'City Leader', 'label' => 'Project deleted', 'details' => 'Deleted project “002”. Reason: need to end this project', 'ip' => '152.56.7.28'];
        $this->assertTrue(activityEntryMatches($e, ''));
        $this->assertTrue(activityEntryMatches($e, 'bengaluru deleted'));
        $this->assertTrue(activityEntryMatches($e, 'REASON need'));
        $this->assertTrue(activityEntryMatches($e, '152.56'));
        $this->assertFalse(activityEntryMatches($e, 'bengaluru surveyor'));
    }

    public function test_filter_choices_list_people_in_the_log_and_every_project(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT)');
        $pdo->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INTEGER)');
        $pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, actor_id INTEGER)');
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1,'Zed','admin'),(2,'Asha','city_leader'),(3,'Quiet','surveyor')");
        $pdo->exec('INSERT INTO activity_log (user_id) VALUES (1),(1),(NULL)');
        $pdo->exec('INSERT INTO audit_log (actor_id) VALUES (2)');
        $pdo->exec("INSERT INTO city_audits VALUES (5,'final test'),(18,'002 [deleted #18]')");
        $o = activityFilterOptions($pdo);
        $this->assertSame([['id' => 2, 'name' => 'Asha', 'role' => 'City Leader'], ['id' => 1, 'name' => 'Zed', 'role' => 'Admin']], $o['people']);   // by name; Quiet never acted
        $this->assertSame([18, 5], array_column($o['projects'], 'id'));
    }
}
