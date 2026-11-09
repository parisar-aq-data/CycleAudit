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
