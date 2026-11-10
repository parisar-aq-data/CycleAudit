"""
Guard for H6 (Content-Security-Policy): pages that have been converted must not get
inline event handlers (onclick=, oninput=, ...) back, and every data-...-action label in
the page must have a handler in the matching JavaScript file.

Add a page to PAGES when you convert it.
"""
import re

import pytest
from conftest import ROOT

# page, label attribute, JavaScript file that attaches the handlers
PAGES = [
    ("pages/profile.php", "data-profile-action", "js/profile.js"),
    ("pages/segment.php", "data-segment-action", "js/segment-handlers.js"),
]

INLINE = re.compile(
    r"\son(click|change|input|focus|blur|keydown|keyup|keypress|submit|load|error|paste|"
    r"mouse[a-z]+|touch[a-z]+|drag[a-z]*|drop)\s*=",
    re.I,
)


@pytest.mark.parametrize("page,label,js", PAGES)
def test_converted_page_has_no_inline_handlers(page, label, js):
    text = (ROOT / page).read_text(encoding="utf-8")
    hits = [f"line {n}: {line.strip()[:90]}"
            for n, line in enumerate(text.splitlines(), 1) if INLINE.search(line)]
    assert not hits, f"{page} has inline event handlers again:\n" + "\n".join(hits)


@pytest.mark.parametrize("page,label,js", PAGES)
def test_every_label_has_a_handler(page, label, js):
    text = (ROOT / page).read_text(encoding="utf-8")
    code = (ROOT / js).read_text(encoding="utf-8")
    names = set(re.findall(re.escape(label) + r'="([\w-]+)"', text))
    assert names, f"no {label} labels found in {page}"
    missing = sorted(n for n in names if f"'{n}'" not in code)
    assert not missing, f"{page} uses {label} values with no handler in {js}: {missing}"

# --- JavaScript-built markup (segment page) ---
JS_CONVERTED = ["js/segment.js", "js/segment-roads.js"]
JS_INLINE = re.compile(r"\son(click|change|input|focus|blur|keydown|keyup|submit)\s*=\s*[^a-zA-Z\s]")


@pytest.mark.parametrize("path", JS_CONVERTED)
def test_converted_script_builds_no_inline_handlers(path):
    text = (ROOT / path).read_text(encoding="utf-8")
    hits = [f"line {n}: {line.strip()[:90]}"
            for n, line in enumerate(text.splitlines(), 1) if JS_INLINE.search(line)]
    assert not hits, f"{path} builds inline event handlers again:\n" + "\n".join(hits)


def test_every_segment_dyn_label_has_a_handler():
    code = (ROOT / "js/segment-handlers.js").read_text(encoding="utf-8")
    names = set()
    for path in JS_CONVERTED:
        names |= set(re.findall(r'data-segment-dyn="([\w-]+)"', (ROOT / path).read_text(encoding="utf-8")))
    assert names, "no data-segment-dyn labels found"
    missing = sorted(n for n in names if f"'{n}'" not in code)
    assert not missing, f"data-segment-dyn values with no handler in js/segment-handlers.js: {missing}"

# --- form page ---
FORM_PHP, FORM_JS, FORM_HANDLERS = "pages/form.php", "js/form.js", "js/form-handlers.js"


def test_form_page_has_no_inline_handlers():
    text = (ROOT / FORM_PHP).read_text(encoding="utf-8")
    hits = [f"line {n}: {line.strip()[:90]}"
            for n, line in enumerate(text.splitlines(), 1) if INLINE.search(line)]
    assert not hits, f"{FORM_PHP} has inline event handlers again:\n" + "\n".join(hits)


def test_form_script_builds_no_inline_handlers():
    text = (ROOT / FORM_JS).read_text(encoding="utf-8")
    hits = [f"line {n}: {line.strip()[:90]}"
            for n, line in enumerate(text.splitlines(), 1) if JS_INLINE.search(line)]
    assert not hits, f"{FORM_JS} builds inline event handlers again:\n" + "\n".join(hits)


def test_every_form_label_has_a_handler():
    code = (ROOT / FORM_HANDLERS).read_text(encoding="utf-8")
    static = set(re.findall(r'data-form-action="([\w-]+)"', (ROOT / FORM_PHP).read_text(encoding="utf-8")))
    dynamic = set(re.findall(r'data-form-dyn="([\w-]+)"', (ROOT / FORM_JS).read_text(encoding="utf-8")))
    assert static and dynamic, "no form labels found"
    missing = sorted(n for n in static | dynamic if f"'{n}'" not in code)
    assert not missing, f"form labels with no handler in {FORM_HANDLERS}: {missing}"


def test_form_handlers_load_before_form_js():
    text = (ROOT / FORM_PHP).read_text(encoding="utf-8")
    a, b = text.find("js/form-handlers.js"), text.find("js/form.js")
    assert a != -1 and b != -1 and a < b, "form-handlers.js must be loaded before form.js in form.php"

# --- dashboard and report pages ---
DASH_PHP, REPORT_PHP = "pages/dashboard.php", "pages/report.php"


def _inline_hits(rel):
    text = (ROOT / rel).read_text(encoding="utf-8")
    return [f"line {n}: {line.strip()[:90]}"
            for n, line in enumerate(text.splitlines(), 1) if INLINE.search(line)]


def test_dashboard_has_no_inline_handlers():
    hits = _inline_hits(DASH_PHP)
    assert not hits, f"{DASH_PHP} has inline event handlers again:\n" + "\n".join(hits)


def test_report_has_no_inline_handlers():
    hits = _inline_hits(REPORT_PHP)
    assert not hits, f"{REPORT_PHP} has inline event handlers again:\n" + "\n".join(hits)


def test_dashboard_inline_scripts_have_a_nonce():
    text = (ROOT / DASH_PHP).read_text(encoding="utf-8")
    bare = [m.group(0) for m in re.finditer(r"<script(?![^>]*\bsrc=)[^>]*>", text) if "nonce=" not in m.group(0)]
    assert not bare, f"{DASH_PHP} has inline <script> blocks without a nonce: {bare}"


def test_dashboard_and_report_labels_have_handlers():
    for php, js, attr in ((DASH_PHP, "js/dashboard-handlers.js", "data-dash-action"),
                          (REPORT_PHP, "js/report-handlers.js", "data-report-action")):
        text = (ROOT / php).read_text(encoding="utf-8")
        assert js in text, f"{php} does not load {js}"
        labels = set(re.findall(attr + r'="([\w-]+)"', text))
        code = (ROOT / js).read_text(encoding="utf-8")
        assert labels, f"no {attr} labels found in {php}"
        missing = sorted(n for n in labels if n not in code)
        assert not missing, f"labels with no handler in {js}: {missing}"

# --- sign-in pages nonce ---
def test_auth_inline_scripts_have_a_nonce():
    bare = []
    for rel in ("auth", "auth/partials"):
        for p in sorted((ROOT / rel).glob("*.php")):
            text = p.read_text(encoding="utf-8")
            for m in re.finditer(r"<script(?![^>]*\bsrc=)[^>]*>", text):
                if "nonce=" not in m.group(0):
                    bare.append(f"{p.relative_to(ROOT)}: {m.group(0)}")
    assert not bare, "inline <script> blocks without a nonce:\n" + "\n".join(bare)


def test_send_csp_header_creates_a_nonce():
    text = (ROOT / "config/constants.php").read_text(encoding="utf-8")
    body = text[text.index("function sendCspHeader"):]
    body = body[:body.index("function ", 10)] if "function " in body[10:] else body
    assert "csp_nonce" in body, "sendCspHeader() must create $_SESSION['csp_nonce'] for pages that do not use auth_guard"


def test_script_src_has_no_unsafe_inline():
    """H6 final step: scripts need the nonce. Both places that send a policy must agree."""
    for rel in ("config/constants.php", "pages/public_audit_report.php"):
        text = (ROOT / rel).read_text(encoding="utf-8")
        for line in text.splitlines():
            if "script-src" in line:
                assert "unsafe-inline" not in line, f"{rel}: script-src allows 'unsafe-inline' again:\n{line.strip()}"
        assert "'nonce-" in text, f"{rel}: script-src must carry the request's nonce"


# --- landing page (index.html) ---
def test_landing_page_has_no_inline_handlers_or_scripts():
    """index.html is served by Apache, so its policy comes from .htaccess and allows no inline scripts."""
    text = (ROOT / "index.html").read_text(encoding="utf-8")
    hits = [f"line {n}: {line.strip()[:90]}"
            for n, line in enumerate(text.splitlines(), 1) if INLINE.search(line)]
    assert not hits, "index.html has inline event handlers again:\n" + "\n".join(hits)
    inline = [m.group(0) for m in re.finditer(r"<script(?![^>]*\bsrc=)[^>]*>", text)
              if "speculationrules" not in m.group(0)]
    assert not inline, f"index.html has an inline <script> again (move it to a file in js/): {inline}"


def test_htaccess_sends_a_policy_for_the_landing_page():
    text = (ROOT / ".htaccess").read_text(encoding="utf-8")
    assert '<Files "index.html">' in text, ".htaccess must set a Content-Security-Policy for index.html"
    block = text[text.index('<Files "index.html">'):]
    block = block[:block.index("</Files>")]
    script = next(d for d in block.split(";") if "script-src" in d)
    assert "'self'" in script and "unsafe-inline" not in script, f"landing page script-src is too loose: {script.strip()}"


def test_landing_page_blocks_injected_style_blocks():
    """index.html has no <style> block, so the landing policy must not let one run (style-src-elem)."""
    html = (ROOT / "index.html").read_text(encoding="utf-8")
    assert not re.search(r"<style\b", html, re.I), "index.html has a <style> block; move it to css/landing.css"
    text = (ROOT / ".htaccess").read_text(encoding="utf-8")
    block = text[text.index('<Files "index.html">'):]
    block = block[:block.index("</Files>")]
    elem = next((d for d in block.split(";") if "style-src-elem" in d), "")
    assert elem, ".htaccess must set style-src-elem for the landing page"
    assert "unsafe-inline" not in elem, f"landing page style-src-elem allows 'unsafe-inline': {elem.strip()}"


# --- <style> blocks need the nonce too ---
def test_style_blocks_in_pages_have_a_nonce():
    """Browsers only run a <style> block that carries the request's nonce (style-src-elem).
    api/reports/export-pdf.php is not sent to a browser, it is turned into a PDF on the server."""
    bare = []
    for p in sorted(ROOT.rglob("*.php")):
        rel = p.relative_to(ROOT).as_posix()
        if rel.startswith(("vendor/", "tests/")) or rel == "api/reports/export-pdf.php":
            continue
        for m in re.finditer(r"<style(?![^>]*nonce=)[^>]*>", p.read_text(encoding="utf-8")):
            bare.append(f"{rel}: {m.group(0)}")
    assert not bare, "<style> blocks without a nonce:\n" + "\n".join(bare)


def test_style_src_elem_requires_the_nonce():
    for rel in ("config/constants.php", "pages/public_audit_report.php"):
        text = (ROOT / rel).read_text(encoding="utf-8")
        lines = [l for l in text.splitlines() if "style-src-elem" in l]
        assert lines, f"{rel}: style-src-elem is missing"
        assert all("unsafe-inline" not in l for l in lines), f"{rel}: style-src-elem allows 'unsafe-inline'"
