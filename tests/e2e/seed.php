<?php
declare(strict_types=1);

// ───────────────────────────────────────────────────────────────
//  tests/e2e/seed.php
//  Test helper for the end-to-end suite. NEVER run against production.
//
//    php tests/e2e/seed.php seed          empty the tables and add the test cities and people
//    php tests/e2e/seed.php reset-limits  clear the rate-limit counters (between tests)
//    php tests/e2e/seed.php user EMAIL    print one user row as JSON
//
//  Reads the same MYSQLHOST / MYSQLPORT / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD
//  variables as the app. Refuses to run unless the database name ends in "_test"
//  or E2E_ALLOW_ANY_DB=1 is set, so a wrong variable cannot wipe real data.
// ───────────────────────────────────────────────────────────────

$host = getenv('MYSQLHOST') ?: '127.0.0.1';
$port = (int)(getenv('MYSQLPORT') ?: 3306);
$name = getenv('MYSQLDATABASE') ?: 'cycleaudit_test';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';

if (!str_ends_with($name, '_test') && getenv('E2E_ALLOW_ANY_DB') !== '1') {
    fwrite(STDERR, "Refusing to run: database '$name' does not end in _test.\n");
    exit(2);
}

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

const TEST_PASSWORD = 'Test@12345';

$cmd = $argv[1] ?? '';

if ($cmd === 'seed') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec('TRUNCATE TABLE `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $pdo->exec("INSERT INTO cities (id, name, slug) VALUES (1, 'Pune', 'pune'), (2, 'Bengaluru', 'bengaluru')");

    $hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);
    $people = [
        // id, public_id, name, email, role, city_id
        [1, 'USR-0001', 'Test Admin',        'admin@test.local',          'admin',       null],
        [2, 'USR-0002', 'Leader Pune',       'leader.pune@test.local',    'city_leader', 1],
        [3, 'USR-0003', 'Leader Bengaluru',  'leader.blr@test.local',     'city_leader', 2],
        [4, 'USR-0004', 'Surveyor Pune',     'surveyor.pune@test.local',  'surveyor',    1],
        [5, 'USR-0005', 'Surveyor Bengaluru','surveyor.blr@test.local',   'surveyor',    2],
    ];
    $ins = $pdo->prepare(
        'INSERT INTO users (id, public_id, name, email, password, role, city_id, is_active, approval_status, email_verified)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, \'approved\', 1)'
    );
    foreach ($people as [$id, $pub, $n, $email, $role, $city]) {
        $ins->execute([$id, $pub, $n, $email, $hash, $role, $city]);
    }
    echo "seeded\n";
    exit(0);
}

if ($cmd === 'reset-limits') {
    $pdo->exec('TRUNCATE TABLE rate_limit_attempts');
    echo "limits cleared\n";
    exit(0);
}

if ($cmd === 'user') {
    $st = $pdo->prepare('SELECT id, name, email, role, city_id, is_active, approval_status FROM users WHERE email = ?');
    $st->execute([$argv[2] ?? '']);
    echo json_encode($st->fetch() ?: null), "\n";
    exit(0);
}

fwrite(STDERR, "usage: php tests/e2e/seed.php seed|reset-limits|user EMAIL\n");
exit(2);
