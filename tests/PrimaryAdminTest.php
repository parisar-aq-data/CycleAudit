<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PrimaryAdmin.php';

/** The original Admin keeps control; later Admins cannot change that account. */
class PrimaryAdminTest extends TestCase
{
    private function pdoWith(string $rows): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL)');
        if ($rows !== '') {
            $pdo->exec("INSERT INTO users (id, role) VALUES $rows");
        }
        return $pdo;
    }

    public function test_original_admin_is_the_lowest_admin_id(): void
    {
        $pdo = $this->pdoWith("(1,'surveyor'),(2,'admin'),(5,'admin'),(7,'city_leader')");
        $this->assertSame(2, primaryAdminId($pdo));
    }

    public function test_no_admin_means_no_original_admin(): void
    {
        $this->assertNull(primaryAdminId($this->pdoWith("(1,'surveyor')")));
        $this->assertNull(primaryAdminId($this->pdoWith('')));
    }

    public function test_a_later_admin_cannot_change_the_original_admin(): void
    {
        $err = primaryAdminProtectionError(5, 2, 2);
        $this->assertIsString($err);
        $this->assertNotSame('', $err);
    }

    public function test_the_original_admin_can_change_a_later_admin(): void
    {
        $this->assertNull(primaryAdminProtectionError(2, 2, 5));
    }

    public function test_the_original_admin_can_act_on_their_own_account(): void
    {
        $this->assertNull(primaryAdminProtectionError(2, 2, 2));
    }

    public function test_anyone_can_be_changed_when_the_target_is_not_the_original_admin(): void
    {
        $this->assertNull(primaryAdminProtectionError(5, 2, 9));
        $this->assertNull(primaryAdminProtectionError(5, null, 9));
    }
}
