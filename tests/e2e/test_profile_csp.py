"""
H6 phase 2: the profile page must work with no inline event handlers and no
Content-Security-Policy violations. Real Chromium (Playwright), like test_browser_flows.py.
"""
import re

import pytest

pw = pytest.importorskip("playwright.sync_api")
from conftest import BASE_URL, PASSWORD, SURVEYOR_PUNE  # noqa: E402

FUNCTIONS = ["uploadAvatar", "sanitizePhoneInput", "saveProfile", "loadProfile",
             "updateEmail", "checkStrength", "changePassword"]

RECORD_VIOLATIONS = """
window.__csp = [];
document.addEventListener('securitypolicyviolation', function (e) {
    window.__csp.push(e.violatedDirective + ' <- ' + (e.blockedURI || 'inline'));
});
"""


@pytest.fixture(scope="module")
def browser():
    with pw.sync_playwright() as p:
        b = p.chromium.launch()
        yield b
        b.close()


@pytest.fixture
def profile_page(browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.route(re.compile(r"https://fonts\.(googleapis|gstatic)\.com/.*"), lambda route: route.abort())
    ctx.set_default_timeout(15000)
    ctx.add_init_script(RECORD_VIOLATIONS)
    page = ctx.new_page()
    page.goto(f"{BASE_URL}/auth/login.php", wait_until="domcontentloaded")
    page.fill("#email", SURVEYOR_PUNE)
    page.fill("#password", PASSWORD)
    page.click("form#login-form button[type=submit]")
    page.wait_for_url("**/dashboard.php*")
    page.goto(f"{BASE_URL}/pages/profile.php", wait_until="load")
    yield page
    ctx.close()


def test_profile_page_has_no_inline_event_handlers(profile_page):
    count = profile_page.evaluate(
        "() => [...document.querySelectorAll('*')]"
        ".filter(e => [...e.attributes].some(a => a.name.startsWith('on'))).length"
    )
    assert count == 0, f"{count} element(s) still have an inline on... handler"


def test_profile_controls_still_call_their_functions(profile_page):
    page = profile_page
    # Replace each real function with a counter; the page script looks them up when clicked.
    page.evaluate(
        "(names) => { window.__calls = {}; names.forEach(n => { window[n] = function () {"
        " window.__calls[n] = (window.__calls[n] || 0) + 1; }; }); }",
        FUNCTIONS,
    )

    with page.expect_file_chooser():
        page.click(".avatar-edit-btn")                      # opens the file picker
    sel = lambda a: f'[data-profile-action="{a}"]'          # noqa: E731
    page.dispatch_event(sel("upload-avatar"), "change")
    page.dispatch_event(sel("sanitize-phone"), "input")
    page.dispatch_event(sel("save-profile"), "click")
    page.dispatch_event(sel("reset-profile"), "click")
    page.dispatch_event(sel("update-email"), "click")
    page.dispatch_event(sel("check-strength"), "input")
    page.dispatch_event(sel("change-password"), "click")

    calls = page.evaluate("() => window.__calls")
    missing = [n for n in FUNCTIONS if not calls.get(n)]
    assert not missing, f"these controls no longer reach their function: {missing}"


def test_profile_page_has_no_csp_violations(profile_page):
    assert profile_page.evaluate("() => window.__csp") == []
