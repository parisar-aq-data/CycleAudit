"""
Real-browser (Playwright / Chromium) versions of the checks that used to be done by hand:
  1. a person registers, is blocked, the City Leader approves in the dashboard, the person signs in
  2. a password change in one browser signs the same account out of a second browser

Run with the app up (see tests/e2e/README.md). Skipped automatically if Playwright is not installed.
"""
import re

import pytest

pw = pytest.importorskip("playwright.sync_api")
from conftest import BASE_URL, LEADER_PUNE, PASSWORD, SURVEYOR_PUNE  # noqa: E402

NEW_EMAIL = "browser.person@test.local"
NEW_PASSWORD = "Changed@98765"


@pytest.fixture(scope="module")
def browser():
    with pw.sync_playwright() as p:
        b = p.chromium.launch()
        yield b
        b.close()


def new_context(browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Web fonts are decoration; never let a slow font host slow a test down.
    ctx.route(re.compile(r"https://fonts\.(googleapis|gstatic)\.com/.*"), lambda route: route.abort())
    ctx.set_default_timeout(15000)
    return ctx


def sign_in(page, email, password=PASSWORD):
    page.goto(f"{BASE_URL}/auth/login.php", wait_until="domcontentloaded")
    page.fill("#email", email)
    page.fill("#password", password)
    page.click("form#login-form button[type=submit]")


def test_signup_then_city_leader_approves_then_person_signs_in(browser):
    person = new_context(browser)
    leader = new_context(browser)
    try:
        # 1. register through the real form
        p = person.new_page()
        p.goto(f"{BASE_URL}/auth/register.php", wait_until="domcontentloaded")
        p.fill("#inp-name", "Browser Person")
        p.fill("#inp-age", "29")
        p.locator("label:has(input[name=gender][value=Female])").click()
        p.fill("#inp-city", "Pune")
        p.locator("#inp-city").dispatch_event("change")
        p.locator("#inp-city").blur()
        # the State box is filled by the page script; fall back to setting it if the script did not
        if not p.input_value("#inp-state"):
            p.evaluate("document.querySelector('#inp-state').value = 'Maharashtra'")
        p.fill("#inp-email", NEW_EMAIL)
        p.fill("#inp-phone", "9876543210")
        p.fill("#inp-org", "Browser Test Org")
        p.fill("#inp-pass", PASSWORD)
        p.fill("#inp-conf", PASSWORD)
        p.click("button.btn-submit")
        pw.expect(p.locator("body")).to_contain_text("request has been sent")

        # 2. blocked at sign-in with the waiting message
        sign_in(p, NEW_EMAIL)
        pw.expect(p.locator("#login-error")).to_contain_text("waiting for approval")

        # 3. the City Leader sees it and approves
        lp = leader.new_page()
        sign_in(lp, LEADER_PUNE)
        lp.wait_for_url(re.compile(r".*/pages/(city_)?dashboard\.php"))
        lp.goto(f"{BASE_URL}/pages/city_dashboard.php", wait_until="domcontentloaded")
        item = lp.locator(".su-item", has_text=NEW_EMAIL)
        pw.expect(item).to_be_visible()
        lp.once("dialog", lambda d: d.accept())
        item.locator(".su-approve").click()
        pw.expect(item).to_contain_text("Approved")

        # 4. the person can now sign in and lands on a dashboard
        sign_in(p, NEW_EMAIL)
        p.wait_for_url(re.compile(r".*/pages/dashboard\.php"))
        pw.expect(p.locator("body")).to_contain_text("Browser")
    finally:
        person.close()
        leader.close()


def test_password_change_in_one_browser_signs_out_the_other(browser):
    a_ctx = new_context(browser)
    b_ctx = new_context(browser)
    try:
        a, b = a_ctx.new_page(), b_ctx.new_page()
        sign_in(a, SURVEYOR_PUNE)
        a.wait_for_url(re.compile(r".*/pages/dashboard\.php"))
        sign_in(b, SURVEYOR_PUNE)
        b.wait_for_url(re.compile(r".*/pages/dashboard\.php"))
        a.wait_for_timeout(1200)   # one-second precision on the stored timestamps

        a.goto(f"{BASE_URL}/pages/profile.php", wait_until="domcontentloaded")
        a.click("button.tab-btn[data-tab=account]")
        a.fill("#f-cur-pw", PASSWORD)
        a.fill("#f-new-pw", NEW_PASSWORD)
        a.fill("#f-confirm-pw", NEW_PASSWORD)
        a.locator("button[onclick='changePassword()']").click()
        pw.expect(a.locator("#alert-account-msg")).to_contain_text("Password changed")

        # browser B: any protected page now sends it to the sign-in page
        b.goto(f"{BASE_URL}/pages/dashboard.php", wait_until="domcontentloaded")
        assert "/auth/login.php" in b.url, f"browser B was not signed out (at {b.url})"
        # browser A is still signed in
        a.goto(f"{BASE_URL}/pages/dashboard.php", wait_until="domcontentloaded")
        assert "/auth/login.php" not in a.url
    finally:
        a_ctx.close()
        b_ctx.close()
