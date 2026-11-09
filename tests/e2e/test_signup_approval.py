"""Security review H1: a self-registered account cannot sign in until a City Leader or Admin approves it."""
from conftest import (ADMIN, LEADER_BLR, LEADER_PUNE, SURVEYOR_PUNE, Client, db_user, php_seed,
                      register, signed_in_as)

NEW = "new.person@test.local"


def test_registering_does_not_sign_the_person_in(anon):
    r = register(anon)
    assert r.status_code == 200
    assert "request has been sent" in r.text
    assert not anon.signed_in()
    row = db_user(NEW)
    assert row["is_active"] == 0 and row["approval_status"] == "pending"


def test_pending_account_cannot_sign_in_and_is_told_why(anon):
    register(anon)
    c = Client()
    r = c.login(NEW)
    assert r.status_code == 200
    assert "waiting for approval" in c.error_text(r)
    assert not c.signed_in()


def test_city_leader_of_the_same_city_can_approve(anon):
    register(anon, city="Pune", state="Maharashtra")
    uid = db_user(NEW)["id"]
    leader = signed_in_as(LEADER_PUNE)
    r = leader.api("/api/admin/signup_requests.php", {"action": "approve", "id": uid})
    assert r.status_code == 200 and r.json()["success"] is True
    assert Client().login_ok(NEW)
    assert db_user(NEW)["approval_status"] == "approved"


def test_request_appears_on_the_right_city_leaders_dashboard(anon):
    register(anon, city="Pune", state="Maharashtra")
    pune = signed_in_as(LEADER_PUNE).get("/pages/city_dashboard.php")
    blr = signed_in_as(LEADER_BLR).get("/pages/city_dashboard.php")
    assert NEW in pune.text
    assert NEW not in blr.text


def test_city_leader_of_another_city_cannot_approve(anon):
    register(anon, city="Pune", state="Maharashtra")
    uid = db_user(NEW)["id"]
    other = signed_in_as(LEADER_BLR)
    r = other.api("/api/admin/signup_requests.php", {"action": "approve", "id": uid})
    assert r.json()["success"] is False
    assert not Client().login_ok(NEW)


def test_a_surveyor_cannot_approve(anon):
    register(anon)
    uid = db_user(NEW)["id"]
    sv = signed_in_as(SURVEYOR_PUNE)
    r = sv.api("/api/admin/signup_requests.php", {"action": "approve", "id": uid})
    assert r.status_code in (302, 401, 403)
    assert not Client().login_ok(NEW)


def test_approving_needs_a_csrf_token(anon):
    register(anon)
    uid = db_user(NEW)["id"]
    leader = signed_in_as(LEADER_PUNE)
    r = leader.api("/api/admin/signup_requests.php", {"action": "approve", "id": uid}, csrf=False)
    assert r.status_code == 403
    r = leader.api("/api/admin/signup_requests.php", {"action": "approve", "id": uid}, csrf="0" * 64)
    assert r.status_code == 403
    assert not Client().login_ok(NEW)


def test_rejected_account_stays_out_and_gets_the_right_message(anon):
    register(anon)
    uid = db_user(NEW)["id"]
    leader = signed_in_as(LEADER_PUNE)
    r = leader.api("/api/admin/signup_requests.php", {"action": "reject", "id": uid})
    assert r.json()["success"] is True
    c = Client()
    resp = c.login(NEW)
    assert "not approved" in c.error_text(resp)
    # A City Leader cannot flip a rejection afterwards.
    again = leader.api("/api/admin/signup_requests.php", {"action": "approve", "id": uid})
    assert again.json()["success"] is False
    assert not Client().login_ok(NEW)


def test_an_admin_can_reactivate_a_rejected_account(anon):
    register(anon)
    uid = db_user(NEW)["id"]
    signed_in_as(LEADER_PUNE).api("/api/admin/signup_requests.php", {"action": "reject", "id": uid})
    admin = signed_in_as(ADMIN)
    r = admin.api("/api/admin/surveyors.php", {"id": uid, "is_active": True})
    assert r.status_code == 200 and r.json()["success"] is True
    assert Client().login_ok(NEW)
    assert db_user(NEW)["approval_status"] == "approved"


def test_sign_up_is_capped_per_network_per_hour():
    ok = 0
    for i in range(12):
        r = register(Client(), name="Cap Test", email=f"cap{i}@test.local")
        if "request has been sent" in r.text:
            ok += 1
    assert ok == 10, f"expected exactly 10 accepted sign-ups, got {ok}"


def test_bad_sign_up_details_are_refused(anon):
    r = register(anon, password="short1")
    assert "request has been sent" not in r.text
    r = register(anon, name="R2D2 99")
    assert "request has been sent" not in r.text
    r = register(anon, password="onlyletters")
    assert "request has been sent" not in r.text
    assert db_user(NEW) is None
