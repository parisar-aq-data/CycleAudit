# End-to-end tests

These start from an **empty throwaway database** and drive a running copy of the app, over HTTP
(`requests`) and with a real browser (Playwright). They never touch production: `seed.php` refuses to
run unless the database name ends in `_test`.

They run automatically on every pull request (`.github/workflows/e2e.yml`).

## What is covered
- `test_signup_approval.py` - H1: register, blocked until approved, approve / reject / reactivate, per-city rules, CSRF, sign-up cap
- `test_sessions_passwords.py` - H3: a password change ends other sessions; wrong-password throttle; deactivation ends a live session
- `test_access_control.py` - who may open what, CSRF on writes, login lockout, no account-existence leak, CSP gap (expected failure until H6)
- `test_browser_flows.py` - the same sign-up/approval and two-browser password flows, clicked in Chromium

`tests/smoke/smoke.py` is separate: read-only checks of the live site (`.github/workflows/smoke.yml`).

## Running locally
```
# 1. an empty MySQL database named something_test, loaded with the table definitions
mysql -u root -e "CREATE DATABASE cycleaudit_test CHARACTER SET utf8mb4"
mysql -u root cycleaudit_test < tests/e2e/schema.sql

# 2. point the app at it and start it (PowerShell: $env:NAME = "value")
export MYSQLHOST=127.0.0.1 MYSQLDATABASE=cycleaudit_test MYSQLUSER=root MYSQLPASSWORD=
export BASE_URL=http://127.0.0.1:8080
php -S 127.0.0.1:8080 -t .

# 3. in another terminal (same variables set)
pip install pytest requests playwright && python -m playwright install chromium
python -m pytest tests/e2e -v
```

## Refreshing schema.sql
`schema.sql` is a schema-only dump of the live database. Refresh it after a migration that changes tables:
`mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --no-data --skip-comments --skip-add-drop-table railway`
(run in the Railway MySQL console), then delete the `SET @@GLOBAL.GTID_PURGED` line.
