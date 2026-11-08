<p align="center">
  <img src="assets/parisar-logo.png" alt="Parisar logo" height="90" />
</p>

<h1 align="center">CycleAudit</h1>

<p align="center">
  A field-auditing platform for <b>Parisar's cycle track assessment programme</b> in Pune.<br/>
  Surveyors walk a road, audit it segment by segment, and the platform scores its condition from 0 (best) to 100 (worst).
</p>

<p align="center">
  <a href="https://cycleaudit.parisar-pune.workers.dev/"><b>Live app</b></a>
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
- Email and password sign-up and sign-in, with an emailed one-time link to reset a forgotten password
- Role-based access control: Admin, City Leader, Surveyor
- Admin dashboard, road manager, surveyor manager (activate or deactivate accounts), activity log
- Excel exports of users and roads for admins

**Operations**
- IP and action based **rate limiting** with escalating lockouts
- Activity logging for key user actions
- `/health.php` endpoint for Railway health checks and uptime monitoring
- Automated daily database backups to Backblaze B2

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

**Cyclist slowed** uses a stricter scale on a configurable list of busy "Group B" roads (for example Karve Road and Sinhagad Road). The list lives in `config/constants.php`, so admins can change it without touching the scoring logic.

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
| Testing | PHPUnit 10 |
| CI | GitHub Actions (PHP lint + PHPUnit on PHP 8.3) |
| Container | Docker (Ubuntu 22.04, Apache 2, PHP 8.1) |
| Hosting | Railway, with Cloudflare in front |
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
├── migrations/              # SQL migrations 000 to 008
├── tests/                   # PHPUnit suites
├── scripts/backup.sh        # database backup job
├── Dockerfile, docker-entrypoint.sh, railway.toml
├── health.php               # health check endpoint
└── .github/workflows/       # CI
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
| Surveyor | `surveyor` | Create roads, audit any road, edit or delete only their own roads and segments |

Permissions live in `config/permissions.php` and fail closed: an endpoint that forgets to pass a city context grants a City Leader nothing extra.

## Local setup

**Prerequisites:** PHP 8.1+ with `pdo_mysql`, `gd`, `mbstring`, `xml`, `zip` and `curl`; MySQL 8; [Composer](https://getcomposer.org/). XAMPP works fine on Windows.

```bash
# 1. Clone
git clone https://github.com/NikhilWagh1018/Parisar.git
cd Parisar

# 2. Install PHP dependencies (including dev tools for tests)
composer install

# 3. Create the database
mysql -u root -p -e "CREATE DATABASE parisar_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Apply the schema, in order
mysql -u root -p parisar_db < migrations/000_initial_schema.sql
for f in migrations/00[1-8]*.sql; do mysql -u root -p parisar_db < "$f"; done
```

Then place the folder where your web server can serve it (for XAMPP, `C:\xampp\htdocs\Parisar`) and open `http://localhost/Parisar/`.

> **Heads up:** see [Known gaps](#known-gaps-and-roadmap). The migrations do not yet create every table and column the code uses, so a fresh install from migrations alone will be incomplete.

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

There are 139 test methods across 7 suites, covering the scoring engine and its helpers, segment repository, input validation, and front-end form-state and toast logic. GitHub Actions runs a PHP syntax lint over every `.php` file and the full PHPUnit suite on each push and pull request to `main`.

## Deployment

CycleAudit is containerised and runs on **Railway**, with a managed MySQL service and Cloudflare in front.

- `Dockerfile` builds an Ubuntu 22.04 image with Apache, PHP 8.1, Composer dependencies and `rclone`. It sets hardened PHP options (errors logged not displayed, `expose_php` off, secure session cookie flags).
- `docker-entrypoint.sh` reads Railway's `PORT` and points Apache at it.
- `railway.toml` selects the Dockerfile builder and restarts on failure (up to 3 times).
- `health.php` returns `200` when the database responds and `503` when it does not.
- Sessions are HTTPS-aware behind the proxy (`X-Forwarded-Proto`), and rate limiting reads the real visitor IP from Cloudflare's `CF-Connecting-IP` header, but only when the request really comes from a Cloudflare range.

**To deploy your own copy:**

1. Create a Railway project, add a MySQL service, and connect this repository.
2. Set `BASE_URL` on the web service.
3. Run the SQL migrations against the Railway MySQL instance.
4. Point a health check at `/health.php`.

## Backups

A separate `backup-cron` Railway service runs `scripts/backup.sh` daily at 02:00 UTC: `mysqldump`, then `gzip`, then upload to a private Backblaze B2 bucket with `rclone`. Files older than `RETENTION_DAYS` (default 7) are pruned. Full setup, verification and restore steps are in [BACKUPS.md](./BACKUPS.md).

## Security notes

- All SQL uses PDO prepared statements with emulation off
- Secure session bootstrap: `HttpOnly`, `SameSite=Lax`, `Secure` when on HTTPS, strict mode, 8 hour lifetime
- Login and registration are rate limited per IP and action (5 failures in 15 minutes triggers a 15 minute lock that doubles on repeat, capped at 24 hours)
- Guards re-check the user's role and active status from the database on every request, not just from the session
- Errors are logged server-side and never shown to the browser
- Public identifiers hide internal IDs

Found a vulnerability? Please open a private security advisory on GitHub rather than a public issue.

## Known gaps and roadmap

**Known gaps**
- [ ] **Migrations lag behind the code.** The code uses tables and columns that no migration creates: `cities`, `road_groups`, `audit_log`, `rate_limit_attempts`, `users.city_id`, `users.is_active`, the `admin` and `city_leader` roles, and `segment_audits.cycle_track_missing` / `missing_length`. Production has them; a fresh install does not. A consolidating migration is the next fix.
- [ ] Profile pictures are stored as base64 in MySQL because Railway's filesystem is ephemeral. Object storage would be better.

**Roadmap**
- [ ] Image upload for audits (Cloudflare R2)
- [ ] Analytics dashboard
- [ ] Screenshots and a demo walkthrough in this README

## Author

**Nikhil Wagh**, final-year Computer Engineering student from Pune, and Web Developer Intern at Parisar.

[LinkedIn](https://www.linkedin.com/in/nikhil-wagh-8716003a2) · [Email](mailto:waghnikhil1018@gmail.com)

<!-- deploy check after repo transfer -->
