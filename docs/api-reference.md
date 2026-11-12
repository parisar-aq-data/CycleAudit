# API reference

Every HTTP endpoint under `api/`: who may call it, which method, how CSRF and rate limits apply, what it takes and what it returns. There are 55 endpoint files. Pages (`pages/*.php`) and sign-in files (`auth/*.php`) are not listed here; see [frontend](frontend.md) and [auth and permissions](auth-and-permissions.md).

Related chapters: [auth and permissions](auth-and-permissions.md) (guards, CSRF, rate limits, roles), [workflows](workflows.md) (what each step means), [database](database.md) (tables).

Everything here was read from the code. Items that could not be proved are marked "(not verified)". Request bodies are summarised from the endpoint header comments and the validation code; the code is the final word.

## 1. Conventions

**Format.** Almost every endpoint answers JSON: `{"success": true, ...}` or `{"success": false, "error": "message"}`. Validation failures may add `"errors": {"field": "message"}`. The exceptions are file downloads (`reports/export-*`, `admin/export-*`, `public/*logo*`, `public/past_audit_report.php`, `admin/collaborator_logo.php`), which stream a file and answer plain text on error.

**Status codes.**

| Code | Meaning |
| --- | --- |
| 200 | Done |
| 400 | Malformed request or missing required field |
| 401 | Not signed in, or the session ended (also deactivated account, or password changed since sign-in) |
| 403 | Not allowed, wrong role, session not yours, or invalid CSRF token |
| 404 | Not found, or not visible to you (a City Leader asking about another leader's project gets 404) |
| 405 | Wrong HTTP method |
| 409 | State conflict: already decided, not in the right status, cannot be done now |
| 410 | The project was deleted by the Admin (`project_deleted: true` in the body) |
| 422 | Validation failed |
| 429 | Rate limit reached |
| 500 | Server error; the message is generic and details go to the server log |

**Sign-in.** Endpoints marked `auth_guard` need any signed-in user. Endpoints marked `admin_guard` need an Admin or a City Leader and then usually check the exact role again. `public` endpoints need nothing. API-style requests that are not signed in get `401` JSON; browsers asking for a page get a redirect (see [auth chapter, section 3](auth-and-permissions.md)).

**City Leader allow-list.** On top of the table below, a City Leader can reach only the scripts in `CITY_LEADER_ALLOWED_SCRIPTS` (`helpers/CityLeaderGate.php`). Everything else answers `403 "This area is not available to City Leaders."` before the endpoint runs. For a City Leader the endpoints that work are: `api/city/*` (all eight), `api/user/profile.php`, `api/user/notifications.php`, `api/admin/roads.php`, `api/admin/export-roads.php`, `api/admin/password_requests.php`, `api/admin/signup_requests.php`, `api/admin/team.php` and `api/reports/export-city-excel.php`.

**CSRF.** Checked per endpoint, with the `hash_equals` function, against `$_SESSION['csrf_token']`. Three styles exist:

- Helper: `cityApiContext()` (City Leader writes), `nationalApiContext()` and `nationalFormContext()` (Admin writes) in `helpers/CityApi.php`. They check method, role, CSRF and JSON body together. Token in the `X-CSRF-Token` header.
- Inline header check: the same header, written out in the endpoint.
- Body field: `api/user/profile.php` reads `csrf_token` from the JSON body instead.

Read-only `GET` endpoints do not check CSRF.

**Which request body.** JSON for nearly all writes. Exceptions: `segments/submit.php` takes form fields (`$_POST`, with `intersections` as a JSON string); `admin/collaborator_save.php` and `admin/past_audit_create.php` take multipart forms because they carry files.

## 2. Admin: projects (audits)

All are `POST`, admin only (`nationalApiContext`), CSRF header required. Described step by step in [workflows, section 3](workflows.md).

| Endpoint | Body | Result and rules |
| --- | --- | --- |
| `admin/audit_create.php` | `city_id` or `city_name`, `name`, `audit_date`, `programme_info?`, `leader_user_id?` | `{audit_id}`. 422 on bad input, 409 on duplicate name and year or bad leader |
| `admin/audit_update.php` | `audit_id`, `name`, `audit_date`, `programme_info?`, `leader_user_id?` | `{changed, leader_changed}`. City and state do not change |
| `admin/audit_decide.php` | `audit_id`, `action` (`approve` or `return`), `note` (required for return) | `{status}`. Only while `awaiting_approval` (409 otherwise) |
| `admin/audit_delete.php` | `audit_id`, `confirm_name`, `reason` | Marks the project `voided`. 422 if the typed name does not match or the reason is missing |
| `admin/audit_unpublish.php` | `audit_id` | Hides a published audit from the home page |
| `admin/audit_republish.php` | `audit_id` | Shows it again |
| `admin/city_leader.php` | `city_id`, `user_id`, `replace?` | Makes an existing user a City Leader of that city (checks in `cityLeaderAssignError`) |

## 3. City Leader: working an audit

All are `POST`, City Leader only with a city (`cityApiContext`), CSRF header required. An Admin calling them gets `403 "Only a City Leader can change audits."` The project must be openable by the caller (`projectOpenableBy`) or the answer is `404`.

| Endpoint | Body | Result and rules |
| --- | --- | --- |
| `city/audit_road_add.php` | `audit_id`, `road_group_id`, `total_length`, `segment_length` | `{road_id, segment_count}`. Length 50 to 100,000 m, segments at least 10 m, at most 500 |
| `city/audit_road_remove.php` | `audit_id`, `road_id` | Only while nothing is audited on the road |
| `city/audit_segment_add.php` | `audit_id`, `road_id`, `segment_length` | Adds one segment at the end |
| `city/audit_segment_remove.php` | `audit_id`, `segment_id` | Only while the segment is untouched |
| `city/audit_assign.php` | `audit_id`, `surveyor_id`, and `segment_ids` or `road_id` | `{assigned, skipped}`. `surveyor_id` empty clears |
| `city/audit_activate.php` | `audit_id` | `draft` to `active` |
| `city/audit_review.php` | `audit_id`, `segment_id`, `action` (`approve` or `send_back`), `note`, `surveyor_id?` | Review one submitted segment |
| `city/audit_close.php` | `audit_id`, `action` (`close` or `send`) | `finalised`, or `awaiting_approval` |

## 4. Admin and City Leader: people and housekeeping

All behind `admin_guard`. "CSRF" here is the `X-CSRF-Token` header.

| Endpoint | Method | Who | Body or query | Notes |
| --- | --- | --- | --- | --- |
| `admin/surveyors.php` | GET | Admin: everyone. The code also supports a City Leader seeing their own city, but the allow-list blocks leaders | none | The user list for `pages/admin_surveyors.php` |
| `admin/surveyors.php` | POST | Admin | `id` plus one action: `action: "reset_password"`, `city_id`, `role`, or `is_active` | Resets a password, moves a user to another city, changes a role (Admin only), activates or deactivates. Rules in [auth chapter, section 1](auth-and-permissions.md). Inline CSRF |
| `admin/signup_requests.php` | POST | Admin, or City Leader for their own city | `action` (`approve` or `reject`), `id` | Who may act is decided on the server. Others get "Request not found." |
| `admin/password_requests.php` | POST | Admin for requests addressed to Admins; City Leader for requests addressed to City Leaders of their own city | `action` (`approve` with `code`, or `dismiss`), `id` | The forgot-password approval, see [auth chapter, 7.2](auth-and-permissions.md) |
| `admin/team.php` | POST | City Leader only | `action` (`add`, `remove`, `add_many` with `user_ids`, up to 200), `user_id` | The team roster |
| `admin/roads.php` | GET | City Leader only | none | The city's roads (`road_groups`) with their member rows. Admin gets 403 |
| `admin/roads.php` | POST | City Leader only | `action` `create` with `name`; `delete` with `id`, `confirm_name`; `assign` with `id`, `surveyor_id` (legacy) | Delete works only for a road no audit uses |
| `admin/activity_log.php` | GET | Admin sees the whole log; a City Leader who reached it would see only the road list entries of their own city (it is not on the allow-list, so leaders cannot reach it) | `category`, `action`, `user`, `role`, `audit`, `tone`, `from`, `to` (YYYY-MM-DD), `q`, `limit`, `offset`, `order` | Filters are applied on the server over the whole log |
| `admin/export-users.php` | POST | Admin (the endpoint itself only needs `admin_guard`; the allow-list keeps City Leaders out) | `{rows: [...]}` exactly as shown on screen | Streams an .xlsx. No database query |
| `admin/export-roads.php` | POST | Admin or City Leader (on the allow-list) | `{rows: [...]}` | Streams an .xlsx. No database query |
| `admin/collaborator_save.php` | POST (multipart) | Admin | `collaborator_id?`, `name`, `role_label?`, `website_url?`, `logo?` (up to 1 MB), `remove_logo?` | Adds or edits a collaborator. `nationalFormContext` |
| `admin/collaborator_action.php` | POST | Admin | `collaborator_id`, `action` (`show`, `hide`, `delete`, `up`, `down`) | Delete keeps the row and logo, hides it everywhere |
| `admin/collaborator_logo.php` | GET | Admin | `id` | The logo, including hidden collaborators |
| `admin/past_audit_create.php` | POST (multipart) | Admin | `city_name`, `name`, `audit_date`, `road_count`, `segment_count`, `length_km`, `score?`, `report?` (PDF up to 8 MB) | Published at once on the Audit Data page |
| `admin/past_audit_delete.php` | POST | Admin | `past_audit_id` | The row stays; it stops being listed |

The two export endpoints take the rows from the browser and trust them; they do not re-read the database. A signed-in Admin can therefore produce a spreadsheet with any content. This is harmless for data safety but means the file is not proof of what the database holds.

## 5. Surveyor work: sessions, segments, roads

All behind `auth_guard`. Because of the City Leader allow-list, City Leaders cannot call any endpoint in this section. Details of each step are in [workflows, section 3.6](workflows.md).

| Endpoint | Method | CSRF | Body or query | Rules |
| --- | --- | --- | --- | --- |
| `audit-sessions/create.php` | POST | inline | `road_id` | 10 per minute per user. 410 deleted project; 403 road not assigned to you; returns the existing session if one is active; a finalised road returns the last session with `finalized: true` |
| `segments/submit.php` | POST (form fields) | inline | segment answers, `segment_id`, `session_id`, `intersections` (JSON string), `edit_mode?`, landmarks and GPS | 30 per minute per user. One transaction with the segment row locked. 409 finalised or already locked; 403 not your session or not assigned; 422 bad answers |
| `segments/complete.php` | PUT | inline | `segment_id`, `session_id` | Marks the segment `completed`; completes the session when all are done |
| `segments/reset.php` | POST | inline | `segment_id`, `session_id` | Clears the segment's answers, back to `pending` |
| `segments/unlock.php` | POST | inline | `segment_id` | Back to `pending`, keeping answers. Permission `unlock_segment`; 409 if the road is finalised or the segment is not completed |
| `segments/audit-data.php` | GET | none | `segment_id` | Latest saved answers. Permission `view_audit_data` |
| `roads/finalize.php` | PUT | inline | `road_id` | Locks a fully audited road. Permission `finalize_road`; repeating returns `already_finalized: true` |
| `roads/create.php` | POST | inline | road details | Permission `create_road` (Admin or City Leader). In practice only an Admin can reach it (leaders are blocked by the allow-list) |
| `roads/update.php` | POST | inline | road fields | Permission `edit_road` |
| `roads/delete.php` | POST | inline | `road_id` | Permission `delete_road` (Admin only) |
| `roads/groups.php` | GET | none | none | Unflagged road names for the viewer's city |
| `roads/segments/index.php` | GET | none | `road_id` | A road plus its segments, with an `assigned_only` flag in the answer. For a surveyor on a city audit this limits the list to their assigned segments (the flag logic itself was not traced, not verified) |
| `roads/segments/save.php` | POST | inline | segment definitions for one road | Permission `save_segments` |

Permissions are the functions in `config/permissions.php`; the matrix is in the [auth chapter](auth-and-permissions.md). Whether `roads/create.php`, `update.php`, `delete.php` and `segments/save.php` are still called from any page was not checked (not verified).

## 6. Dashboard, notifications, profile, project status

| Endpoint | Method | CSRF | Notes |
| --- | --- | --- | --- |
| `dashboard/stats.php` | GET | none | All data for the signed-in user's dashboard in one request. Surveyors get their assignments and streak |
| `user/notifications.php` | GET | none | `{unread, notifications: [...]}` for the signed-in user |
| `user/notifications.php` | POST | header | `{action: "read_all"}` marks everything read. Any other action gets "Unknown action." |
| `user/profile.php` | GET | none | The signed-in user's profile |
| `user/profile.php` | POST | `csrf_token` in the JSON body | `action`: `update_profile` (name, phone as 10 digits, organisation, gender, age, address), `update_email`, `change_password` (rate limited per user), `upload_avatar` (data URL image) |
| `project_status.php` | GET | none | `?audit_id=N`, `?road_id=N` or `?segment_id=N`; answers `{success, deleted}`. Polled every 8 seconds by `js/project_watch.js`. You only learn about projects you are part of |

## 7. Reports and downloads

| Endpoint | Method | Who | Query | Notes |
| --- | --- | --- | --- | --- |
| `reports/session.php` | GET | Signed-in user, owner of the session | `session_id` | JSON report data. 404 unknown session, 403 not yours |
| `reports/export-pdf.php` | GET | Owner of the session | `session_id` | mPDF file. 5 per minute per user |
| `reports/export-excel.php` | GET | Owner of the session | `session_id` | PhpSpreadsheet .xlsx. 5 per minute per user |
| `reports/export-city-excel.php` | GET | Admin (needs `city_id` once more than one city exists) or City Leader (own city, any `city_id` is ignored) | `city_id?` | City-wide score sheet, one row per road. 5 per minute per user |

The three per-session endpoints compare the session's `user_id` with the signed-in user and have no exception for Admins or City Leaders. A City Leader or Admin therefore cannot download a surveyor's per-road PDF or Excel through these endpoints (they read the report on screen instead, or use the city-wide sheet).

## 8. Public endpoints (no sign-in)

`GET` only, each with a per-IP fixed-window limit (see the [auth chapter, section 5](auth-and-permissions.md) for the numbers and for how the client IP is found).

| Endpoint | Limit | Returns |
| --- | --- | --- |
| `public/audits.php` | 20 per minute | Published audits and past audits for the home page; 5-minute file cache |
| `public/stats.php` | 20 per minute | Landing page figures; optional `?city_id=` |
| `public/roads.php` | 60 per minute | Verified road names |
| `public/collaborators.php` | 30 per minute | Visible collaborators |
| `public/collaborator_logo.php` | 120 per minute | A visible collaborator's logo, `?id=`; hidden or missing answers 404 |
| `public/past_audit_report.php` | 30 per minute | A past audit's PDF, `?id=` and optional `download=1`; missing answers 404 |

## 9. Per-endpoint CSRF and guard table

This is the table promised in the [auth chapter, section 8](auth-and-permissions.md). "helper" means `cityApiContext`, `nationalApiContext` or `nationalFormContext`.

| Endpoint | Guard | Write method | CSRF check |
| --- | --- | --- | --- |
| `admin/audit_*` (6 files), `admin/city_leader.php`, `admin/collaborator_action.php`, `admin/past_audit_delete.php` | admin_guard | POST | helper |
| `admin/collaborator_save.php`, `admin/past_audit_create.php` | admin_guard | POST (form) | helper |
| `admin/export-roads.php`, `export-users.php`, `password_requests.php`, `roads.php`, `signup_requests.php`, `surveyors.php`, `team.php` | admin_guard | POST | inline |
| `city/*` (8 files) | admin_guard | POST | helper |
| `audit-sessions/create.php` | auth_guard | POST | inline |
| `segments/submit.php`, `reset.php`, `unlock.php` | auth_guard | POST | inline |
| `segments/complete.php`, `roads/finalize.php` | auth_guard | PUT | inline |
| `roads/create.php`, `update.php`, `delete.php`, `segments/save.php` | auth_guard | POST | inline |
| `user/notifications.php` | auth_guard | POST | inline (header) |
| `user/profile.php` | auth_guard | POST | inline (JSON body field) |
| All `GET` endpoints, `public/*` | various | none | not checked |

## 10. Adding an endpoint

1. Pick the guard: `auth_guard.php` (any user) or `admin_guard.php` (Admin or City Leader), required at the top.
2. For a write, use `cityApiContext()` or `nationalApiContext()` so the method, role, CSRF and body checks come for free, or copy the inline check from an existing endpoint.
3. Look the resource up and check the caller may see it. Answer `404` rather than `403` for things the caller should not know exist.
4. For a project, call `cityApiFailIfDeleted()` so deleted projects answer `410`.
5. Use `gate('permission', ...)` from `config/permissions.php` for object-level rules and add new permissions there.
6. If a City Leader should be able to call it, add its path to `CITY_LEADER_ALLOWED_SCRIPTS` in `helpers/CityLeaderGate.php`.
7. Log with `ActivityLogger::log()` after the action succeeds; add the constant if it is a new event.
8. Wrap notifications in `try`/`catch` so they cannot undo the action.
9. Add the endpoint to this chapter and to the CSRF table above.

## Known gaps and things that surprised me

- **The project watcher does not work for City Leaders.** `js/project_watch.js` is loaded by `pages/city_audit.php` and `pages/city_segment_review.php` and polls `api/project_status.php` every 8 seconds. That endpoint is not on the City Leader allow-list, so a leader gets `403` and the script ignores it. A leader therefore never sees the "This project was deleted" popup while a screen is open; their next write still answers `410`. Found by reading; not tested on a live copy. Fix: add `api/project_status.php` to `CITY_LEADER_ALLOWED_SCRIPTS`.
- Per-session PDF, Excel and JSON reports are limited to the surveyor who owns the session (section 7).
- `api/roads/create.php`, `update.php`, `delete.php` and `segments/save.php` carry permissions for City Leaders that the allow-list makes unreachable.
- CSRF is checked in three different ways (section 1). A central check in `auth_guard.php` for every non-GET request would remove the chance of forgetting it on a new endpoint.
