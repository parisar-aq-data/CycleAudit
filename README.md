<p align="center">
  <img src="assets/parisar-logo.png" alt="Parisar logo" height="90" />
</p>

<h1 align="center">CycleAudit</h1>

<p align="center">
  A field-auditing platform for <b>Parisar's cycle track assessment programme</b> in Pune.<br/>
  Surveyors walk a road, audit it segment by segment, and the platform scores its condition from 0 (best) to 100 (worst).
</p>

<p align="center">
  <a href="https://cycleaudit.parisar.org/"><b>Live app</b></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL" />
  <img src="https://img.shields.io/badge/JavaScript-323330?style=for-the-badge&logo=javascript&logoColor=F7DF1E" alt="JavaScript" />
  <img src="https://img.shields.io/badge/Docker-2496ED?style=for-the-badge&logo=docker&logoColor=white" alt="Docker" />
  <img src="https://img.shields.io/badge/Railway-0B0D0E?style=for-the-badge&logo=railway&logoColor=white" alt="Railway" />
  <img src="https://img.shields.io/badge/GitHub_Actions-2088FF?style=for-the-badge&logo=githubactions&logoColor=white" alt="GitHub Actions" />
</p>

---

## Table of contents

1. [What it does](#what-it-does)
2. [Features](#features)
3. [How scoring works](#how-scoring-works)
4. [Tech stack](#tech-stack)
5. [Project structure](#project-structure)
6. [Data model](#data-model)
7. [Roles and permissions](#roles-and-permissions)
8. [Local setup](#local-setup)
9. [Configuration](#configuration)
10. [Testing and CI](#testing-and-ci)
11. [Deployment](#deployment)
12. [Backups](#backups)
13. [Security notes](#security-notes)
14. [Known gaps and roadmap](#known-gaps-and-roadmap)
15. [Author](#author)

---

## What it does

Parisar audits the quality of cycling infrastructure across Pune. CycleAudit replaces paper forms with a mobile-friendly web app used by volunteers in the field.

1. A surveyor **defines a road** (start, end, GPS points, total length) and splits it into segments, either automatically at a fixed length or by hand.
2. For each segment, they fill in an **audit form**: surface, shade, lighting, buffer zone, obstructions, and every intersection along it.
3. The **scoring engine** turns those observations into Safety, Comfort and Continuity scores and one final 0 to 100 score with a condition label (Good to Very Bad).
4. Results roll up into road-level scores and **PDF and Excel reports**.

## Features

**Auditing**
- Road creation with automatic or manual segmentation
- Per-segment audit form with autosave, toast messages and loading states
- Obstruction records (fixed, movable, parked) and intersection records (ramps, markings, signage, traffic calming)
- Edit and re-open completed segments, then explicitly **finalise** a road so it becomes read-only
- Sessions auto-complete when the last segment of a road is submitted

**Results and reporting**
- Segment score and length-weighted road score
- Road result page and printable report
- **PDF** export (mPDF) and **Excel** export (PhpSpreadsheet)

**Engagement**
- Audit streaks in IST with a one-day grace period, so a streak survives until a full day is missed
- Public landing page with live stats

**Accounts and administration**
- Email and password sign-up. New accounts are **held for approval** by a City Leader or Admin before they can sign in
- Password help: an emailed one-time reset link, a "forgot password" request that goes to the surveyor's team leader, and a **Reset password** button for Admins and City Leaders
- Role-based access control: Admin, City Leader, Surveyor
- Admin dashboard, road manager, surveyor manager (approve, activate or deactivate accounts), activity log
- Excel exports of users and roads for admins

**Operations**
- IP and action based **rate limiting** with escalating lockouts
- Activity logging for key user actions
- `/health.php` endpoint for Railway health checks and uptime monitoring
- Automated daily database backups to Backblaze B2, owned by Parisar

## How scoring works

Every segment is scored from **0 (best) to 100 (worst)** across three categories. Each category is the average of its parameters, each of which scores 0 to 100.

| Category | Weight | Parameters |
| --- | --- | --- |
| **Safety** | 1.00 | Buffer zone / segregation, light after dark, traffic calming at intersections, partial obstructions |
| **Comfort** | 1.25 | Track surface, times a cyclist was slowed, shade |
| **Continuity** | 1.50 | Missing ramps, missing signage and markings, total obstructions |

```
final = (safety x 1.00 + comfort x 1.25 + continuity x 1.50) / 3.75
```

**Missing track.** If part of the segment has no cycle track, that length counts as 100 and the audited part is scored normally, weighted by length. If the whole segment is missing, every category is 100.

**Cyclist slowed** uses one scale for every road: under 5 scores 0, 5 to 10 scores 50, 10 to 20 scores 75, and over 20 scores 100.

**Condition bands**

| Score | Condition |
| --- | --- |
| 0 to 20 | Good |
| 21 to 40 | OK |
| 41 to 60 | Poor |
| 61 to 80 | Bad |
| 81 to 100 | Very Bad |

Road scores are **length-weighted** across their segments and are computed with three batch queries per road, no matter how many segments it has. The scoring code is pure PHP (`_computeScoreFromData`) with no database access, which is why it is heavily unit tested.

## Tech stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.1+ (strict types), PDO, no framework |
| Database | MySQL 8 (utf8mb4, InnoDB) |
| Frontend | Vanilla JavaScript, HTML, CSS |
| Reports | mPDF (PDF), PhpSpreadsheet (Excel) |
| Auth | PHP sessions, `password_hash` / `password_verify` |
| Config | `vlucas/phpdotenv` for local `.env`, environment variables in production |
| Testing | PHPUnit 10, pytest + Playwright (end-to-end) |
| CI | GitHub Actions (lint, PHPUnit, end-to-end tests, dependency scan, live smoke test) |
| Container | Docker (Ubuntu 22.04, Apache 2, PHP 8.1) |
| Hosting | Railway |
| Backups | `mysqldump`, `rclone`, Backblaze B2 |

## Project structure

```
Parisar/
├── index.html               # public landing page
├── pages/                   # server-rendered app pages
│   ├── dashboard.php        #   surveyor dashboard
│   ├── form.php, segment.php#   audit workflow
│   ├── view.php, report.php, road_result.php, profile.php
│   └── admin*.php           #   admin dashboard, surveyors, activity log
├── api/                     # JSON endpoints, grouped by domain
│   ├── roads/, segments/, audit-sessions/
│   ├── reports/             #   PDF and Excel export
│   ├── user/, admin/, dashboard/, public/
├── auth/                    # login, register, logout
├── config/                  # db, constants, guards, permissions, rate limiting, error handler
├── services/                # ScoreService, ScoreHelpers, IdGenerator
├── repositories/            # Road, Segment, AuditSession data access
├── helpers/                 # Validator, StreakCalculator, ActivityLogger
├── js/                      # front-end modules (form-state, toast, loading-state, ...)
├── css/                     # stylesheets
├── migrations/              # SQL migrations 000 to 032
├── tests/                   # PHPUnit suites
│   ├── e2e/                 #   end-to-end tests (pytest, Playwright) and schema.sql
│   └── smoke/               #   read-only checks of the live site
├── scripts/backup.sh        # database backup job
├── Dockerfile, docker-entrypoint.sh, railway.toml
├── health.php               # health check endpoint
└── .github/workflows/       # CI, security scan, smoke test
```

The code follows a simple layering: **pages and API endpoints** handle HTTP, **repositories** run SQL, **services** hold business rules (scoring), and **config** provides guards and shared infrastructure.

## Data model

Core tables created by `migrations/000_initial_schema.sql`:

| Table | Purpose |
| --- | --- |
| `users` | Accounts, role, profile picture |
| `roads` | A road to audit: name (unique), endpoints, GPS, length, segmentation method, `finalized_at` |
| `segments` | Slices of a road with distances and a pending or completed status |
| `audit_sessions` | One surveyor's audit run over a road (active, completed, abandoned) |
| `segment_audits` | The audit form for one segment: surface, shade, lighting, buffer, width, footpath, comments |
| `obstructions` | Fixed, movable or parked obstructions attached to an audit |
| `intersections` | Ramps, markings, signage and traffic calming at each intersection |

Supporting tables: `login_attempts` and `activity_log` (migrations 001 and 002). Public-facing identifiers use short `public_id` values so internal auto-increment IDs are never exposed.

## Roles and permissions

| Role (shown in the app) | Stored value | Can do |
| --- | --- | --- |
| Admin | `admin` | Everything, across all cities |
| City Leader | `city_leader` | Everything an Admin can, but only for resources in their own city |
| Surveyor | `surveyor` | Audit the segments a City Leader assigns. Cannot create or delete roads |

Self-registered accounts start inactive and `pending`, and cannot sign in until a City Leader (or an Admin) approves them. Permissions live in `config/permissions.php` and fail closed: an endpoint that forgets to pass a city context grants a City Leader nothing extra.

## Local setup

**Prerequisites:** PHP 8.1+ with `pdo_mysql`, `gd`, `mbstring`, `xml`, `zip` and `curl`; MySQL 8; [Composer](https://getcomposer.org/). XAMPP works fine on Windows.

```bash
# 1. Clone
git clone https://github.com/parisar-aq-data/CycleAudit.git
cd CycleAudit

# 2. Install PHP dependencies (including dev tools for tests)
composer install

# 3. Create the database
mysql -u root -p -e "CREATE DATABASE parisar_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Load the full table definitions (a schema-only copy of the live database)
mysql -u root -p parisar_db < tests/e2e/schema.sql
```

Then place the folder where your web server can serve it (for XAMPP, `C:\xampp\htdocs\Parisar`) and open `http://localhost/Parisar/`. The folder name only matters for the URL, so a clone named `CycleAudit` works too if you adjust `BASE_URL`.

> **Why not the migrations?** `migrations/` is the history of changes to the live database, applied by hand in Railway's query box. It does not yet create every table, so a fresh install should start from `tests/e2e/schema.sql`. See [Known gaps](#known-gaps-and-roadmap).

## Configuration

Settings are read from environment variables first, then a local `.env` (via phpdotenv), then localhost defaults. **Never commit real values.**

| Variable | Purpose | Default |
| --- | --- | --- |
| `MYSQLHOST` / `DB_HOST` | Database host | `localhost` |
| `MYSQLPORT` / `DB_PORT` | Database port | `3306` |
| `MYSQLDATABASE` / `DB_NAME` | Database name | `parisar_db` |
| `MYSQLUSER` / `DB_USER` | Database user | `root` |
| `MYSQLPASSWORD` / `DB_PASS` | Database password | empty |
| `BASE_URL` | Public URL of the app (also used in the password reset link) | `http://localhost/Parisar` |
| `MAIL_PROVIDER` | Email service for password reset: `brevo` or `resend` | empty (reset emails are not sent) |
| `MAIL_API_KEY` | The email service's API key | empty |
| `MAIL_FROM` | Sender address, verified with the email service | empty |
| `MAIL_FROM_NAME` | Sender name | `CycleAudit` |

The `MYSQL*` names are the ones Railway injects, so the same code runs locally and in production.

`env.example` documents the variables used by the backup job (see [Backups](#backups)).

## Testing and CI

```bash
composer test          # runs phpunit --testdox
```

There are more than 580 PHPUnit test methods across 47 files. They cover scoring, validation, roles and city scoping, approvals, password reset, audit review and reports, and the front-end form logic. The end-to-end suite in `tests/e2e/` starts from an empty throwaway database and drives a running copy of the app over HTTP and in Chromium. Its own guide is `tests/e2e/README.md`.

| Workflow | When it runs | What it checks |
| --- | --- | --- |
| **CI / CD** (`deploy.yml`) | every push and pull request to `main` | PHP lint, PHPUnit, dependency audit |
| **End-to-end tests** (`e2e.yml`) | every pull request | sign-up and approval, sessions and passwords, access control, sign-in page security headers |
| **Security scan** (`security-scan.yml`) | weekly (Monday 03:23 UTC) | known vulnerabilities in PHP libraries |
| **Live smoke test** (`smoke.yml`) | every 6 hours and after each deploy | the live site responds |
| **Docker permissions** | when the Docker setup changes | the web server cannot modify the code |

Dependabot (`.github/dependabot.yml`) opens pull requests for outdated libraries.

## Deployment

CycleAudit is containerised and runs on **Railway**, with a managed MySQL service.

- `Dockerfile` builds an Ubuntu 22.04 image with Apache, PHP 8.1, Composer dependencies and `rclone`. It sets hardened PHP options (errors logged not displayed, `expose_php` off, secure session cookie flags).
- `docker-entrypoint.sh` reads Railway's `PORT` and points Apache at it.
- `railway.toml` selects the Dockerfile builder and restarts on failure (up to 3 times).
- `health.php` returns `200` when the database responds and `503` when it does not.
- `.htaccess` returns 403 for server-side folders and project files (`config/`, `migrations/`, `scripts/`, `composer.lock`, `Dockerfile` and similar), because the Docker image copies the whole project into the web folder.
- Sessions are HTTPS-aware behind the proxy (`X-Forwarded-Proto`), and rate limiting reads the real visitor IP from `X-Forwarded-For`, but only when the request really comes from Railway's proxy range.

**To deploy your own copy:**

1. Create a Railway project, add a MySQL service, and connect this repository.
2. Set `BASE_URL` on the web service.
3. Load `tests/e2e/schema.sql` into the Railway MySQL instance, then run any later migration by hand, one statement per query.
4. Point a health check at `/health.php`.

## Backups

A separate `backup-cron` Railway service runs `scripts/backup.sh` daily at 02:00 UTC (07:30 IST): `mysqldump`, then `gzip`, then upload to a private bucket in **Parisar's own Backblaze B2 account** with `rclone`. Files older than `RETENTION_DAYS` (default 7) are pruned. Full setup, verification and restore steps are in [BACKUPS.md](./BACKUPS.md).

## Security notes

The full list, with how to report a problem, is in [SECURITY.md](./SECURITY.md). In short:

- All SQL uses PDO prepared statements with emulation off
- Secure sessions: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, strict mode, 8 hour lifetime. Changing a password ends the user's other sessions
- Content-Security-Policy on every HTML page: scripts run only from this site or with a fresh per-request nonce, and inline `<script>` and inline event handlers are not allowed
- Browser hardening headers (`nosniff`, frame protection, referrer policy, permissions policy, HSTS)
- Login and registration are rate limited per IP and action (5 failures in 15 minutes triggers a 15 minute lock that doubles on repeat, capped at 24 hours)
- CSRF protection on write requests; sign-in responses do not reveal whether an account exists
- New accounts need approval, and guards re-check the user's role, active status and approval from the database on every request
- Errors are logged server-side and never shown to the browser
- Public identifiers hide internal IDs

Found a vulnerability? Please open a private security advisory on GitHub rather than a public issue.

## Known gaps and roadmap

**Known gaps**
- [ ] **Migrations lag behind the code.** No migration creates `cities`, `road_groups`, `audit_log` or `rate_limit_attempts`, and several columns exist only in production. `tests/e2e/schema.sql` is the complete, current picture, so use it for fresh installs. A consolidating migration is still to do.
- [ ] Profile pictures are stored as base64 in MySQL because Railway's filesystem is ephemeral. Object storage would be better.
- [ ] `style-src` still allows `'unsafe-inline'` because pages use inline `style` attributes. Removing it is a larger clean-up.
- [ ] `index.html` (the public landing page) still has a few inline handlers. It sends no policy header, so nothing breaks, but it would need tidying before one is added.
- [ ] The minimum password length is 8 characters. Raising it to 10 is under consideration.

**Roadmap**
- [ ] Image upload for audits
- [ ] Analytics dashboard
- [ ] Screenshots and a demo walkthrough in this README

## Author

**Nikhil Wagh**, final-year Computer Engineering student from Pune, and Web Developer Intern at Parisar.

[LinkedIn](https://www.linkedin.com/in/nikhil-wagh-8716003a2) · [Email](mailto:waghnikhil1018@gmail.com)

<!-- deploy check after repo transfer -->
