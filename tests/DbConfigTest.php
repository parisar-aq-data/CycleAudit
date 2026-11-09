<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/DbConfig.php';

/** Security review H5: the database user is never silently "root" on a real server. */
class DbConfigTest extends TestCase
{
    public function test_your_own_computer_keeps_the_xampp_defaults(): void
    {
        $this->assertSame(['root', ''], resolveDbCredentials('localhost', '', ''));
        $this->assertSame(['root', ''], resolveDbCredentials('127.0.0.1', '', ''));
        $this->assertSame(['root', ''], resolveDbCredentials('::1', '', ''));
        $this->assertSame(['root', ''], resolveDbCredentials('LOCALHOST', '', ''));
    }

    public function test_on_your_own_computer_a_chosen_user_and_password_are_used(): void
    {
        $this->assertSame(['dev', 'secret'], resolveDbCredentials('localhost', 'dev', 'secret'));
        $this->assertSame(['dev', ''], resolveDbCredentials('localhost', 'dev', ''));
    }

    public function test_a_real_server_with_both_values_set_is_used_as_given(): void
    {
        $this->assertSame(['cycleaudit_app', 'pw-123'],
            resolveDbCredentials('mysql.railway.internal', 'cycleaudit_app', 'pw-123'));
        // Still allowed, so the switch to a limited user can be done at your own pace.
        $this->assertSame(['root', 'pw'], resolveDbCredentials('mysql.railway.internal', 'root', 'pw'));
    }

    public function test_a_real_server_with_nothing_set_refuses_instead_of_using_root(): void
    {
        $this->expectException(RuntimeException::class);
        resolveDbCredentials('mysql.railway.internal', '', '');
    }

    public function test_a_real_server_with_no_password_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        resolveDbCredentials('mysql.railway.internal', 'cycleaudit_app', '');
    }

    public function test_a_real_server_with_no_user_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        resolveDbCredentials('db.example.com', '', 'pw-123');
    }

    public function test_the_refusal_names_the_host_but_never_a_password(): void
    {
        try {
            resolveDbCredentials('db.example.com', 'someone', '');
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('db.example.com', $e->getMessage());
            $this->assertStringContainsString('MYSQLPASSWORD', $e->getMessage());
        }
        try {
            resolveDbCredentials('db.example.com', '', 'TopSecretValue');
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('TopSecretValue', $e->getMessage());
        }
    }

    public function test_only_the_real_local_names_count_as_local(): void
    {
        $this->assertTrue(isLocalDbHost('localhost'));
        $this->assertFalse(isLocalDbHost('localhost.evil.example'));
        $this->assertFalse(isLocalDbHost('10.0.0.5'));
        $this->assertFalse(isLocalDbHost(''));
    }
}
