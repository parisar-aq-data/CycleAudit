<?php
declare(strict_types=1);

// ───────────────────────────────────────────────────────────────
//  helpers/DbConfig.php
//  Which MySQL user the app connects as (security review H5).
//
//  Rule: the old convenience fallback ("root", empty password) is only for your
//  own computer. On any other host a missing user or password is a configuration
//  mistake, and the app must stop instead of trying root.
// ───────────────────────────────────────────────────────────────

/** True when the database is on this same machine (local XAMPP development). */
function isLocalDbHost(string $host): bool
{
    return in_array(strtolower(trim($host)), ['localhost', '127.0.0.1', '::1'], true);
}

/**
 * @return array{0:string,1:string}  [user, password]
 * @throws RuntimeException          when a non-local host has no user or no password
 */
function resolveDbCredentials(string $host, string $user, string $pass): array
{
    if (isLocalDbHost($host)) {
        return [$user !== '' ? $user : 'root', $pass];
    }

    if ($user === '' || $pass === '') {
        // Never put the password in the message. Say what to fix, not what the values are.
        throw new RuntimeException(sprintf(
            'Database credentials are not set for host "%s": set MYSQLUSER and MYSQLPASSWORD '
            . '(or DB_USER and DB_PASS). Refusing to fall back to root.',
            $host
        ));
    }

    return [$user, $pass];
}
