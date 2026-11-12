# Frontend

How the browser side of CycleAudit is built: which page each role sees, which scripts run on it, how the Content-Security-Policy (CSP) nonce works, how the browser proves a request is genuine (CSRF), and how the audit form keeps a draft.

Related chapters: [auth and permissions](auth-and-permissions.md) (who may open what), [API reference](api-reference.md) (the endpoints these scripts call), [workflows](workflows.md) (what each screen is for).

Everything here was read from the code. Items that could not be proved are marked "(not verified)". The code is the final word.

## 1. How the frontend is built

- **No framework and no build step.** There is no `package.json`, bundler or transpiler in the repository. Pages are PHP files in `pages/` (and `auth/`) that print HTML. Behaviour is plain JavaScript in `js/`, loaded with ordinary `<script>` tags. Styles are plain CSS in `css/`.
- **Pages fetch data from `api/`.** A page renders its own shell on the server, and many screens then call an endpoint with `fetch` and fill in the rest (the surveyor dashboard calls `api/dashboard/stats.php`, for example). See the [API reference](api-reference.md).
- **Cache busting.** Every PHP page adds `?v=` plus the file's modified time to its CSS and JS links (`../js/form.js?v=<?= filemtime(...) ?>`), so a deploy changes the address and browsers fetch the new file. `.htaccess` lets browsers keep `css`, `js`, `png`, `jpg` and `woff2` files for a year (`max-age=31536000`), which is why this matters.
- **The home page is the exception.** `index.html` is a plain file that Apache serves, so PHP cannot stamp it. Its links carry hand-written numbers (`css/landing.css?v=17`, `js/landing.js?v=5`). Change the number by hand when you edit one of those files (section 10).
- **Fonts** come from Google Fonts (`fonts.googleapis.com` for the stylesheet, `fonts.gstatic.com` for the files). These are the only outside hosts the CSP allows.

## 2. Content-Security-Policy and the nonce

`sendCspHeader()` in `config/constants.php` sends the policy. `config/auth_guard.php` calls it for every protected page, and the sign-in pages in `auth/` call it too, so every PHP page gets the same policy.

**What the policy says.**

| Directive | Value | Effect |
| --- | --- | --- |
| `default-src` | `'self'` | Anything not listed below comes only from this site |
| `script-src` | `'self'` plus this request's nonce | Scripts run only from this site's files or with the nonce. There is no `'unsafe-inline'` |
| `style-src-elem` | `'self'`, Google Fonts, nonce | `<style>` blocks and `<link>` stylesheets need the nonce (or come from this site or Google Fonts) |
| `style-src-attr` | `'unsafe-inline'` | `style="..."` attributes still work, because pages set colours and widths that way |
| `style-src` | `'self' 'unsafe-inline'` and Google Fonts | Fallback for very old browsers only |
| `font-src` | `'self'`, `fonts.gstatic.com` | |
| `img-src` | `'self' data:` | Images from this site or inline `data:` images |
| `connect-src` | `'self'` | `fetch` may only talk to this site |
| `object-src`, `base-uri`, `form-action`, `frame-ancestors` | `'none'` / `'self'` | No plug-ins; forms post only here; only this site may frame a page |

**The nonce.** Each request makes a fresh random value, stores it in `$_SESSION['csp_nonce']` and puts the same value in the header. A page repeats it on every inline `<script>`, `<style>` and `<link>`:

```php
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  window.IS_ADMIN = <?= isAnyAdmin($CURRENT_USER_ROLE) ? 'true' : 'false' ?>;
</script>
```

A script an attacker injects into a page has no nonce, so the browser refuses to run it.

**What this means when you write code.**

- **No inline event handlers.** `onclick="..."`, `oninput="..."` and friends are blocked. Put a `data-*` attribute on the element and attach the listener in a script file (section 5 describes the pattern).
- **Inline scripts are for handing values over, not for logic.** The inline blocks that exist set `window.IS_ADMIN`, set `const CSRF`, toggle a sidebar or a popup, and add a `js` class on the login page. Anything longer lives in `js/`.
- **A script file loaded with `src` works without the nonce** because it comes from `'self'`. Most tags carry the nonce anyway. A few do not (`notifications.js` and `dashboard-handlers.js` on `dashboard.php`); that works.
- **Data for a script can travel as JSON in a script block with the nonce.** `pages/partials/my_team.php` writes its rows into `<script type="application/json" id="tmData" nonce="...">`, encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, so no value can close the tag. `js/my_team.js` reads it with `JSON.parse`.

**Pages with their own policy.**

| Page | Where the policy comes from | Difference |
| --- | --- | --- |
| `index.html` (home) | `.htaccess` (`<Files "index.html">`) | No nonce, because the file has no inline scripts or styles. Adds `'inline-speculation-rules'` so the small prerender hint for the login page can run |
| `pages/public_audit_report.php` (no sign-in) | Its own `header(...)` call with its own nonce | Lists fewer directives: no `object-src`, `base-uri`, `form-action` or `frame-ancestors` |
| Logo downloads (`helpers/Collaborators.php`) | `default-src 'none'; sandbox` | A logo file can run nothing |

## 3. CSRF in the browser

Every state-changing request must carry the session's CSRF token (see [auth and permissions](auth-and-permissions.md) for the server check). The token reaches the browser in four ways, depending on the page:

| How the page holds the token | Where | How scripts send it |
| --- | --- | --- |
| `<meta name="csrf" content="...">` | `form.php`, `segment.php` | `getCsrf()` in `js/form.js` and `js/segment.js` reads the meta tag and sends header `X-CSRF-Token` |
| Inline `const CSRF = '...'` | `dashboard.php`, `admin.php`, `admin_surveyors.php` | Scripts on the page use the global `CSRF` in the `X-CSRF-Token` header |
| `data-csrf="..."` on a page root or panel | `#npApp` (`platform_dashboard.php`, `collaborations.php`, `published_audits.php`), `#caApp` (`city_dashboard.php`, `city_audit.php`, `city_audit_report.php`, `city_segment_review.php`), and the panels in `my_team.php`, `password_requests.php`, `signup_requests.php` | The page's script reads `dataset.csrf` and sends `X-CSRF-Token` |
| In the request body | `profile.js` sends `csrf_token` in its JSON body. The server-rendered form in `auth/force_password.php` has a hidden `csrf_token` input | Not a header |

`js/notifications.js` is shared by two kinds of page, so it looks for the global `CSRF` first and falls back to `data-csrf` on `#caApp`.

Most scripts also send `X-Requested-With: XMLHttpRequest`. The auth guard uses that header (and `Accept: application/json`) to decide whether to answer a signed-out request with JSON `401` instead of a redirect to the login page.

## 4. Pages by role

`dashboard.php` is the surveyor's home. If the signed-in person is an Admin or a City Leader it sends them on to their own home (`helpers/RoleHome.php`): `platform_dashboard.php` for an Admin, `city_dashboard.php` for a City Leader.

**Everyone signed in**

| Page | What it shows | Scripts |
| --- | --- | --- |
| `profile.php` | Profile and settings | `city_search.js`, `location_fields.js`, `profile.js` |
| `view.php` | One audited segment, read only | none |
| `report.php` | Printable report for one road session | `report-handlers.js` |
| `road_result.php` | Per-segment scores and the final road score | none |

**Surveyor**

| Page | What it shows | Scripts |
| --- | --- | --- |
| `dashboard.php` | Stats, roads, notifications, assigned segments | `notifications.js`, `dashboard.js`, `dashboard-handlers.js` |
| `segment.php` | A road and its segments. A surveyor reaches it only with `?road_id=` or when returning from a submitted audit (`?status=done`); otherwise it sends them to `dashboard.php` | `segment.js`, `segment-roads.js`, `segment-handlers.js`, `project_watch.js` |
| `form.php` | The audit form for one segment. A surveyor may open only a segment assigned to them in a city audit; a deleted project sends them to `dashboard.php?notice=project_deleted` | `form-handlers.js`, `form.js`, `project_watch.js` |

**City Leader** (page list in `CITY_LEADER_ALLOWED_SCRIPTS`, `helpers/CityLeaderGate.php`)

| Page | What it shows | Scripts |
| --- | --- | --- |
| `city_dashboard.php` | Home: numbers, what needs attention, one card per audit | `notifications.js`, `confirm_box.js`, `city_audit.js` |
| `city_audit.php?id=N` | One audit: add roads, assign, review segments, close | `confirm_box.js`, `city_audit.js`, `admin_project.js`, `project_watch.js` |
| `city_segment_review.php` | One segment in full: approve or send back | `confirm_box.js`, `city_review.js`, `project_watch.js` |
| `city_audit_report.php` | Overall report for an audit | `confirm_box.js`, `city_review.js` |
| `city_audit_report_detail.php` | Printable detailed report | none |
| `city_completed.php` | Finished (published) audits of their own projects | none |
| `city_team.php` | "My team": which surveyors are in their team | `my_team.js` (from the partial) |
| `admin.php` | The city's road list (this is the City Leader's roads page; an Admin is sent home) | inline script with `CSRF` |

**Admin**

| Page | What it shows | Scripts |
| --- | --- | --- |
| `platform_dashboard.php` | Home: what needs attention, every audit, every city | `city_search.js`, `admin_new_project.js`, `admin_dashboard_delete.js` |
| `published_audits.php` | What visitors see in the home page's audit list; unpublish or delete | `city_search.js`, `admin_published.js`, `admin_published_filters.js` |
| `collaborations.php` | Partner organisations shown on the home page | `admin_collaborations.js` |
| `admin_surveyors.php` | Surveyor list panel | inline script with `CSRF` |
| `admin_activity.php` | Read-only activity log (not on the City Leader allow-list) | inline scripts only |

Pages that an Admin and a City Leader both open (`city_audit.php`, `city_audit_report.php`, `city_audit_report_detail.php`, `city_segment_review.php`, `city_completed.php`, `city_dashboard.php`) set `$isNational = $CURRENT_USER_ROLE === 'admin'`. The audit pages send each role to its own home when the audit is missing, deleted or not theirs (`projectOpenableBy(...)`). An Admin opens `city_dashboard.php` and `city_completed.php` for one city with `?city_id=N`; without it they are sent to `platform_dashboard.php` and `published_audits.php`.

**Partials** (`pages/partials/`) are not pages: `role_sidebar.php` (shared sidebar, with a small inline script for the user popup), `notifications.php`, `surveyor_work.php` ("My assigned segments"), `password_requests.php` and `signup_requests.php` (approver panels), `my_team.php`, `city_detailed_report.php`, and `cx_icons.php` (`cxIcon('road')` returns an inline SVG).

**Sign-in pages** (`auth/`) use a shared shell (`auth/partials/shell_top.php`, `shell_bottom.php`, `wheel.php`). `login.php` loads `login.js`; `register.php` loads `city_search.js`, `location_fields.js`, `auth_simple.js` and `register.js`; `auth_simple.js` is loaded by the shared shell (`shell_bottom.php`) as well as by `register.php`. The login and register pages are covered in [auth and permissions](auth-and-permissions.md).

**Public pages (no sign-in)**: `index.html` (section 9) and `pages/public_audit_report.php`.

## 5. Scripts

**The handler pattern (replaces inline `onclick`).** Because inline handlers are blocked, pages mark elements with `data-*` attributes and a small `*-handlers.js` file wires them up:

| Attribute | Handler file | Example |
| --- | --- | --- |
| `data-dash-action="toggle-user-menu"` | `dashboard-handlers.js` | Dashboard user menu, close the delete box |
| `data-report-action="print"` | `report-handlers.js` | Print button |
| `data-segment-action="..."` | `segment-handlers.js` | Search a road, generate segments, finalize, edit-road modal |
| `data-segment-dyn="..."` (+ `data-seg-id`) | `segment-handlers.js` | Buttons that JavaScript builds later; one delegated click listener finds them |
| `data-form-dyn="..."` and per-control listeners | `form-handlers.js` | Audit form controls, the "Discard" button on the draft banner |

To add a button: give it the attribute, add a line to the handler's table, never add `onclick`.

**Script by job**

| Script | Job |
| --- | --- |
| `form.js` (1,253 lines) | The audit form: counters, obstruction lists, intersection cards, GPS fill, validation, submit, reset, edit-mode prefill, draft autosave |
| `segment.js` | Road and segment definition: create the audit session, load and show segments, save a road, finalize, edit a road |
| `segment-roads.js` | The searchable road picker on `segment.php` (reads `api/roads/groups.php`) |
| `dashboard.js` | Surveyor dashboard: loads `api/dashboard/stats.php`, draws the roads table, deletes a road |
| `notifications.js` | Open and close the notifications panel, mark one or all as read |
| `city_audit.js`, `city_review.js` | City Leader screens: audit actions, segment approve or send back |
| `admin_project.js`, `admin_new_project.js`, `admin_dashboard_delete.js` | Admin project actions: delete a project (type its name to confirm), the "+ New Project" form, dashboard delete |
| `admin_published.js`, `admin_published_filters.js`, `admin_collaborations.js` | Published audit list and partner organisations |
| `my_team.js`, `password_requests.js`, `signup_requests.js` | The three approver panels |
| `city_search.js`, `location_fields.js` | Searchable city dropdown (`CitySearch.attach(...)`) and the City and State boxes, used at sign-up, on the profile and in New Project |
| `confirm_box.js` | Styled confirmation dialog: `await caConfirm({ title, message, confirmText, danger })` resolves `true` or `false`. Text is set with `textContent` |
| `profile.js`, `login.js`, `register.js`, `auth_simple.js` | Profile editing and the sign-in pages |
| `project_watch.js` | Deleted-project watcher (section 8) |
| `landing.js`, `landing_audits.js`, `landing_cities.js`, `landing_collab.js` | Home page (section 9) |

**How scripts call the API.** Plain `fetch` with the CSRF header (section 3), JSON in and out. `segment.js` wraps this in `apiFetch(url, method, body)`: it reads the reply as text first, and if the text is not JSON it returns `{ success: false, error: 'Server error (HTTP n). Check Railway logs.' }` instead of throwing. `dashboard.js` does the same read-then-parse and shows a toast.

## 6. Styles

- `css/theme.css` holds the colour variables (`--bg0`, `--txt1`, and so on) used by the dashboard and admin pages. Pages that need them link it.
- Admin and City Leader pages link `theme.css` + `dashboard.css`, and the City Leader screens add `city_audit.css` and `city_dashboard.css`.
- The surveyor screens have one sheet each: `segment.css` (+ `segment-dropdown.css`), `form.css` (+ `form-overlay.css`), `report.css` (+ `report_detail.css`), `view.css` (+ `view-inline.css`, `road-result.css`), `profile.css`.
- The sign-in pages use `login.css` and `register.css`; the home page uses `landing.css`.
- `css/modules/form-state.css` and `css/modules/toast.css` exist; I found no page or sheet that references them (not verified, they may be unused).

## 7. The audit form draft (autosave)

An audit takes time and phones lose signal, so `FormStateManager` in `js/form.js` keeps a draft in the browser's `localStorage`. Nothing is sent to the server until the surveyor submits.

- **Key and lifetime.** `parisar_form_<segment_id>` (or `parisar_form_noseg`). A saved draft is thrown away when it is older than 24 hours or belongs to a different segment.
- **When it saves.** On every `input` and `change` event, and every 30 seconds.
- **What it saves.** Text, number and textarea fields; the checked radio in each group; checked checkboxes per name; and the intersection cards.
- **When it restores.** About 100 ms after the page loads, and only when the form is **not** in edit mode (`edit_mode=1` loads the saved audit from `api/segments/audit-data.php` instead). A banner reads "Draft restored from your last session" with a Discard button that clears the draft.
- **When it clears.** After a successful submit, when the form is reset, and when Discard is pressed.
- **Leaving the page.** A separate guard marks the form dirty on any input and asks the browser to warn before the person leaves; the flag resets when the draft is cleared.
- **Submit.** `submitFullAudit()` validates, builds a `FormData` (adding the intersections as a JSON string), and POSTs to `api/segments/submit.php` with `X-CSRF-Token`. On success it clears the draft, shows a toast, and after 900 ms goes to `segment.php?segment_id=...&status=done`.
- **GPS.** The GPS fields are read-only and fill only from the GPS button (`fillGPS`), which uses the browser's location and needs HTTPS (or `localhost`). Errors for denied, unavailable or timed-out location are shown under the field.

## 8. The deleted-project watcher

`js/project_watch.js` asks the server every 8 seconds whether the Admin has deleted the project the person is working in. It reads its target from its own tag: `data-audit-id`, `data-road-id` or `data-segment-id`, plus `data-home` (where to send the person).

- It calls `api/project_status.php` and treats a `410`, or `success` with `deleted`, as "deleted".
- It does nothing while the tab is hidden and checks at once when the tab becomes visible again.
- When triggered it covers the page with a dialog ("This project was deleted") and a button to the dashboard. Any other failure is ignored and retried.
- Loaded by `form.php` (when a segment is open), `segment.php` (for surveyors on an assigned road), `city_audit.php` and `city_segment_review.php`.

## 9. The home page

`index.html` is static. `landing.js` handles the page itself; `landing_audits.js` calls `/api/public/audits.php`; `landing_collab.js` calls `/api/public/collaborators.php` (low priority); `landing_cities.js` draws one tile per city that has a published audit, and a tap filters the audit list. Both public endpoints need no sign-in (see the [API reference](api-reference.md)).

## 10. Browser storage

| Where | Key | Used by | Holds |
| --- | --- | --- | --- |
| `localStorage` | `parisar_form_<segment_id>` | `form.js` | The audit draft (section 7) |
| `localStorage` | `CACHE_KEY` in `landing_collab.js` | Home page | The last list of partner organisations, drawn at once on a repeat visit, then refreshed |
| `sessionStorage` | `ntOpen` | `notifications.js` | Whether the notifications panel was open |
| `sessionStorage` | `OPEN_KEY` in `city_audit.js` | City audit screen | A list of ids, read and removed after a reload |

All reads and writes are wrapped in `try`/`catch`, so a browser with storage blocked still works.

## 11. Writing safe output

- Text that people type (road names, landmarks, names) must show as text. `segment.js` uses `segEsc()`, `dashboard.js` uses `escHtml()`, and `segment-roads.js` has its own escape function before building HTML; most other scripts use `textContent`.
- Rule: if a value can come from a person or the database, either set it with `textContent` or pass it through the page's escape function before it goes into `innerHTML`. Numbers that the server computed are used as they are.
- `innerHTML` is used in `form.js` (8 places), `segment.js` (6), `segment-roads.js`, `dashboard.js`, `profile.js`, `landing_cities.js` and `admin_new_project.js`. The ones I read either use fixed text or escape the values; I did not trace every call in `form.js` (not verified).

## 12. Adding a page or a script

1. Start the PHP page with the right guard (`config/auth_guard.php`, or `config/admin_guard.php` for Admin and City Leader pages) and add a role check that redirects with `roleHomePage()`.
2. If a City Leader should open it, add `pages/<name>.php` to `CITY_LEADER_ALLOWED_SCRIPTS`.
3. Put the nonce on every inline `<script>`, `<style>` and `<link>`: `nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>"`.
4. Put logic in `js/<name>.js` and link it with `?v=<?= filemtime(__DIR__ . '/../js/<name>.js') ?>`.
5. Pass the CSRF token one of the ways in section 3 and send it as `X-CSRF-Token` on every POST, PUT or DELETE.
6. Use `data-*` attributes for clicks, never `onclick`.
7. Escape or `textContent` anything a person typed.
8. If the screen works on a project, load `project_watch.js` with the right `data-*` attribute, after checking section 13 for the City Leader limit.

## 13. Known gaps and things that surprised me

- **The draft saves intersection cards but never restores them.** `save()` writes `data.__intersections`, but `restore()` skips every key that starts with `__` and handles only `__radios` and `__checks`. After a reload, the text fields, radios and checkboxes come back; intersection cards the surveyor had added do not. Found by reading; not tested in a browser.
- **The draft key has no user in it.** Two people signing in on the same browser and opening the same segment id would share a draft, and logout does not clear it. Segments are assigned to one surveyor, so this needs a shared device; I did not test it.
- **The project watcher does not work for City Leaders.** `project_watch.js` is loaded on `city_audit.php` and `city_segment_review.php`, but `api/project_status.php` is not on the City Leader allow-list, so the leader's browser gets `403` and the script ignores it. A leader never sees the popup; their next write still answers `410`. Fix: add `api/project_status.php` to `CITY_LEADER_ALLOWED_SCRIPTS`. (Same finding as in the [API reference](api-reference.md).)
- **Four ways to hold the CSRF token** (section 3). A single pattern, such as the meta tag on every page, would remove the chance of forgetting it on a new screen.
- **Home page versions are manual.** Edit `landing.css` or a `landing*.js` file and forget the `?v=` number in `index.html`, and visitors keep the old file for up to a year.
- **The public report page has a shorter policy** than the rest (section 2).
- **`css/modules/` has no references** that I could find (section 6).
