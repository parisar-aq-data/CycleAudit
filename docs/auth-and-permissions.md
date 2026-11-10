# Authentication, roles and permissions

This chapter explains who can use CycleAudit and what each person may do: roles, sign-up and approval, sign-in, sessions, passwords, CSRF protection, the Content-Security-Policy, and the guards that run on every request.

Related chapters: [database](database.md) (the `users`, `password_*` and `rate_limit_attempts` tables), [API reference](api-reference.md), [workflows](workflows.md), [frontend](frontend.md) (how the CSP nonce is used in pages).

Everything here was read from the code. Items that could not be proved are marked "(not verified)".

## 1. Roles

| Role shown in the app | Stored in `users.role` | Home page after sign-in | Scope |
| --- | --- | --- | --- |
| Admin | `admin` | `pages/platform_dashboard.php` | Everything, all cities. `users.city_id` is normally empty. |
| City Leader | `city_leader` | `pages/city_dashboard.php` | One city (`users.city_id`). Must have a city. |
| Surveyor | `surveyor` | `pages/dashboard.php` | Audits only the segments assigned to them. |

- Stored values and labels are defined in `helpers/RoleRules.php` (`ROLE_VALUES`) and `helpers/RoleHome.php` (`roleHomePage`, `roleLabel`).
- Every sign-in lands on `pages/dashboard.php`, which forwards by role.
- Migration 018 renamed the old stored values `national_admin` to `admin` and `city_admin` to `city_leader`. Old comments in some files still use the older names.

### Who may change whom (`helpers/RoleRules.php`)

- Only an Admin can change a role. A City Leader can therefore only exist because an Admin made one.
- A user cannot demote themselves, and the last remaining Admin cannot be demoted.
- A City Leader cannot be created for a user who has no city.
- `canManageUser`: an Admin may act on anyone; a City Leader may act on surveyors only.

### The original Admin (`helpers/PrimaryAdmin.php`)

The "original Admin" is the admin account with the lowest `id`. Other Admins have full power over everyone else but cannot deactivate, demote, move or reset the password of the original Admin. If the original Admin is locked out, the code comment points to `scripts/make_admin_reset_sql.php`, to be run by someone with database access.

### Team leader and team roster (`helpers/TeamRoster.php`)

A city can have several City Leaders. A surveyor can belong to one leader's team (`users.team_leader_id`, migration 027).

- A City Leader may add an active surveyor of their own city who is in no team (or whose old leader is no longer an active City Leader of that city), and may remove people from their own team.
- Nobody can take a surveyor out of another leader's team. Only surveyors can be in a team.
- The team decides who sees a surveyor's "forgot password" request (section 6.2) and which surveyors a leader may assign work to.

### Removing a surveyor who has work (`helpers/SurveyorRemoval.php`)

Removing a surveyor from a team, or deactivating them, sorts their assignments:

- Not started (status `assigned`, segment still `pending`, no active session on the road): returned to unassigned immediately.
- Started or sent back (`needs_revisit`): the surveyor keeps them, finishes, and the removal completes when the last one is submitted. Until then `users.removal_pending_at` is set.
- Submitted or approved work is finished and never blocks a removal.

## 2. Permission matrix

Permissions live in `config/permissions.php` as a map of name to function, used through `can()` (returns true or false) and `gate()` (responds `403` JSON and stops). Unknown permission names are denied.

Rules that apply to several actions:

- `isAnyAdmin(role)`: true for `admin` and `city_leader`.
- `hasAdminAccessToCity(role, ctx)`: an Admin always; a City Leader only if the resource's `city_id` is present and equals the leader's own city; a surveyor never.
- **Fail closed:** if an endpoint forgets to pass `city_id`, a City Leader gets nothing extra and is treated like a plain owner check.

| Permission | Admin | City Leader | Surveyor | Used by |
| --- | --- | --- | --- | --- |
| `create_road` | yes | yes (but see gate note) | no | `api/roads/create.php` |
| `edit_road` | yes | own city, or road owner | road owner | `api/roads/update.php` |
| `delete_road` | yes | no | no | `api/roads/delete.php` |
| `save_segments` | yes | own city, or owner | owner | `api/roads/segments/save.php` |
| `finalize_road` | yes | own city, or owner | owner | `api/roads/finalize.php` |
| `unlock_segment` | yes | own city, or owner | owner | `api/segments/unlock.php` |
| `view_audit_data` | yes | own city, or owner | owner | `api/segments/audit-data.php` |
| `create_session`, `submit_audit` | yes | yes | yes | defined, but no `can()` or `gate()` call uses these names (ownership is checked inside the endpoint) |
| `view_session`, `reset_segment` | yes | own city, or owner | owner | defined; no `can()` or `gate()` call uses these names |
| `manage_users`, `view_all_roads` | yes | yes | no | defined; no `can()` or `gate()` call uses these names |

"Owner" means `ctx['owner_id']` equals the user's id (for example the road's `creator_id`).

### The City Leader screen allow-list (`helpers/CityLeaderGate.php`)

On top of the matrix, a City Leader can open **only** the scripts in `CITY_LEADER_ALLOWED_SCRIPTS`. `config/auth_guard.php` enforces it on every protected request: pages redirect to `pages/city_dashboard.php`, APIs answer `403` with "This area is not available to City Leaders."

The list covers the city dashboard and audit pages, review and report pages, `api/city/*`, profile, notifications, the roads page and its API (`pages/admin.php`, `api/admin/roads.php`, `api/admin/export-roads.php`), the password-request, sign-up-request and team APIs, `pages/city_team.php`, `pages/city_completed.php` and `api/reports/export-city-excel.php`. When you build a new City Leader screen, add its path there.

Consequence worth knowing: `api/roads/*` and `api/segments/*` (the surveyor audit endpoints) are not on the list, so a City Leader cannot call them even though some permissions above would allow it.

### City scoping for reads (`helpers/CityScope.php`)

`resolveViewerCityScope(role, userCityId)` returns `null` (Admin: all cities), a city id, or `0` (no city can be determined: matches nothing, fail closed). A user with no `city_id` falls back to the only city when exactly one exists.

## 3. Request lifecycle

### Pages and API endpoints that need a login

Every protected PHP file starts with one of two includes:

- `config/auth_guard.php`: any signed-in user.
- `config/admin_guard.php`: includes `auth_guard.php`, then admits `admin` and `city_leader` only (`isAnyAdmin`). National-only endpoints must additionally check `$CURRENT_USER_ROLE === 'admin'` themselves; the helpers `nationalApiContext()` and `nationalFormContext()` in `helpers/CityApi.php` do this for the Admin write APIs.

`auth_guard.php` runs these steps in order:

1. Start the secure session and apply the inactivity timeout (section 4).
2. Send the Content-Security-Policy header and create the per-request nonce (section 8).
3. No `user_id` in the session: browsers are redirected to `auth/login.php`; requests that look like API calls (`Accept: application/json`, `X-Requested-With: XMLHttpRequest`, or a JSON content type) get `401` JSON.
4. Make sure a CSRF token exists in the session.
5. **Re-read the user from the database on every request** (`role`, `is_active`, `profile_picture`, `city_id`). A deactivated or deleted account is signed out at once (`401`, or redirect to `login.php?deactivated=1`). Role and city changes therefore apply immediately without a new sign-in.
6. If the password was changed after this session started (`passwordChangedSinceLogin`, comparing `users.password_changed_at` with `$_SESSION['auth_at']`), end the session (`401` or redirect).
7. If `users.must_change_password` is set and the page is not exempt, force `auth/force_password.php` (`403` for APIs). Exempt pages: `force_password.php` and `logout.php`.
8. If the user is a City Leader and the script is not on the allow-list, redirect or `403`.
9. Expose `$CURRENT_USER_ID`, `$CURRENT_USER_NAME`, `$CURRENT_USER_ROLE`, `$CURRENT_USER_CITY_ID`, `$CURRENT_USER_PIC` to the page.

Status codes you will see: `401` not signed in or session ended, `403` not allowed or invalid CSRF token, `405` wrong method, `409` state conflict (for example "already decided"), `422` validation, `429` from the rate limiter where used, `500` server error (generic message, details logged).

### Public endpoints

Files under `api/public/` and the landing page need no login. They are read-only (`GET`) and use the IP-based request cap (section 5).

## 4. Sessions

Defined in `config/constants.php`:

| Setting | Value |
| --- | --- |
| Session cookie name | `ca_session` |
| Cookie lifetime | 8 hours (`SESSION_LIFETIME`) |
| Inactivity timeout | 8 hours since the last request (`enforceSessionTimeout`, using `$_SESSION['_last_activity']`) |
| Cookie flags | `HttpOnly`; `SameSite=Lax`; `Secure` when the request is HTTPS (direct, or `X-Forwarded-Proto: https` behind Railway) |
| Session id | regenerated on sign-in and after a password change in the profile |

The expired-session redirect in `enforceSessionTimeout()` is the fixed path `/auth/login.php?expired=1`, which assumes the app is served from the web root. On a local install under a sub-folder such as `/Parisar/` that redirect would miss. (Observed in the code; not tested locally.)

## 5. Rate limiting and client IP

`config/rate_limit.php` stores counters in `rate_limit_attempts` keyed by `(ip_address, action)`.

**Failure-based limiter** (`checkRateLimit`, `recordFailedAttempt`, `clearRateLimitAttempts`):

- 5 failed attempts for one action within a 15-minute window start a lockout.
- The first lockout lasts 15 minutes. The code computes `15 minutes x 2^(lockouts-1)`, capped at 24 hours.
- Used for the actions `login`, `register`, `password_request`, `reset_token` (keyed by IP), and `change_password` (keyed `user:<id>`).
- **Check this on a live copy:** reading the code, the doubling looks unreachable. `_pruneOldAttempts()` runs at the start of every check and deletes any row whose lockout has expired, which also removes `lockout_count`, and attempts made during a lockout are refused before they are recorded. The effect is that each lockout is 15 minutes. No test covers doubling (not tested live).

**Fixed-window request cap** (`checkAndRecordApiRequest`): a plain count per window with no escalation:

| Action key | Limit | Where |
| --- | --- | --- |
| `register_ok` | 10 per hour | new accounts per network, `auth/register.php` |
| `public_stats` | 20 per minute | `api/public/stats.php` |
| `public_audits` | 20 per minute | `api/public/audits.php` |
| `public_roads` | 60 per minute | `api/public/roads.php` |
| `public_collaborators` | 30 per minute | `api/public/collaborators.php` |
| `public_collab_logo` | 120 per minute | `api/public/collaborator_logo.php` |
| `public_report` | 30 per minute | `api/public/past_audit_report.php`, `pages/public_audit_report.php` |
| `segment_submit` | 30 per minute per user | `api/segments/submit.php` |
| `audit_session_create` | 10 per minute per user | `api/audit-sessions/create.php` |
| `export_pdf`, `export_excel`, `export_city_excel` | 5 per minute per user | `api/reports/*` |

**Client IP (`getClientIp`)**: `X-Forwarded-For` (leftmost address) is used only when the direct connection comes from a private or Railway range: `100.64.0.0/10`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`. Otherwise the raw `REMOTE_ADDR` is used, so a visitor cannot choose their own IP by sending the header. `helpers/ActivityLogger.php` carries its own copy of the same logic.

## 6. Registration, approval and sign-in

### 6.1 Registration (`auth/register.php`, `helpers/Registration.php`)

Self-registration creates a **surveyor** who cannot sign in yet:

1. Validation: name (letters, spaces, hyphens, apostrophes, dots, 2 to 80 characters); a valid email; optional phone (10-digit Indian mobile starting 6 to 9); gender; age 16 to 80; organisation; home city and state; password of at least 12 characters with at least one letter and one number; matching confirmation.
2. Sign-up is refused ("temporarily unavailable") if `users.approval_status` does not exist yet, so the code fails shut until migration 032 has run.
3. Duplicate emails are refused. At most 10 new accounts per network per hour.
4. The row is inserted with `role = 'surveyor'`, `is_active = 0`, `approval_status = 'pending'`; `users.city_id` is set only when the typed city already exists in `cities`. The `public_id` is then set to `USR-NNNN`.
5. There is no auto sign-in and no session. The visitor sees a "request has been sent" message.

The register and login forms do not carry a CSRF token. They are protected by the rate limits and the `SameSite=Lax` cookie (no `csrf` handling in `auth/login.php` or `auth/register.php`).

**Approval.** Pending sign-ups appear in a "Sign-up requests" panel for the City Leader of the matching city, and for every Admin (`signupRequestsOpenFor`). `signupRequestDecide` approves (`is_active = 1`, `approved`) or rejects (`is_active = 0`, `rejected`). The decision uses the signed-in role, never a value sent by the page; a request that is not yours answers `404` exactly like one that does not exist, and a request already decided answers `409`. Endpoint: `api/admin/signup_requests.php`. Admins can also reactivate people from the Surveyors page (`markUserApproved` clears the pending or rejected tag).

### 6.2 Sign-in (`auth/login.php`)

1. Rate-limit check for `(ip, 'login')`.
2. Look up the user by email, then run `password_verify` **even for an unknown email** (against a fixed dummy hash) so the response time is similar either way.
3. Wrong password or unknown email: record a failure and show the same generic "Invalid email or password" message, plus a remaining-attempts hint when 2 or fewer are left. Both cases are logged with reason `wrong_password` or `unknown_email`.
4. Correct password but `is_active = 0`: the message depends on `approval_status` (waiting for approval, not approved, or deactivated). This is shown only after the password was proved, so it does not reveal whether an account exists.
5. Success: clear the failure counter, `session_regenerate_id(true)`, set `user_id`, `user_name`, `user_email`, `user_role`, `profile_picture`, `auth_at` (time of sign-in) and a CSRF token, update `last_login`, log `user_login`, redirect to the dashboard.
6. Sign-out (`auth/logout.php`) clears the session and cookie and logs `user_logout`.

## 7. Passwords

- Hashing: `password_hash(..., PASSWORD_DEFAULT)`; checking: `password_verify`.
- Rule: at least 12 characters with at least one letter and one number, plus matching confirmation. The rule is implemented in `passwordRuleError()` (`helpers/PasswordReset.php`) and separately in `auth/register.php`. It appears again in the form pages (`minlength="12"` in `auth/forgot_password.php`, `reset_password.php`, `force_password.php`) and in the client scripts `js/register.js` and `js/profile.js` for the strength meter. Existing accounts that chose a shorter password keep it until they change it.
- A password is never checked against leaked-password lists.

### 7.1 Changing your own password (profile)

`api/user/profile.php`, action change password: rate limited per user (`change_password`), verifies the current password, applies the rule, refuses an unchanged password, stores the hash and `password_changed_at`, regenerates the session id and logs `password_changed`. Every other session of the same user ends on its next request (step 6 above). The CSRF token is read from the JSON body field `csrf_token` here, not from a header.

### 7.2 Forgot password: approved by a person (`auth/forgot_password.php`, `helpers/PasswordRequest.php`)

This is the live "forgot password" path and needs no email service.

1. The person enters their email and chooses a **new** password. It is hashed and stored as a pending request in `password_requests`; the old password keeps working. A 6-digit code is shown on their screen and only its hash is stored.
2. The request is routed to whoever can approve it (`passwordRequestRoute`): a surveyor in a team goes to that team leader; a surveyor with no team is shown to every City Leader of the city; a surveyor with no active City Leader, a City Leader, or a user with no city goes to an Admin. An Admin's request goes to nobody (use `scripts/make_admin_reset_sql.php`).
3. The approver checks the person's identity out of band (the code comment suggests a phone call), types the code and approves. Only then does the new password take effect and every older session of that person end.
4. Limits: a request expires after 48 hours, 5 wrong codes close it, at most 10 requests per account per 24 hours, finished requests are deleted after 90 days.
5. The page looks the same whether or not the email exists and does the same hashing work either way, so it cannot be used to discover accounts. Endpoint for approvers: `api/admin/password_requests.php`.

### 7.3 Staff "Reset password" button (`helpers/StaffPasswordReset.php`)

Via `api/admin/surveyors.php`, action `reset_password`: an Admin may reset anyone except themselves (and subject to the original-Admin protection); a City Leader may reset surveyors of their own city. The target must be active. A temporary password (three words and four digits, `tempPasswordGenerate`) is generated, stored hashed with `must_change_password = 1`, and shown once to the person who pressed the button. Open emailed links for that account are closed. At the next sign-in the person is forced to `auth/force_password.php` before anything else.

### 7.4 Emailed reset link: present in code but not issued

`auth/reset_password.php`, `helpers/PasswordReset.php` and `helpers/Mailer.php` implement a one-time emailed link (token hashed with SHA-256, valid 60 minutes, used once, 2-minute cooldown, Brevo or Resend via `MAIL_PROVIDER`, `MAIL_API_KEY`, `MAIL_FROM`).

**However, nothing in the application calls `passwordResetCreate()` or `mailerSend()`.** A search of `auth/`, `api/`, `helpers/` and `pages/` finds the definitions only; the call sites exist only in `tests/PasswordResetTest.php`. The git history shows the commit "Add in-house password requests approved by City Leader/Admin" replaced the emailed flow in `auth/forgot_password.php`. So:

- No reset email is sent today, whatever the `MAIL_*` variables are set to.
- `auth/reset_password.php` can only succeed for a token that already exists in `password_resets`.
- The README mentions an emailed link and the `MAIL_*` settings; treat that as describing dormant code.

## 8. CSRF protection

- Each session gets a random token (`bin2hex(random_bytes(32))`) at sign-in and again in `auth_guard.php` if missing.
- **Verification is done per endpoint, not centrally.** `auth_guard.php` only creates the token. Each write endpoint compares `$_SESSION['csrf_token']` with the request using `hash_equals`.
- Most endpoints read the HTTP header `X-CSRF-Token`. The helpers `cityApiContext()`, `nationalApiContext()` and `nationalFormContext()` in `helpers/CityApi.php` do the header check together with the method and role checks. `api/user/profile.php` reads `csrf_token` from the JSON body, and `auth/force_password.php` from the form field `csrf_token`.
- In the browser the token reaches scripts in a few ways: a `meta[name="csrf"]` tag or `window.__CSRF__` (`js/form.js`), or a `const CSRF = '...'` line in a nonce-protected inline script (for example `pages/admin.php`, `pages/admin_surveyors.php`).
- Read-only `GET` endpoints do not check the token. The full per-endpoint table of guard, method and CSRF handling is in the [API reference](api-reference.md).
- Adding a write endpoint: use one of the `CityApi.php` helpers, or copy the check from an existing endpoint, and send the header from your JavaScript.

## 9. Content-Security-Policy and the nonce

`sendCspHeader()` in `config/constants.php` is called by `auth_guard.php` and by the sign-in pages (`auth/login.php`, `register.php`, `forgot_password.php`), so every PHP-rendered HTML page gets the same policy. For each request it creates a random nonce, stores it in `$_SESSION['csp_nonce']` and puts the same value in the header.

| Directive | Value |
| --- | --- |
| `default-src` | `'self'` |
| `script-src` | `'self'` plus the request nonce (no `'unsafe-inline'`) |
| `style-src` (old-browser fallback) | `'self' 'unsafe-inline' https://fonts.googleapis.com` |
| `style-src-elem` | `'self' https://fonts.googleapis.com` plus the nonce |
| `style-src-attr` | `'unsafe-inline'` (so `style="..."` attributes still work) |
| `font-src` | `'self' https://fonts.gstatic.com` |
| `img-src` | `'self' data:` |
| `connect-src` | `'self'` |
| `object-src` | `'none'` |
| `base-uri`, `form-action` | `'self'` |
| `frame-ancestors` | `'self'` |

Rules for developers (more in [frontend](frontend.md)):

- Inline `<script>` and `<style>` blocks in PHP pages must carry `nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>"`.
- Inline event handlers (`onclick="..."`) do not work. The project uses `data-*` attributes with listeners in `js/`.
- `style="..."` attributes are allowed; `<style>` blocks need the nonce.
- The static landing page `index.html` cannot carry a nonce, so its policy comes from `.htaccess` (see [operations](operations.md)).

Other browser hardening headers (HSTS, frame protection, `nosniff`, referrer and permissions policies) are described in [operations](operations.md) and `SECURITY.md`.

## 10. Activity logging of auth events

`helpers/ActivityLogger.php` writes to `activity_log` (user id, action, JSON `meta`, IP, time) and never lets a logging failure break the request. Auth-related actions: `user_login`, `user_logout`, `user_login_failed`, `user_registered`, `password_changed`, `password_reset_requested`, `password_reset_completed`, `user_password_reset`, `password_request_approved`, `password_request_dismissed`, `user_activated`, `user_deactivated`, `user_role_changed`, `user_city_changed`, `profile_updated`, `team_member_added`, `team_member_removed`. Only a shortened email (100 characters) is stored for failed sign-ins.

## Known gaps / things that surprised me

- No emailed password reset is sent by the live code (section 7.4), although the README describes it.
- The lockout doubling to 24 hours looks unreachable by reading the code (section 5); confirm on a test database before relying on it.
- CSRF is checked endpoint by endpoint, so a new write endpoint that forgets the check is unprotected.
- Six permission names in `config/permissions.php` are never checked by `can()` or `gate()` (section 2).
- The session-timeout redirect assumes the app is at the web root (section 4).
- The login and register forms have no CSRF token.
- Some comments still use the pre-018 role names `national_admin` and `city_admin`.
