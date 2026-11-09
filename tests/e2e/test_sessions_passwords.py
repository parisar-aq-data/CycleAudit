"""Security review H3: changing a password ends other sessions; the check is rate limited."""
import time

from conftest import ADMIN, SURVEYOR_PUNE, Client, PASSWORD, signed_in_as

NEW_PASSWORD = "Changed@98765"


def _change(client, current, new):
    return client.post("/api/user/profile.php", json={
        "action": "change_password", "csrf_token": client.csrf(),
        "current_password": current, "new_password": new, "confirm_password": new,
    }, headers={"Accept": "application/json"})


def test_changing_the_password_signs_out_every_other_session():
    a = signed_in_as(SURVEYOR_PUNE)
    b = signed_in_as(SURVEYOR_PUNE)
    assert a.signed_in() and b.signed_in()
    time.sleep(1.2)   # sessions and password_changed_at are stored with one-second precision
    r = _change(a, PASSWORD, NEW_PASSWORD)
    assert r.status_code == 200 and r.json()["success"] is True
    assert not b.signed_in(), "the other browser should have been signed out"
    assert a.signed_in(), "the browser that changed the password stays signed in"


def test_old_password_stops_working_and_the_new_one_works():
    a = signed_in_as(SURVEYOR_PUNE)
    assert _change(a, PASSWORD, NEW_PASSWORD).json()["success"] is True
    assert not Client().login_ok(SURVEYOR_PUNE, PASSWORD)
    assert Client().login_ok(SURVEYOR_PUNE, NEW_PASSWORD)


def test_wrong_current_password_attempts_are_limited_per_account():
    a = signed_in_as(SURVEYOR_PUNE)
    codes = [_change(a, "Wrong@1111", NEW_PASSWORD).status_code for _ in range(5)]
    assert all(c == 400 for c in codes), codes
    assert _change(a, "Wrong@1111", NEW_PASSWORD).status_code == 429
    # even the right current password is now refused until the lockout ends
    assert _change(a, PASSWORD, NEW_PASSWORD).status_code == 429


def test_changing_the_password_needs_a_csrf_token():
    a = signed_in_as(SURVEYOR_PUNE)
    r = a.post("/api/user/profile.php", json={
        "action": "change_password", "csrf_token": "nope",
        "current_password": PASSWORD, "new_password": NEW_PASSWORD, "confirm_password": NEW_PASSWORD,
    }, headers={"Accept": "application/json"})
    # The endpoint sets 403 and then json_out() overrides it to 400; either way the request is refused.
    assert r.status_code in (400, 403)
    assert r.json()["success"] is False
    assert Client().login_ok(SURVEYOR_PUNE, PASSWORD), "the password must not have changed"


def test_a_deactivated_person_is_signed_out_on_their_next_request():
    sv = signed_in_as(SURVEYOR_PUNE)
    assert sv.signed_in()
    admin = signed_in_as(ADMIN)
    r = admin.api("/api/admin/surveyors.php", {"id": 4, "is_active": False})
    assert r.status_code == 200 and r.json()["success"] is True
    assert not sv.signed_in()
    assert not Client().login_ok(SURVEYOR_PUNE)
