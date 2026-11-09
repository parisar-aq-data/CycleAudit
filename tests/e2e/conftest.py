"""
Shared helpers for the end-to-end suite (tests/e2e).

The suite talks to a RUNNING copy of the app (BASE_URL, default http://127.0.0.1:8080)
backed by a throwaway database. It must never be pointed at production: seed.php
refuses to run unless the database name ends in "_test".
"""
import html
import os
import re
import subprocess
from pathlib import Path

import pytest
import requests

ROOT = Path(__file__).resolve().parents[2]
BASE_URL = os.environ.get("BASE_URL", "http://127.0.0.1:8080").rstrip("/")
PASSWORD = "Test@12345"

ADMIN = "admin@test.local"
LEADER_PUNE = "leader.pune@test.local"
LEADER_BLR = "leader.blr@test.local"
SURVEYOR_PUNE = "surveyor.pune@test.local"
SURVEYOR_BLR = "surveyor.blr@test.local"


def php_seed(*args: str) -> str:
    """Run tests/e2e/seed.php with the app's own database variables."""
    out = subprocess.run(
        ["php", str(ROOT / "tests" / "e2e" / "seed.php"), *args],
        capture_output=True, text=True, check=True, env=os.environ.copy(),
    )
    return out.stdout.strip()


def db_user(email: str):
    import json
    return json.loads(php_seed("user", email))


@pytest.fixture(autouse=True)
def fresh_database():
    """Every test starts from the same small seeded database and empty rate limits."""
    php_seed("seed")
    yield


class Client:
    """One browser-like session (its own cookies). Redirects are NOT followed by default."""

    def __init__(self):
        self.s = requests.Session()
        self.s.headers["User-Agent"] = "cycleaudit-e2e"

    def get(self, path, **kw):
        kw.setdefault("allow_redirects", False)
        return self.s.get(BASE_URL + path, **kw)

    def post(self, path, **kw):
        kw.setdefault("allow_redirects", False)
        return self.s.post(BASE_URL + path, **kw)

    def login(self, email, password=PASSWORD):
        return self.post("/auth/login.php", data={"email": email, "password": password})

    def login_ok(self, email, password=PASSWORD) -> bool:
        r = self.login(email, password)
        return r.status_code == 302 and "dashboard.php" in r.headers.get("Location", "")

    def error_text(self, response) -> str:
        m = re.search(r'id="login-error"[^>]*>(.*?)</div>', response.text, re.S)
        return html.unescape(m.group(1)).strip() if m else ""

    def signed_in(self) -> bool:
        """True when a protected page opens instead of bouncing to the sign-in page."""
        r = self.get("/pages/profile.php")
        return r.status_code == 200

    def csrf(self) -> str:
        r = self.get("/pages/profile.php")
        assert r.status_code == 200, f"not signed in (profile page gave {r.status_code})"
        m = re.search(r'const CSRF = "([0-9a-f]+)"', r.text)
        assert m, "CSRF token not found on the profile page"
        return m.group(1)

    def api(self, path, body, csrf=True):
        headers = {"Content-Type": "application/json", "Accept": "application/json"}
        if csrf is True:
            headers["X-CSRF-Token"] = self.csrf()
        elif csrf:
            headers["X-CSRF-Token"] = csrf
        return self.post(path, json=body, headers=headers)


def register(client: Client, name="Test Person", email="new.person@test.local", city="Pune",
             state="Maharashtra", password=PASSWORD, **over):
    data = {
        "name": name, "email": email, "phone": "9876543210", "organisation": "Test Org",
        "city": city, "state": state, "gender": "Male", "age": "30",
        "password": password, "confirm_password": password,
    }
    data.update(over)
    return client.post("/auth/register.php", data=data)


def signed_in_as(email) -> Client:
    c = Client()
    assert c.login_ok(email), f"could not sign in as {email}"
    return c


@pytest.fixture
def anon():
    return Client()
