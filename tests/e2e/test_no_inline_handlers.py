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
