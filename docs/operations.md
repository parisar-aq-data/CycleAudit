# Operations

How CycleAudit is built into a container, run on Railway, checked by CI, backed up, and fixed when something goes wrong. This chapter lists environment variable **names** only, never values.

Related chapters: [database](database.md) (how to add a migration, read-only queries), [auth and permissions](auth-and-permissions.md) (rate limits, locked-out accounts), [frontend](frontend.md) (CSP, which you will see in the browser console after a deploy), and [BACKUPS.md](../BACKUPS.md) (the backup job in full).

Everything here was read from the repository. Railway's own settings (cron schedule, health check, branch rules, which services exist) live in Railway, not in the code, so where a statement rests on the Railway screens you shared or on a README line, it says so. Items that could not be proved are marked "(not verified)".

## 1. What runs where

| Piece | What it is | Source |
| --- | --- | --- |
| **Web service** (`Parisar`) | The app: Apache + PHP in one container, built from `Dockerfile`, served at `cycleaudit.parisar.org` | Railway dashboard: deployments listed "via GitHub", one replica, US West |
| **MySQL service** | Managed MySQL 8 database | README; the app reads Railway's `MYSQL*` variables |
| **`backup-cron` service** | Runs `scripts/backup.sh` once a day and uploads a dump to Backblaze B2 | `BACKUPS.md`; the dashboard shows a `-cron` service |
| **GitHub Actions** | Lint, tests, security scan, live smoke test | `.github/workflows/` |

The Railway project is named `aware-tranquility` and the environment `production` in the dashboard. There is no staging environment in the screens I have seen (not verified).

## 2. The container image

`Dockerfile` builds one image used for the web service.

- **Base:** `ubuntu:22.04` with Apache 2, PHP 8.1 and these PHP extensions: `mysql`, `gd`, `curl`, `mbstring`, `xml`, `zip`. Also `curl`, `git`, `unzip`, `zip`, the MySQL client and `rclone`. The image is also able to run the backup job, because `mysqldump` and `rclone` are installed in it (the cron service's start command is set in Railway, not in the repository).
- **PHP settings** appended to `php.ini`: errors logged and not shown (`display_errors = Off`, `log_errors = On`, log file `/var/log/apache2/php_errors.log`), `expose_php = Off`, `max_execution_time = 60`, `memory_limit = 256M`, `upload_max_filesize = 10M`, `post_max_size = 12M`, secure session cookie flags (`httponly`, `secure`, `samesite=Lax`, `use_strict_mode`), `session.gc_maxlifetime = 28800` (8 hours), OPcache on.
- **Code:** `COPY . .` into `/var/www/html`, then `composer install --no-dev --optimize-autoloader`. `index.html` from the Apache default is removed first.
- **Apache:** `AllowOverride All` (so `.htaccess` works), `ServerTokens Prod`, `ServerSignature Off`, modules `rewrite` and `headers`.
- **Permissions:** everything under `/var/www/html` is owned by `root` (directories `755`, files `644`), so the web server user `www-data` cannot change the code. Only `logs/` and `reports/` are owned by `www-data` and writable. The workflow `docker-permissions.yml` checks this (section 6).
- **Start-up:** `docker-entrypoint.sh` reads Railway's `PORT` (default 80), rewrites Apache's `Listen` line and the virtual host to that port, and starts Apache in the foreground.
- **Restarts:** `railway.toml` sets the Dockerfile builder and `restartPolicyType = "ON_FAILURE"` with at most 3 retries.

`railway.toml` has no health check path. The README tells you to point a health check at `/health.php` in Railway; whether that is set is a dashboard setting (not verified).

## 3. Environment variables

The app reads each variable from `$_ENV`, then `$_SERVER`, then `getenv()` (`_env()` in `config/constants.php`), because Railway injects variables into `$_SERVER` inside Docker. A local `.env` file is read by `vlucas/phpdotenv` when the library is installed. Set values in Railway's **Variables** tab. Never commit real values.

**Web service**

| Name | Purpose | If missing |
| --- | --- | --- |
| `MYSQLHOST` (or `DB_HOST`) | Database host | `localhost` |
| `MYSQLPORT` (or `DB_PORT`) | Database port | `3306` |
| `MYSQLDATABASE` (or `DB_NAME`) | Database name | `parisar_db` |
| `MYSQLUSER` (or `DB_USER`) | Database user | See below |
| `MYSQLPASSWORD` (or `DB_PASS`) | Database password | See below |
| `PORT` | Port Apache listens on; set by Railway | `80` |
| `BASE_URL` | Public URL of the app | `http://localhost/Parisar` |
| `MAIL_PROVIDER`, `MAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` | Email service for the emailed reset link | Empty. No email is sent today, whatever these are set to ([auth and permissions](auth-and-permissions.md) section 7.4) |

**Database credentials are strict.** When the host is `localhost`, `127.0.0.1` or `::1`, a missing user falls back to `root` (for XAMPP). On any other host, a missing user or password stops the app: every page and endpoint answers `503` with "Database unavailable" and the real reason goes to the error log (`helpers/DbConfig.php`). The app will not try `root` on a real server.

**`backup-cron` service** (set these on that service only)

| Name | Purpose |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | The same database. Wire them with Railway's Variable Reference picker (`${{MySQL.MYSQLHOST}}` and so on) |
| `BACKUP_S3_ENDPOINT`, `BACKUP_S3_BUCKET`, `BACKUP_S3_ACCESS_KEY`, `BACKUP_S3_SECRET_KEY` | The Backblaze B2 bucket and its application key |
| `RETENTION_DAYS` | Days of backups to keep (default 7) |

**Tests only:** `E2E_ALLOW_ANY_DB` (lets the end-to-end seed script run against a database whose name does not end in `_test`; do not set it in Railway) and `BASE_URL` (the address the end-to-end tests drive).

## 4. Deploying a change

1. Work on a branch and open a pull request into `main`. Wait for the checks in section 6.
2. If the change needs a database change, take a backup, then run the SQL **before** merging code that depends on it (see [database](database.md) section 7 for the order and the one-statement-per-query rule).
3. Merge. Railway builds the new image and deploys it. The dashboard lists each deployment as "Merge pull request #N from ..." with the status "Deployment successful". Documentation-only merges (#165 to #167) trigger a deployment too, because the whole repository is the image.
4. When GitHub receives a successful deployment status, `smoke.yml` runs after a 45 second pause and checks the live site (section 6).
5. Open the live site with the browser console visible and watch for red "Content Security Policy" messages.

Whether Railway waits for the GitHub checks before it deploys is a Railway setting ("Wait for CI"); I could not see it (not verified). Treat a merge as a deploy.

**Undo a bad merge:** `git revert -m 1 <merge commit>` and push. The revert is itself a deployment.

**There is no migration runner.** The 33 files in `migrations/` (000 to 032) are applied by hand in Railway's MySQL query box. A fresh database starts from `tests/e2e/schema.sql`, not from the migrations ([database](database.md) section 1).

## 5. The health check

`health.php` (project root) answers `200` or `503` as JSON and needs no sign-in:

```json
{ "status": "ok", "app": "CycleAudit", "org": "Parisar",
  "checks": { "database": "ok", "reports_dir": "ok", "php": "8.1" },
  "response_ms": 12.3, "timestamp": "..." }
```

| Check | Fails when | Effect |
| --- | --- | --- |
| `database` | Cannot connect or `SELECT 1` fails (3 second timeout) | `status` is `degraded` and the HTTP status is `503` |
| `reports_dir` | `reports/` is missing or not writable | Shows `not_writable` only. Still `200` |
| `php` | Never | Reports the PHP version |

The page sets `Cache-Control: no-store`. `.htaccess` skips the HTTP-to-HTTPS redirect for `/health.php`, so a plain-HTTP probe is not redirected. The end-to-end workflow waits for it before it starts testing, and the smoke test requires a `200`, `status: ok`, `database: ok` and an answer within 3 seconds. The file's own comment says it also serves an uptime monitor; no monitor is configured in the repository (not verified).

## 6. CI workflows

All five workflows are in `.github/workflows/`. Third-party actions are pinned to a commit.

| Workflow | Runs | What it does | Blocks a merge? |
| --- | --- | --- | --- |
| **CI / CD** (`deploy.yml`) | Push and pull request to `main` | Job `audit`: `composer audit --locked` fails on known vulnerabilities (abandoned packages are only listed). Job `test`: PHP syntax check of every `.php` file, then PHPUnit (583 test methods in 47 files) | Its checks fail the run. Whether `main` requires them is a GitHub setting (not verified) |
| **End-to-end tests** (`e2e.yml`) | Push and pull request to `main`, on demand | Starts a throwaway app (`php -S`) on an empty MySQL 8 database loaded from `tests/e2e/schema.sql`, then runs pytest and Playwright from `tests/e2e/`: sign-up and approval, sessions and passwords, access control, no inline handlers, profile CSP, browser flows | Fails the run on a failing test |
| **Security scan** (`security-scan.yml`) | Pull request and push to `main`, every Monday 03:23 UTC, on demand | `composer audit` and Semgrep (`p/php`, `p/security-audit`, `p/owasp-top-ten`). Results go to the run summary; Semgrep's full report is kept as an artifact | No. Both steps are `continue-on-error` (report only) |
| **Live smoke test** (`smoke.yml`) | After a successful deployment, every 6 hours (minute 17), on demand | `tests/smoke/smoke.py`: read-only checks of `https://cycleaudit.parisar.org` (health, key pages open, signed-out visitors get no data, server files are not exposed, security headers, CSP rules, HTTP redirects to HTTPS) | No. It runs after the deploy; a failure is a signal to look |
| **Docker permissions** (`docker-permissions.yml`) | Pull requests that change `Dockerfile`, `docker-entrypoint.sh`, `.dockerignore` or this workflow; on demand | Builds the real image and checks that `www-data` cannot write to `config/`, the app root or the main PHP files, but can write to `logs/` and `reports/` | Fails the run on a violation |

Despite its name, `deploy.yml` does not deploy anything: Railway deploys on its own from GitHub.

**Dependabot** (`.github/dependabot.yml`) opens weekly pull requests for GitHub Actions and Composer packages (at most 5 Composer pull requests open at once). Merging one deploys it like any other change.

**Running the checks yourself**

```
composer test                                  # PHPUnit
python tests/smoke/smoke.py                    # live site, read-only
python tests/smoke/smoke.py http://127.0.0.1:8080   # another copy
```

The end-to-end steps are in `tests/e2e/README.md`. `seed.php` refuses to run unless the database name ends in `_test`.

## 7. Backups

The full procedure (Backblaze setup, Railway variables, changing the bucket, verifying, restoring, troubleshooting) is in [BACKUPS.md](../BACKUPS.md). What the script does, read from `scripts/backup.sh`:

1. `mysqldump --single-transaction --routines --triggers` of the database, piped through `gzip` into `/tmp/cycleaudit_<UTC timestamp>.sql.gz`.
2. Configures an `rclone` remote called `backupstore` entirely from `BACKUP_S3_*` variables (no config file; the "config file not found" line in the log is harmless).
3. Uploads the file, deletes the local copy.
4. Prunes: lists the bucket and deletes every file whose name carries a date older than `RETENTION_DAYS`. Pruning depends on the date in the **file name**, so nothing else should write into that bucket path.

The script stops at the first error (`set -euo pipefail`), so a run that prints "Backup job finished successfully." is a run in which the dump, upload and prune all worked. The 02:00 UTC (07:30 IST) schedule is a Railway cron setting (not verified from the repository).

**What nothing checks automatically:** that a backup can be restored. The workflows never read the bucket. A file listed in Backblaze proves an upload happened, not that the dump is complete. Practise a restore (section 8, runbook 5) into a scratch database from time to time.

## 8. Logs

| Where | What | Lifetime |
| --- | --- | --- |
| `/var/log/apache2/php_errors.log` | PHP errors and `error_log()` lines (including health check and database failures) | Inside the container: lost on redeploy |
| `/var/log/apache2/error.log`, `access.log` | Apache logs | Same |
| `logs/app.log` | JSON lines written by `appLog()` in `config/error_handler.php` (level, message, context) | Same. `logs/` is writable by the web server |
| Railway **Deploy Logs** | Output of the container's start-up (`Starting Apache on port ...`) and of the backup job | Kept by Railway |
| `activity_log` table | Who did what (sign-ins, passwords, projects, roads, downloads). Read it in the Admin's Activity Log page | Durable, in the database |

Nothing in the `Dockerfile` sends the PHP or Apache log files to the container's standard output, so PHP errors may not show in Deploy Logs (not verified). To read them, open the service's **Console** tab in Railway and look at those files. Because the container is replaced on every deploy, a problem you want to investigate is best looked at before you redeploy. Profile pictures and any file written inside the container are lost the same way; that is why pictures are stored in the database (README, known gaps).

## 9. Runbooks

**1. The site is down or shows an error.**
Open `/health.php`. If it answers `503` with `"database": "error"`: check that the MySQL service is running; check that the web service's `MYSQL*` variables still point at it and have no stray spaces (Raw Editor); remember that a missing user or password makes every page answer "Database unavailable" (section 3). Read `php_errors.log` for the real message. If the page itself will not load, look at the latest deployment's logs; Railway restarts a crashing container up to 3 times and then stops.

**2. A deploy broke something.**
`git revert -m 1 <merge commit>`, push, and wait for the deployment. If the break is a database change, do not revert the code first: work out whether the old code still works with the new column. Additive columns usually do ([database](database.md) section 7).

**3. A database change.**
Take a backup (**Run now** on `backup-cron`, and confirm a new file in the bucket). Run the statements one per query in Railway's MySQL **Data > Query** box. Then merge the code. After a change to tables, refresh `tests/e2e/schema.sql` with the command in `tests/e2e/README.md` and delete the `SET @@GLOBAL.GTID_PURGED` line.

**4. The backup job fails.**
Open the `backup-cron` Deploy Logs. `Unknown MySQL server host ... (-2)` is nearly always a leading or trailing space in a variable value: check the Raw Editor ([BACKUPS.md](../BACKUPS.md) troubleshooting has the debug line). An `rclone` error usually means a wrong key, bucket or endpoint. Fix, click **Run now**, and confirm a new file in the bucket.

**5. Restore from a backup.**
Steps are in [BACKUPS.md](../BACKUPS.md). A dump written by this script drops and re-creates each table when it is loaded (`mysqldump` adds `DROP TABLE IF EXISTS` by default), so loading it into the live database **replaces** the current data. Load it into a scratch database first, check the numbers, and only then decide whether to restore production.

**6. An Admin is locked out and no second Admin can reset them.**
Run `php scripts/make_admin_reset_sql.php <admin email> '<temporary password>'` on a computer with the repository (single quotes in PowerShell). It prints one SQL statement; paste it into Railway's query box. The person signs in with the temporary password and must choose their own. Needs migration 022 (`users.must_change_password`). The temporary password must satisfy the password rules.

**7. Someone is locked out by the rate limiter.**
Counters are in `rate_limit_attempts`, keyed by IP address and action (`login`, `register`, `password_request`, `reset_token`, `change_password`). The lock lasts about 15 minutes. Deleting the row for that IP and action lifts it at once (not tested live). See [auth and permissions](auth-and-permissions.md) section 5, which also explains why a 24-hour lock probably never happens.

**8. Browser shows "Content Security Policy" errors after a deploy.**
Look for a new inline `<script>` or `<style>` without the nonce, or an `onclick=` attribute. Follow [frontend](frontend.md) sections 2 and 12.

**9. Rotating a secret.**
The Backblaze key: create the new key restricted to the bucket, update the two `BACKUP_S3_*` key variables, **Run now**, confirm a file, then delete the old key. The database password: change it in Railway and make sure the web service and `backup-cron` both read the new value (they use Variable References). The `MAIL_*` values are unused today.

## 10. Known gaps and things that surprised me

- **CI runs PHP 8.3; production runs PHP 8.1.** `composer.json` pins the platform to 8.1.34, which protects the library choice, but PHP code that works on 8.3 and fails on 8.1 would pass CI. The image installs `php8.1` from Ubuntu 22.04.
- **The end-to-end tests do not exercise `.htaccess`.** They use PHP's built-in server, which ignores it. The headers and the home page policy that `.htaccess` sets are checked only by the smoke test against the live site, after a deploy.
- **No health check path in `railway.toml`**, and no restart-on-unhealthy rule beyond `ON_FAILURE` (section 2).
- **Logs disappear on redeploy** and PHP errors may not reach Railway's log view (section 8).
- **Nothing tests a restore** (section 7).
- **The image build is not pinned.** `ubuntu:22.04` is a moving tag, and `rclone` and Composer are installed with `curl ... | bash` and `curl ... | php` at build time. A rebuild next month may not produce the same image as today.
- **One replica, one region, one environment** in the dashboard: a deploy or a crash is a short outage for everyone.
- **`deploy.yml` is named "CI / CD" but only tests.** Deployment is Railway's own GitHub integration.
- **Line endings.** The copy of `scripts/backup.sh` in the zip I was given had Windows line endings (CRLF), which `bash` cannot run. The live job works, so the committed file is probably fine (a Windows checkout can add CRLF). A `.gitattributes` rule such as `*.sh text eol=lf` would keep it safe (not verified; the zip did not include `.gitattributes`).
- **`.dockerignore` was not in the zip.** `SECURITY.md` and the Docker workflow refer to it; I could not read it (not verified).
