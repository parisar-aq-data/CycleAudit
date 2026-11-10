#!/usr/bin/env python3
"""
Post-deploy smoke test for the LIVE site. Read-only: it creates no accounts and changes nothing.

    python tests/smoke/smoke.py                       # checks https://cycleaudit.parisar.org
    python tests/smoke/smoke.py http://127.0.0.1:8080 # checks another copy

Exit code 0 = all good, 1 = something is wrong (the list says what).
Only needs the `requests` package.
"""
import sys
import time

import requests

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://cycleaudit.parisar.org").rstrip("/")
S = requests.Session()
S.headers["User-Agent"] = "cycleaudit-smoke"
failures = []


def check(name, ok, detail=""):
    print(("PASS  " if ok else "FAIL  ") + name + (f"  ({detail})" if detail else ""))
    if not ok:
        failures.append(name)


def get(path, **kw):
    kw.setdefault("allow_redirects", False)
    kw.setdefault("timeout", 20)
    t0 = time.time()
    r = S.get(BASE + path, **kw)
    return r, time.time() - t0


# 1. Health endpoint: the app is up and can reach its database
r, secs = get("/health.php")
try:
    h = r.json()
except ValueError:
    h = {}
check("health.php answers 200", r.status_code == 200, f"HTTP {r.status_code}")
check("health: status ok", h.get("status") == "ok", str(h.get("status")))
check("health: database ok", (h.get("checks") or {}).get("database") == "ok", str((h.get("checks") or {}).get("database")))
check("health answers within 3 s", secs < 3, f"{secs:.2f}s")

# 2. Public pages open
for path, needle in [("/", "CycleAudit"), ("/auth/login.php", "Sign in"), ("/auth/register.php", "Create account")]:
    r, secs = get(path)
    check(f"{path} opens", r.status_code == 200 and needle in r.text, f"HTTP {r.status_code}, {secs:.2f}s")

# 3. Protected things stay protected for a visitor who is not signed in
r, _ = get("/pages/dashboard.php")
check("dashboard sends visitors to sign in", r.status_code == 302 and "login.php" in r.headers.get("Location", ""),
      f"HTTP {r.status_code}")
for path in ("/api/admin/surveyors.php", "/api/admin/signup_requests.php", "/api/user/profile.php"):
    r, _ = get(path, headers={"Accept": "application/json"})
    leaked = '"success":true' in r.text.replace(" ", "")
    check(f"{path} gives a visitor no data", r.status_code in (302, 401, 403) and not leaked, f"HTTP {r.status_code}")

# 4. Things that must never be downloadable (Apache's .htaccess does this, so only meaningful on the live site)
if BASE.startswith("https://"):
    for path in ("/.env", "/.git/config", "/logs/app.log", "/composer.json", "/Dockerfile", "/migrations/032_user_approval.sql",
                 "/config/constants.php", "/helpers/Registration.php"):
        r, _ = get(path)
        # A missing file can still be answered with the site's own HTML page (HTTP 200). That is not a leak.
        # Only a non-HTML body (plain text, JSON, SQL, ...) counts as a real file being served.
        ctype = r.headers.get("Content-Type", "").lower()
        is_site_page = "text/html" in ctype and r.text.lstrip()[:15].lower().startswith(("<!doctype html", "<html"))
        exposed = r.status_code == 200 and r.text.strip() != "" and not is_site_page
        check(f"{path} is not exposed", not exposed, f"HTTP {r.status_code}, {ctype or 'no content-type'}")

    # 4b. Browser security headers set by .htaccess
    r, _ = get("/auth/login.php")
    for header, needle in [("X-Frame-Options", ""), ("X-Content-Type-Options", "nosniff"),
                           ("Strict-Transport-Security", "max-age"), ("Referrer-Policy", "")]:
        value = r.headers.get(header, "")
        check(f"header {header} is set", bool(value) and needle in value, value or "missing")

    # 4c. Content-Security-Policy: the landing page gets it from .htaccess, PHP pages from sendCspHeader()
    def directive(csp, name):
        return next((d.strip() for d in csp.split(";") if d.strip().startswith(name + " ")), "")

    for path in ("/", "/auth/login.php"):
        r, _ = get(path)
        csp = r.headers.get("Content-Security-Policy", "")
        script = directive(csp, "script-src")
        check(f"{path} sends a Content-Security-Policy", bool(csp), "missing" if not csp else "")
        check(f"{path} script-src does not allow 'unsafe-inline'", bool(script) and "'unsafe-inline'" not in script,
              script or "no script-src")
    r, _ = get("/")
    elem = directive(r.headers.get("Content-Security-Policy", ""), "style-src-elem")
    check("landing page: <style> blocks are blocked", bool(elem) and "'unsafe-inline'" not in elem,
          elem or "no style-src-elem")
    r, _ = get("/auth/login.php")
    elem = directive(r.headers.get("Content-Security-Policy", ""), "style-src-elem")
    check("sign-in page: <style> blocks need the nonce", "'nonce-" in elem and "'unsafe-inline'" not in elem,
          elem or "no style-src-elem")

# 5. HTTPS only (skipped for local http:// copies)
if BASE.startswith("https://"):
    try:
        plain = S.get("http://" + BASE[len("https://"):] + "/auth/login.php", allow_redirects=False, timeout=20)
        check("plain http redirects to https", plain.status_code in (301, 302, 307, 308)
              and plain.headers.get("Location", "").startswith("https://"), f"HTTP {plain.status_code}")
    except requests.RequestException as exc:
        check("plain http redirects to https", True, f"port 80 not reachable: {type(exc).__name__}")

print()
if failures:
    print(f"{len(failures)} check(s) FAILED: " + "; ".join(failures))
    sys.exit(1)
print("All smoke checks passed.")
