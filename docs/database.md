# Database reference

This chapter describes the MySQL database behind CycleAudit: where its definition lives, what each table is for, how the tables relate, which status values exist and which code sets them, and how to change the schema safely.

Related chapters: [workflows](workflows.md) (how the tables are used by the business flow), [auth and permissions](auth-and-permissions.md), [API reference](api-reference.md), [scoring](scoring.md).

Everything here was checked against `tests/e2e/schema.sql`, `migrations/` and the PHP code. Where something could not be proved from the repository it is marked "(not verified)".

## 1. Where the schema comes from

There are two sources, and they do not fully agree.

| Source | What it is | Use it for |
| --- | --- | --- |
| `tests/e2e/schema.sql` | A schema-only dump of the live database (21 tables, no data, no triggers). Refreshed by hand after migrations that change tables (see `tests/e2e/README.md`). | Fresh installs, local development, the end-to-end tests. This is the most complete and current picture. |
| `migrations/000_...sql` to `032_...sql` | 33 numbered SQL files, the history of changes to the live database. Applied by hand in Railway's query box, one statement per query. | Understanding why a column exists and how the live database got its shape. Not enough on its own to build a database from scratch. |

### Tables that no migration creates

These tables exist in `tests/e2e/schema.sql` but no file in `migrations/` contains a `CREATE TABLE` for them:

| Table | Notes |
| --- | --- |
| `cities` | Referenced (not created) by migrations 009 to 011. |
| `road_groups` | Migration 009 says it "predates migration tracking"; 009 and 010 only alter it. |
| `audit_log` | Written by `api/admin/roads.php`; read by the activity log screen. |
| `rate_limit_attempts` | Used by `config/rate_limit.php`. |
| `oauth_states` | No PHP code reads or writes it (see section 6). |
| `road_assignments` | No PHP code reads or writes it (see section 6). |

Tables that migrations create: `users`, `roads`, `segments`, `audit_sessions`, `segment_audits`, `obstructions`, `intersections` (all in `000_initial_schema.sql`), `login_attempts` (001), `activity_log` (002), `city_audits` (011), `segment_assignments` (012), `notifications` (017), `password_resets` (019), `past_audits` (024), `password_requests` (026), `collaborators` (028).

`login_attempts` is created by migration 001 but is not in `tests/e2e/schema.sql` and no PHP file references it. The code uses `rate_limit_attempts` instead.

**What this means for a fresh install:** load `tests/e2e/schema.sql`, not the migrations. Running 000 to 032 on an empty database would leave out the six tables above and would not match the live column set (several columns exist only in production). The README lists a consolidating migration as still to do.

### Conventions seen in the schema

- Engine InnoDB, character set `utf8mb4`. Collation is `utf8mb4_0900_ai_ci` on most tables and `utf8mb4_unicode_ci` on newer ones (`activity_log`, `notifications`, `password_requests`, `password_resets`, `rate_limit_attempts`). Mixed collations matter if you ever join text columns across these tables (not verified in practice).
- Timestamps are `datetime` or `timestamp`. The dump sets the session time zone to `+00:00`; the app shows streaks in IST (see [workflows](workflows.md)).
- Surrogate integer `id` primary keys everywhere.

## 2. Public IDs

Internal `id` values are never shown to users. Tables exposed in URLs or screens carry a `public_id` column with a unique key:

| Table | Format | Set by |
| --- | --- | --- |
| `users` | `USR-0001` | `auth/register.php` (`generatePublicId('USR', $newId)`), after the INSERT |
| `roads` | `ROAD-0042` | `RoadRepository` and `CityAuditRepository`, after the INSERT |
| `audit_sessions` | `AUD-0007` | `RoadRepository` (around line 404) |
| `segments` | `SEG-0123` | `CityAuditRepository` and `api/roads/segments/save.php` |
| `segment_audits` | `SA-0010` | `api/segments/submit.php` |
| `obstructions` | `OBS-0010` | `api/segments/submit.php` |
| `intersections` | `INT-0010` | `api/segments/submit.php` |

The pattern is the prefix, a hyphen, and the integer id padded to at least 4 digits.

Because `public_id` has a unique key and the id is only known after the INSERT, the code inserts a temporary value first and then updates it. For segments the temporary value is `SEG-R<roadId>N<segmentNumber>`.

Migration 021 changed existing `users` rows from the old `SURV-` prefix to `USR-` so every account has one format whatever its role.

**Surprise:** the header comment of `services/IdGenerator.php` says database `AFTER INSERT` triggers generate the same IDs. No trigger exists anywhere in the repository (a search for `TRIGGER` finds nothing), and `auth/register.php` says "no trigger needed". The PHP code is the only thing that fills `public_id`. If you write a new INSERT, you must set `public_id` yourself or the unique key will collide on the default empty string `''` for the second row.

## 3. Relationships

```
cities
  |-- users.city_id                       (nullable; admins have no city)
  |-- road_groups.city_id                 (the real-world road, unique per city by name)
  |-- city_audits.city_id                 (a "project": one audit programme in one city)

city_audits
  |-- roads.audit_id                      (the roads included in this audit)
  |-- segment_assignments.audit_id

road_groups
  |-- roads.road_group_id                 (ON DELETE SET NULL)

roads
  |-- segments.road_id                    (ON DELETE CASCADE)
  |-- audit_sessions.road_id              (ON DELETE CASCADE)

audit_sessions
  |-- segment_audits.session_id           (ON DELETE CASCADE)

segments
  |-- segment_audits.segment_id           (ON DELETE CASCADE)
  |-- segment_assignments.segment_id      (ON DELETE CASCADE; unique: one assignee per segment)

segment_audits
  |-- obstructions.audit_id               (ON DELETE CASCADE)
  |-- intersections.audit_id              (ON DELETE CASCADE)

users
  |-- users.team_leader_id                (a surveyor's City Leader; no foreign key defined)
  |-- notifications.user_id               (ON DELETE CASCADE)
  |-- city_audits.created_by / leader_user_id
  |-- road_groups.assigned_surveyor_id / assigned_by   (ON DELETE SET NULL)
  |-- segment_assignments.surveyor_id / assigned_by
```

Notes:

- Several foreign keys are only implied by column names (for example `segment_audits.surveyor_id`, `audit_sessions.user_id`, `city_audits.leader_user_id`, `users.team_leader_id`). The dump defines no constraint for them, so the database will not stop a dangling value.
- Deleting a road cascades to its segments, sessions, audits, obstructions and intersections. The app avoids real deletes for whole audits: an Admin delete marks the audit `voided` (section 5) and keeps the data.

## 4. Tables

For each table: purpose, columns of interest, keys, and the main PHP files that touch it. File lists come from searching for the table name in SQL; they show where to look first and may not be exhaustive.

### 4.1 `users`

An account. Primary key `id`; unique `email`, unique `public_id` is not defined on this table (see the note in section 2: users have a `public_id` column without a unique key in the dump).

| Column | Type / default | Meaning |
| --- | --- | --- |
| `public_id` | varchar(20), `''` | `USR-NNNN` |
| `name`, `email` | varchar | Email is unique (`uq_users_email`). |
| `phone`, `organisation`, `gender`, `age`, `address` | nullable | Profile fields. `gender` enum Male, Female, Other. |
| `password` | varchar(255), nullable | `password_hash` output. |
| `must_change_password` | tinyint, 0 | Set by temporary-password flows; forces a change at next sign-in (migration 022). |
| `password_changed_at` | datetime | Used to end other sessions after a change. |
| `role` | enum `surveyor`, `city_leader`, `admin`; default `surveyor` | See section 5. |
| `city_id` | int, nullable | FK to `cities`. Admins have none. |
| `team_leader_id` | int, nullable | The City Leader whose team this surveyor is in (migration 027). |
| `is_active` | tinyint, 1 | Inactive accounts cannot sign in. |
| `approval_status` | varchar(10), `approved` | `pending`, `approved`, `rejected` (migration 032). |
| `auth_provider`, `google_id`, `email_verified` | enum local/google, varchar, tinyint | Google sign-in columns. Code only ever inserts `local`; no PHP reads `google_id` (grep). |
| `profile_picture` | longtext | Base64 image stored in the database (Railway's disk is ephemeral). |
| `last_login`, `created_at`, `updated_at` | datetime | |
| `home_city`, `home_state` | varchar(100) | Free-text home location (migration 020). |
| `removal_pending_at`, `removal_action`, `removal_requested_by` | datetime, varchar(12), int | A surveyor being removed who still has started work (migration 031). `removal_action` is `team` or `deactivate` (`helpers/SurveyorRemoval.php`). |

Keys: `uq_users_email`, `idx_users_city_id`, `idx_users_team_leader`, FK `fk_users_city` to `cities`.
Touched by: `auth/`, `config/auth_guard.php`, `helpers/Registration.php`, `PasswordReset.php`, `PasswordRequest.php`, `StaffPasswordReset.php`, `TeamRoster.php`, `PrimaryAdmin.php`, `api/admin/surveyors.php`, `api/admin/city_leader.php`, `api/admin/team.php`, `api/user/profile.php`.

### 4.2 `cities`

`id`, `name`, `slug` (unique, `uniq_cities_slug`), `created_at`. Read by `helpers/Cities.php`, `CityScope.php`, dashboards and public stats.

### 4.3 `road_groups`

The real-world road in a city ("canonical" road), as opposed to `roads`, which are audit rows.

| Column | Meaning |
| --- | --- |
| `canonical_name`, `city_id` | Unique together (`uq_road_groups_city_name`, migration 010). |
| `assigned_surveyor_id`, `assigned_at`, `assigned_by` | Older whole-road assignment (migration 009). FKs `ON DELETE SET NULL`. Whether the current UI still writes these is not verified. |
| `is_verified` | 0 on creation (`RoadRepository`); exported by `api/admin/export-roads.php`. |
| `is_flagged` | When 1, the road group is hidden from the "available roads" list for new audits (`CityAuditRepository`). |

Touched by: `api/admin/roads.php`, `api/roads/groups.php`, `api/public/roads.php`, `repositories/RoadRepository.php`, `CityAuditRepository.php`, `AdminDashboardRepository.php`.

### 4.4 `city_audits`

A "project": one audit programme (a name and a year) in one city. This is the entity that moves through the review lifecycle.

| Column | Meaning |
| --- | --- |
| `city_id`, `state`, `name`, `audit_year`, `audit_date`, `programme_info` | Descriptive fields. Unique together: `city_id`, `audit_year`, `name` (`uq_city_audit_name`). |
| `status` | Lifecycle, section 5. Default `draft`. |
| `created_by`, `leader_user_id` | Creator and the City Leader who runs the audit (migration 023). |
| `admin_note`, `admin_decided_at`, `admin_decided_by` | The Admin's decision on a submitted audit (migration 014). |
| `public_hidden_at` | When set on a published audit, it is hidden from the public home page (migration 025). |

Keys: FK to `cities` and to `users` (creator). Touched by `repositories/CityAuditRepository.php`, `AuditReviewRepository.php`, `CityDashboardRepository.php`, `AdminDashboardRepository.php`, `PublicAuditRepository.php`, `api/city/*`, `api/admin/audit_*.php`.

### 4.5 `roads`

One road inside one audit.

| Column | Meaning |
| --- | --- |
| `creator_id`, `name`, `public_id` | `name` is not unique in this dump. |
| `road_group_id` | FK to `road_groups`, `ON DELETE SET NULL`. |
| `audit_id` | FK to `city_audits`. Unique together with `road_group_id` (`uq_roads_audit_group`): a road group appears once per audit. |
| `is_verified` | tinyint, 0. |
| `start_point`, `end_point`, `gps_start`, `gps_end` | Optional descriptive fields. The Define Road page no longer asks for them (memory of the redesign is not in the repo; treat as legacy, not verified). |
| `total_length` | decimal(8,2), metres. |
| `segment_method` | enum `auto`, `manual`, default `auto`. |
| `segment_length` | decimal(8,2), the target length when segmenting automatically. |
| `finalized_at` | Set when a road is finalised; the road becomes read-only (migration 007). |

Touched by: `repositories/RoadRepository.php`, `CityAuditRepository.php`, `SegmentRepository.php`, `services/ScoreService.php`, `api/roads/*`, `pages/form.php`, `report.php`, `road_result.php`.

### 4.6 `segments`

A slice of a road with its length and audit status.

| Column | Meaning |
| --- | --- |
| `road_id`, `segment_number` | Position on the road. |
| `start_label`, `end_label` | Optional labels. |
| `start_distance`, `end_distance`, `length` | Metres along the road. |
| `status` | enum `pending`, `in_progress`, `completed`. Code writes only `pending` and `completed` (grep finds no write of `in_progress`). |
| `completed_at` | Set when completed. |

FK `fk_segments_road` cascades. Touched by `SegmentRepository`, `AuditSessionRepository`, `CityAuditRepository`, `AuditReviewRepository`, `api/segments/*`, `api/roads/segments/*`.

### 4.7 `audit_sessions`

One surveyor's run over one road.

| Column | Meaning |
| --- | --- |
| `user_id`, `road_id` | Who and where. FK on `road_id` cascades; `user_id` has no FK. |
| `status` | enum `active`, `completed`, `archived`; default `active`. |
| `started_at`, `completed_at` | |

Note the README used to say the third value is "abandoned". The schema says `archived`, and `api/admin/audit_delete.php` sets it. Created by `RoadRepository` (around line 399); completed by `AuditSessionRepository` (around line 161); reopened by `AuditReviewRepository::reopenLatestSession`.

### 4.8 `segment_audits`

The saved audit form for one segment.

Fixed columns: `session_id`, `segment_id`, `surveyor_id`, `start_landmark`, `end_landmark`, `gps_start`, `gps_end` (all required), then the observations:

| Column | Type | Used for |
| --- | --- | --- |
| `cycle_track_missing` | enum Yes/No | Missing-track handling (see [scoring](scoring.md)) |
| `missing_length` | decimal(8,2) | Length with no track |
| `cyclist_use`, `people_walking` | enum Yes/No | Recorded observations |
| `better_surface` | enum Cycle Track / Road | Recorded observation |
| `surface_material` | enum Interlock Blocks / Concrete / Asphalt | Surface scoring |
| `signage_count` | smallint | |
| `shade`, `light_after_sunset` | enum Yes/No/Partial | Comfort and safety parameters |
| `track_geometry` | enum Road Level / Footpath Level / Segregated from FP&R / NA | |
| `buffer_zone` | enum Segregated / Buffer Zone / None / NA | Safety parameter |
| `surface_issues`, `overhead_issues`, `footpath_rating` | longtext | JSON-like text (format not verified here) |
| `footpath_score` | tinyint, 0 | |
| `segment_width` | decimal(6,2) | |
| `comments` | text | |

Keys: unique `public_id`; indexes on segment, session, surveyor; FKs to `segments` and `audit_sessions`, both cascading. Migration 008 dropped an old `segment_length` column.

### 4.9 `obstructions` and `intersections`

Child rows of `segment_audits` (FK `audit_id`, cascade).

`obstructions`: `obstruction_category` enum `fixed`, `movable`, `parked`; `obstruction_type` free text; `cyclist_slowed`, `partial_obstructions`, `total_obstructions` counts.

`intersections`: `intersection_num`, `gps_coords`, `landmark_name`, `off_ramp`/`on_ramp` (Comfortable, Uncomfortable, No Ramp), `markings`/`signage`/`traffic_calming` (Present, Absent), `discontinuity`/`tapering` (Yes, No, NA), `obstruction_type` (Partial, Total, None).

Both are written by `api/segments/submit.php` and read by `ScoreService`, `AuditReportRepository`, the report and export code.

### 4.10 `segment_assignments`

Which surveyor is responsible for which segment, and the review state.

| Column | Meaning |
| --- | --- |
| `audit_id`, `segment_id` | `segment_id` is unique (`uq_segment_assignment_segment`): a segment has at most one assignment. |
| `surveyor_id`, `assigned_by`, `assigned_at` | |
| `status` | enum `assigned`, `submitted`, `needs_revisit`, `approved`; default `assigned`. |
| `submitted_at`, `reviewed_at`, `reviewed_by`, `review_note` | Review trail. |

Touched by `SurveyorWorkRepository`, `AuditReviewRepository`, `CityAuditRepository`, `CityDashboardRepository`, `SurveyorRemovalRepository`, `helpers/SidebarCounts.php`.

### 4.11 `road_assignments`

Many-to-many road/segment assignment (`road_id`, nullable `segment_id`, `user_id`, `assigned_by`, status `active`, `completed`, `revoked`). No PHP code uses it; `segment_assignments` is the live mechanism. Treat as unused (grep over PHP, JS and tests shows no reference outside `schema.sql`).

### 4.12 `notifications`

In-app messages: `user_id` (FK, cascade), `type` (varchar 40), `title`, `body`, `link`, `is_read`, `created_at`. Index `idx_notifications_user_unread (user_id, is_read, id)`. Code: `repositories/NotificationRepository.php`, `helpers/Notifications.php`, `helpers/WorkNotify.php`.

### 4.13 `activity_log` and `audit_log`

Two different logs:

- `activity_log`: general user and system events. Columns `user_id`, `action` (varchar 64), `meta` (JSON), `ip_address`, `created_at`. Written by `helpers/ActivityLogger.php`, which defines the action names (for example `user_login`, `audit_approved`, `password_changed`); read by `api/admin/activity_log.php` and `helpers/ActivityLogView.php`.
- `audit_log`: a narrow log of road-group changes: `actor_id`, `actor_name`, `action` (varchar 20), `road_group_id`, `road_group_name`, `created_at`. Written in `api/admin/roads.php`.

### 4.14 `password_resets` and `password_requests`

- `password_resets`: emailed one-time links. `token_hash` (char 64, unique), `expires_at`, `used_at`, `requested_ip`. Code: `helpers/PasswordReset.php`, `StaffPasswordReset.php`.
- `password_requests`: "forgot password" requests approved by a team leader or admin. `new_password_hash`, `code_hash`, `code_tries`, `approver_role`, `city_id`, `status` (`pending`, `approved` and others; full set not verified), `handled_by`, `handled_at`, `expires_at`. Code: `helpers/PasswordRequest.php`.

Details of both flows are in [auth and permissions](auth-and-permissions.md).

### 4.15 `rate_limit_attempts`

Throttle counters keyed by `(ip_address, action)` (unique `uq_ip_action`): `attempts`, `locked_until`, `lockout_count`, `last_attempt_at`. Code: `config/rate_limit.php`.

### 4.16 `past_audits`

Historical audits imported as a stored report: `city_name`, `state`, `name`, `audit_date`, `road_count`, `segment_count`, `length_km`, `score`, `condition_label`, `report_name`, `report_data` (longblob, the report file), `created_by`, `deleted_at` (soft delete). Code: `repositories/PastAuditRepository.php`, `api/admin/past_audit_*.php`, `api/public/past_audit_report.php`.

### 4.17 `collaborators`

Partner organisations shown on the landing page: `name`, `role_label`, `website_url`, `logo_mime`, `logo_data` (mediumblob), `sort_order`, `is_visible`, `logo_scale`, `logo_x`, `logo_y`, `deleted_at` (soft delete). Code: `repositories/CollaboratorRepository.php`.

### 4.18 `oauth_states`

`state` (unique), `created_at`. Intended for a Google sign-in handshake. No PHP code uses it.

## 5. Status and enum values

| Where | Values | Set by |
| --- | --- | --- |
| `users.role` | `surveyor`, `city_leader`, `admin` | Admin screens (`api/admin/surveyors.php`, `city_leader.php`). Migration 018 renamed the old `national_admin` to `admin` and `city_admin` to `city_leader`. |
| `users.approval_status` | `pending`, `approved`, `rejected` | `auth/register.php` (pending), `helpers/Registration.php` (approve or reject) |
| `users.is_active` | 0 or 1 | Approval and activate/deactivate actions |
| `city_audits.status` | `draft`, `active`, `in_review`, `finalised`, `awaiting_approval`, `published`, `voided` | See below |
| `segment_assignments.status` | `assigned`, `submitted`, `needs_revisit`, `approved` | See below |
| `segments.status` | `pending`, `completed` in practice | `SegmentRepository` (completed, reset to pending), `AuditReviewRepository::sendBack` |
| `audit_sessions.status` | `active`, `completed`, `archived` | See section 4.7 |
| `roads.finalized_at` | NULL or a time | `api/roads/finalize.php` |

### `city_audits.status` transitions

```
draft --activate--> active --(first approve or send back)--> in_review
active / in_review --close (all segments approved)--> finalised
finalised --send to Admin--> awaiting_approval
awaiting_approval --Admin approve--> published
awaiting_approval --Admin return (with note)--> in_review
any non-voided --Admin delete--> voided   (data kept, name changed)
published: public_hidden_at set = hidden from home page; cleared = shown again
```

Where each move happens:

| Move | Code |
| --- | --- |
| `draft` to `active` | `CityAuditRepository::activate` (needs at least one road, at least one assigned segment, no segment assigned to an inactive surveyor) via `api/city/audit_activate.php` |
| `active` to `in_review` | `AuditReviewRepository::markInReview`, called when a segment is reviewed |
| to `finalised` | `AuditReviewRepository::close` via `api/city/audit_close.php` (action `close`) |
| to `awaiting_approval` | `AuditReviewRepository::sendToAdmin` (action `send`) |
| to `published` / back to `in_review` | `approveAudit` / `returnAudit` via `api/admin/audit_decide.php` |
| hide / show on home page | `unpublishAudit` / `republishAudit` via `audit_unpublish.php` / `audit_republish.php` |
| to `voided` | `CityAuditRepository::deleteAudit` via `api/admin/audit_delete.php`, which also marks active sessions of its roads `archived` |

### `segment_assignments.status` transitions

```
assigned --surveyor submits--> submitted --approve--> approved
                               submitted --send back--> needs_revisit --surveyor submits--> submitted
```

Submitting is allowed from `assigned` and `needs_revisit` (`SURVEYOR_SUBMITTABLE_STATUSES`). Send back also resets the segment to `pending` and reopens the surveyor's latest completed session. See [workflows](workflows.md).

## 6. Unused or legacy items (proved by search)

- `oauth_states`, `road_assignments`: no references in PHP, JS or tests.
- `login_attempts`: created by migration 001, absent from `schema.sql`, no references in PHP.
- `users.google_id`, `users.auth_provider = 'google'`: no code path produces or reads Google accounts.
- `segments.status = 'in_progress'`: never written.
- `road_groups.assigned_*`: whether any current screen writes them was not verified.

## 7. How to add a migration

1. Take a backup first (see [BACKUPS.md](../BACKUPS.md)).
2. Create `migrations/NNN_short_name.sql`, where `NNN` is the next number (the latest is 032, so the next is 033). Follow the existing header: what it does, when to run it relative to the deploy, and the run instructions.
3. Run statements **one per query** in Railway's MySQL console (the query box does not run multi-statement scripts reliably, per the existing migration headers).
4. Decide the order against the deploy:
   - Additive change the old code ignores (new nullable or defaulted column): run **before** merging the code. Example: migration 032 says to run before deploying.
   - Rename or value change (migration 018): widen first, deploy, move the data, then narrow. 018 documents the four steps.
5. Write the PHP so it still works if the column is missing when that is cheap to do (see `usersHaveApprovalStatus()` in `api/admin/surveyors.php`), so a deploy and a migration do not need to be perfectly simultaneous.
6. Refresh `tests/e2e/schema.sql` (command in `tests/e2e/README.md`) so tests and fresh installs match. Delete the `SET @@GLOBAL.GTID_PURGED` line from the dump.
7. Run `composer test` and, in CI, the end-to-end workflow.

## 8. Useful read-only queries for operators

Run these in Railway's MySQL console. They only read data.

```sql
-- Accounts by role and approval state
SELECT role, approval_status, is_active, COUNT(*) FROM users GROUP BY role, approval_status, is_active;

-- Audits and where they are in the lifecycle
SELECT id, name, audit_year, status, public_hidden_at FROM city_audits ORDER BY id DESC;

-- Review progress for one audit (replace 1)
SELECT status, COUNT(*) FROM segment_assignments WHERE audit_id = 1 GROUP BY status;

-- Segments by status for one road (replace 1)
SELECT status, COUNT(*) FROM segments WHERE road_id = 1 GROUP BY status;

-- Active sessions older than a week (possible abandoned work)
SELECT id, user_id, road_id, started_at FROM audit_sessions
WHERE status = 'active' AND started_at < NOW() - INTERVAL 7 DAY;

-- Recent activity
SELECT created_at, user_id, action FROM activity_log ORDER BY id DESC LIMIT 50;

-- Locked-out IPs
SELECT ip_address, action, attempts, lockout_count, locked_until FROM rate_limit_attempts
WHERE locked_until > NOW();
```

## Known gaps / things that surprised me

- `services/IdGenerator.php` mentions database triggers that do not exist; PHP fills `public_id`.
- Migrations do not create six tables that the code needs (section 1).
- Three tables or column families are unused (section 6).
- `users.public_id` and several `*_id` user columns have no foreign key or no unique key in the dump.
- `audit_sessions.status` is `archived`, not `abandoned` as an older README line said.
- Collations differ between older and newer tables.
