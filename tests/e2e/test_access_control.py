"""Who may open what: anonymous visitors, surveyors, City Leaders and Admins; CSRF on writes; login lockout."""
import re
from pathlib import Path

import pytest

from conftest import (ADMIN, LEADER_PUNE, ROOT, SURVEYOR_PUNE, Client, PASSWORD, signed_in_as)

PROTECTED_PAGES = [
    "/pages/dashboard.php", "/pages/profile.php", "/pages/city_dashboard.php",
    "/pages/admin_surveyors.php", "/pages/admin.php",
]
PROTECTED_APIS = [
    "/api/admin/surveyors.php", "/api/admin/signup_requests.php", "/api/admin/roads.php",
    "/api/user/profile.php", "/api/dashboard/stats.php",
]


@pytest.mark.parametrize("path", PROTECTED_PAGES)
def test_anonymous_visitors_are_sent_to_sign_in(anon, path):
    r = anon.get(path)
    assert r.status_code == 302 and "login.php" in r.headers["Location"]


@pytest.mark.parametrize("path", PROTECTED_APIS)
def test_anonymous_visitors_get_no_api_data(anon, path):
    r = anon.get(path, headers={"Accept": "application/json"})
    assert r.status_code in (302, 401, 403)
    assert '"success":true' not in r.text.replace(" ", "")


def test_a_surveyor_cannot_open_admin_pages_or_apis():
    sv = signed_in_as(SURVEYOR_PUNE)
    r = sv.get("/pages/admin_surveyors.php")
    assert r.status_code == 302 and "login.php" not in r.headers["Location"]   # bounced to their dashboard
    r = sv.get("/api/admin/surveyors.php", headers={"Accept": "application/json"})
    assert r.status_code == 403


def test_a_city_leader_is_locked_out_of_admin_only_screens():
    leader = signed_in_as(LEADER_PUNE)
    r = leader.get("/api/admin/surveyors.php", headers={"Accept": "application/json"})
    assert r.status_code == 403, "City Leaders must not reach the Admin's Users API"
    r = leader.get("/pages/admin_surveyors.php")
    assert r.status_code in (302, 403)
    if r.status_code == 302:
        assert "admin_surveyors" not in r.headers["Location"]
    assert leader.get("/pages/city_dashboard.php").status_code == 200


def test_the_admin_can_open_the_users_page_and_api():
    admin = signed_in_as(ADMIN)
    assert admin.get("/pages/admin_surveyors.php").status_code == 200
    r = admin.get("/api/admin/surveyors.php", headers={"Accept": "application/json"})
    assert r.status_code == 200 and r.json()["success"] is True


@pytest.mark.parametrize("path,who", [
    ("/api/admin/surveyors.php", ADMIN),
    ("/api/admin/signup_requests.php", ADMIN),
    ("/api/admin/signup_requests.php", LEADER_PUNE),
])
def test_writes_without_a_csrf_token_are_refused(path, who):
    c = signed_in_as(who)
    r = c.api(path, {"id": 4, "action": "approve", "is_active": False}, csrf=False)
    assert r.status_code == 403


def test_every_post_api_checks_a_csrf_token():
    """Static scan: an API file that handles POST must mention csrf somewhere."""
    missing = []
    for f in sorted((ROOT / "api").rglob("*.php")):
        text = f.read_text(encoding="utf-8", errors="replace")
        handles_post = re.search(r"REQUEST_METHOD'\]\s*(===|!==|==)\s*'POST'", text)
        if handles_post and "csrf" not in text.lower():
            missing.append(str(f.relative_to(ROOT)))
    assert missing == [], f"POST endpoints without any CSRF check: {missing}"


def test_wrong_passwords_lock_the_sign_in_page_after_five_tries():
    c = Client()
    for _ in range(5):
        c.login(SURVEYOR_PUNE, "Wrong@1111")
    r = c.login(SURVEYOR_PUNE, PASSWORD)   # the RIGHT password is refused during the lockout
    assert r.status_code == 200
    assert not c.signed_in()
    assert "too many" in c.error_text(r).lower() or "try again" in c.error_text(r).lower()


def test_a_wrong_password_does_not_say_whether_the_email_exists():
    c = Client()
    known = c.error_text(c.login(SURVEYOR_PUNE, "Wrong@1111"))
    unknown = c.error_text(Client().login("nobody@test.local", "Wrong@1111"))
    strip = lambda s: re.sub(r"\(\d+ attempts? remaining.*\)", "", s).strip()
    assert strip(known) == strip(unknown)


@pytest.mark.xfail(strict=True, reason="Security review H6 still open: the sign-in pages send no Content-Security-Policy. "
                                       "This starts passing when that is fixed - then delete this marker. "
                                       "(Frame/nosniff/HSTS headers come from .htaccess and are checked by tests/smoke/smoke.py.)")
def test_the_sign_in_page_sends_a_content_security_policy(anon):
    r = anon.get("/auth/login.php")
    assert "content-security-policy" in {h.lower() for h in r.headers}
