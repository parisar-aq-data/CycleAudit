<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PasswordReset.php';

/** One-time password reset links, on in-memory SQLite. Times are passed in, so nothing waits. */
class PasswordResetTest extends TestCase
{
    private PDO $pdo;
    private int $t0 = 1_800_000_000;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL,
            password TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, password_changed_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NOT NULL,
            requested_ip TEXT NULL)');
        $this->pdo->exec("INSERT INTO users (id, name, email, password) VALUES
            (1, 'Asha Patil', 'asha@example.org', 'old-hash-1'),
            (2, 'Ravi Kulkarni', 'ravi@example.org', 'old-hash-2')");
        $this->pdo->exec("INSERT INTO users (id, name, email, password, is_active) VALUES (3, 'Gone Person', 'gone@example.org', 'x', 0)");
    }

    private function passwordOf(int $id): string
    {
        return (string)$this->pdo->query("SELECT password FROM users WHERE id = $id")->fetchColumn();
    }

    // ── rules ───────────────────────────────────────────────────

    public function test_password_rules_match_sign_up(): void
    {
        $this->assertSame('Password must be at least 8 characters.', passwordRuleError('abc12', 'abc12'));
        $this->assertSame('Password must contain at least one letter and one number.', passwordRuleError('abcdefgh', 'abcdefgh'));
        $this->assertSame('Password must contain at least one letter and one number.', passwordRuleError('12345678', '12345678'));
        $this->assertSame('Passwords do not match.', passwordRuleError('abcdefg1', 'abcdefg2'));
        $this->assertNull(passwordRuleError('abcdefg1', 'abcdefg1'));
    }

    // ── finding the account ─────────────────────────────────────

    public function test_find_user_returns_active_accounts_only(): void
    {
        $u = passwordResetFindUser($this->pdo, 'asha@example.org');
        $this->assertSame(1, $u['id']);
        $this->assertSame('Asha Patil', $u['name']);
        $this->assertNull(passwordResetFindUser($this->pdo, 'gone@example.org'), 'deactivated account');
        $this->assertNull(passwordResetFindUser($this->pdo, 'nobody@example.org'));
    }

    // ── creating and looking up links ───────────────────────────

    public function test_token_is_random_hex_and_only_its_hash_is_stored(): void
    {
        $a = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $b = passwordResetCreate($this->pdo, 2, '1.2.3.4', $this->t0);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        $this->assertNotSame($a, $b);

        $stored = $this->pdo->query('SELECT token_hash FROM password_resets WHERE user_id = 1')->fetchColumn();
        $this->assertSame(hash('sha256', $a), $stored);
        $this->assertNotSame($a, $stored);
    }

    public function test_valid_link_finds_its_owner(): void
    {
        $token = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $u = passwordResetLookup($this->pdo, $token, $this->t0 + 60);
        $this->assertSame(1, $u['user_id']);
        $this->assertSame('asha@example.org', $u['email']);
    }

    public function test_link_expires_after_the_time_limit(): void
    {
        $token = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $limit = PASSWORD_RESET_TTL_MINUTES * 60;
        $this->assertNotNull(passwordResetLookup($this->pdo, $token, $this->t0 + $limit - 1));
        $this->assertNull(passwordResetLookup($this->pdo, $token, $this->t0 + $limit + 1));
    }

    public function test_malformed_and_unknown_tokens_find_nobody(): void
    {
        passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $this->assertNull(passwordResetLookup($this->pdo, '', $this->t0));
        $this->assertNull(passwordResetLookup($this->pdo, 'not-a-token', $this->t0));
        $this->assertNull(passwordResetLookup($this->pdo, str_repeat('a', 64), $this->t0));
        $this->assertNull(passwordResetLookup($this->pdo, "' OR 1=1 --", $this->t0));
    }

    public function test_a_newer_link_cancels_the_older_one_for_that_account_only(): void
    {
        $old   = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $other = passwordResetCreate($this->pdo, 2, '1.2.3.4', $this->t0);
        $new   = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0 + 300);

        $this->assertNull(passwordResetLookup($this->pdo, $old, $this->t0 + 301));
        $this->assertNotNull(passwordResetLookup($this->pdo, $new, $this->t0 + 301));
        $this->assertNotNull(passwordResetLookup($this->pdo, $other, $this->t0 + 301));
    }

    public function test_link_of_a_deactivated_account_does_not_work(): void
    {
        $token = passwordResetCreate($this->pdo, 3, '1.2.3.4', $this->t0);
        $this->assertNull(passwordResetLookup($this->pdo, $token, $this->t0));
    }

    public function test_cooldown_stops_a_second_email_straight_away(): void
    {
        $this->assertFalse(passwordResetRecentlyRequested($this->pdo, 1, $this->t0));
        passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $this->assertTrue(passwordResetRecentlyRequested($this->pdo, 1, $this->t0 + 30));
        $this->assertFalse(passwordResetRecentlyRequested($this->pdo, 1, $this->t0 + PASSWORD_RESET_COOLDOWN_SEC + 5));
        $this->assertFalse(passwordResetRecentlyRequested($this->pdo, 2, $this->t0 + 30), 'other accounts are not affected');
    }

    public function test_old_rows_are_pruned_when_a_new_link_is_made(): void
    {
        passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        passwordResetCreate($this->pdo, 2, '1.2.3.4', $this->t0 + (PASSWORD_RESET_KEEP_DAYS + 1) * 86400);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    // ── completing a reset ──────────────────────────────────────

    public function test_complete_sets_the_new_password_and_uses_up_the_link(): void
    {
        $token = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);

        $this->assertTrue(passwordResetComplete($this->pdo, $token, 'brandnew1', $this->t0 + 10));

        $this->assertTrue(password_verify('brandnew1', $this->passwordOf(1)));
        $this->assertSame('old-hash-2', $this->passwordOf(2), 'other accounts untouched');
        $this->assertNull(passwordResetLookup($this->pdo, $token, $this->t0 + 11));
        $this->assertFalse(passwordResetComplete($this->pdo, $token, 'another-one2', $this->t0 + 12), 'a link works once');
        $this->assertTrue(password_verify('brandnew1', $this->passwordOf(1)));
    }

    public function test_complete_ends_older_sign_ins_by_stamping_password_changed_at(): void
    {
        $token = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);

        $this->assertTrue(passwordResetComplete($this->pdo, $token, 'brandnew1', $this->t0 + 10));

        $stamp = $this->pdo->query('SELECT password_changed_at FROM users WHERE id = 1')->fetchColumn();
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->t0 + 10), $stamp, 'stored in UTC');
        $this->assertNull($this->pdo->query('SELECT password_changed_at FROM users WHERE id = 2')->fetchColumn(), 'other accounts untouched');
    }

    public function test_complete_refuses_expired_and_unknown_links(): void
    {
        $token = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        $late  = $this->t0 + PASSWORD_RESET_TTL_MINUTES * 60 + 5;

        $this->assertFalse(passwordResetComplete($this->pdo, $token, 'brandnew1', $late));
        $this->assertFalse(passwordResetComplete($this->pdo, str_repeat('b', 64), 'brandnew1', $this->t0));
        $this->assertSame('old-hash-1', $this->passwordOf(1));
    }

    public function test_complete_closes_every_open_link_of_that_account(): void
    {
        $first = passwordResetCreate($this->pdo, 1, '1.2.3.4', $this->t0);
        // A second link made straight into the table, as if two requests had raced.
        $this->pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (1, ?, ?, ?)')
            ->execute([hash('sha256', str_repeat('c', 64)), passwordResetStamp($this->t0 + 3000), passwordResetStamp($this->t0)]);

        $this->assertTrue(passwordResetComplete($this->pdo, $first, 'brandnew1', $this->t0 + 5));
        $this->assertNull(passwordResetLookup($this->pdo, str_repeat('c', 64), $this->t0 + 6));
    }

    // ── link and email ──────────────────────────────────────────

    public function test_link_uses_the_configured_base_url(): void
    {
        $this->assertSame(
            'https://cycleaudit.parisar.org/auth/reset_password.php?token=abc',
            passwordResetLink('https://cycleaudit.parisar.org/', 'abc')
        );
    }

    public function test_email_has_the_link_the_expiry_and_a_first_name(): void
    {
        $m = passwordResetEmail('Asha Patil', 'https://x.example/auth/reset_password.php?token=t1', 'CycleAudit');
        $this->assertSame('Reset your CycleAudit password', $m['subject']);
        $this->assertStringContainsString('Hi Asha,', $m['text']);
        $this->assertStringContainsString('https://x.example/auth/reset_password.php?token=t1', $m['text']);
        $this->assertStringContainsString('expires in ' . PASSWORD_RESET_TTL_MINUTES . ' minutes', $m['text']);
        $this->assertStringContainsString('href="https://x.example/auth/reset_password.php?token=t1"', $m['html']);
    }

    public function test_email_escapes_the_name_in_html(): void
    {
        $m = passwordResetEmail('<script>alert(1)</script> Bob', 'https://x.example/r?token=t', 'CycleAudit');
        $this->assertStringNotContainsString('<script>', $m['html']);
        $this->assertStringContainsString('&lt;script&gt;', $m['html']);
    }
}
