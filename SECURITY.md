# Security

## Reporting a problem

Please open a **private security advisory** on this repository (Security tab, "Report a vulnerability") instead of a public issue. Include what you found, where, and how to reproduce it. Please do not test against real users' data on the live site.

## What protects CycleAudit

**Accounts and sessions**
- Passwords are stored with `password_hash`, minimum length 12.
- New self-registered accounts are held as `pending` until a City Leader or Admin approves them.
- Sessions are `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, with strict mode and an 8 hour lifetime. Changing a password ends the account's other sessions.
- Every request re-checks the user's role, active status and approval in the database, not just the session.
- Sign-in and sign-up are rate limited per IP: 5 failures in 15 minutes locks for 15 minutes, doubling on repeat up to 24 hours. Responses do not reveal whether an account exists.
- Write requests carry a CSRF token.

**Browser protections**
- `Content-Security-Policy` on every HTML page (`sendCspHeader()` in `config/constants.php`). Scripts run only from this site or with a fresh per-request nonce. Inline `<script>` blocks need that nonce, and inline event handlers (`onclick=` and similar) are not allowed, so new code should attach handlers in JavaScript files. Inline `<style>` blocks need the nonce too (`style-src-elem`).
- The public landing page (`index.html`) is a plain file served by Apache, so its policy is set in `.htaccess`. It allows scripts only from this site, with no inline scripts and no inline handlers. It has no `<style>` blocks, so `style-src-elem` allows only this site and Google Fonts; injected style blocks are blocked there too, and `style="..."` attributes stay allowed.
- Also set: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` (geolocation for our own origin only), `Strict-Transport-Security`.
- Output that comes from the database (for example road labels) is escaped before it reaches the page.

**Server and data**
- All SQL uses PDO prepared statements with emulation off.
- `.htaccess` and `.dockerignore` keep server-side folders and project files (`config/`, `migrations/`, `scripts/`, `composer.lock`, `Dockerfile` and similar) from being served. They return 403.
- Errors are logged on the server and never shown to the browser.
- Secrets live only in Railway environment variables, never in the repository.
- Daily encrypted-at-rest database backups go to a private bucket in Parisar's own Backblaze account. See [BACKUPS.md](./BACKUPS.md).

**Automated checks**
- A weekly dependency scan and Dependabot watch the PHP libraries.
- End-to-end tests run on every pull request and cover approvals, sessions, access control, lockout and the sign-in page policy. See `tests/e2e/README.md`.
- A smoke test checks the live site every 6 hours and after each deploy.

## Known limits

- `style="..."` attributes are still allowed (`style-src-attr 'unsafe-inline'`), because pages set colours and widths that way. Injected `<style>` blocks are blocked, injected style attributes are not.
- People who chose a shorter password before the minimum was raised to 12 keep it until they next change it. There is no check against lists of leaked passwords.
- Email-based password reset depends on the configured mail provider (`MAIL_*` variables). Without it, staff use the Reset password button.

## Safe changes

1. Work on a branch and open a pull request. Wait for all checks to pass.
2. Run database changes by hand in Railway's query box, one statement per query, **before** deploying code that needs them, after taking a backup.
3. After a deploy, open the live site with the browser console visible and watch for red "Content Security Policy" messages.
4. To undo a bad merge: `git revert -m 1 <merge commit>` and push.
