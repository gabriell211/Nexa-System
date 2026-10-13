#!/usr/bin/env python3
"""HTTP integration smoke: real Laravel cookies and CSRF outside PHPUnit.

This uses a locally bootstrapped owner and temporary sqlite database. No external
credentials or third-party API requests are made.
"""
from __future__ import annotations

import http.cookiejar
import json
import os
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPCookieProcessor, Request, build_opener

BASE = os.environ.get("NEXA_SMOKE_URL", "http://127.0.0.1:8080/api/v1/browser")
PASSWORD = os.environ["NEXA_BOOTSTRAP_PASSWORD"]
EMAIL = os.environ["NEXA_BOOTSTRAP_EMAIL"]
TENANT = os.environ.get("NEXA_BOOTSTRAP_TENANT", "nexa-demo")
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))


def call(method: str, path: str, data: dict | None = None, csrf: str | None = None):
    headers = {"Accept": "application/json"}
    if csrf:
        headers["X-CSRF-TOKEN"] = csrf
    payload = None
    if data is not None:
        payload = json.dumps(data).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = Request(BASE + path, data=payload, headers=headers, method=method)
    try:
        response = client.open(req, timeout=8)
        status, body = response.status, response.read()
    except HTTPError as error:
        status, body = error.code, error.read()
    parsed = json.loads(body) if body else None
    return status, parsed


def expect(actual: int, wanted: int, label: str) -> None:
    if actual != wanted:
        raise AssertionError(f"{label}: expected HTTP {wanted}, received {actual}")
    print(f"[OK] {label}: {wanted}")


for attempt in range(35):
    try:
        call("GET", "/auth/csrf")
        break
    except (URLError, TimeoutError):
        time.sleep(0.3)
else:
    raise RuntimeError("Laravel HTTP server did not start")

status, data = call("GET", "/auth/csrf")
expect(status, 200, "CSRF bootstrap")
csrf = data["csrf_token"]
assert isinstance(csrf, str) and len(csrf) >= 10

status, _ = call("POST", "/auth/login", {
    "email": EMAIL, "password": PASSWORD, "tenant": TENANT,
})
expect(status, 419, "login without CSRF")

status, data = call("POST", "/auth/login", {
    "email": EMAIL, "password": PASSWORD, "tenant": TENANT,
}, csrf)
expect(status, 200, "login with CSRF")
assert data.get("role") == "owner"
assert "token" not in data, "browser API must not expose bearer credentials"

status, identity = call("GET", "/auth/me")
expect(status, 200, "session persists with cookie")
assert identity.get("role") == "owner"

status, _ = call("POST", "/customers", {"name": "Should not exist"})
expect(status, 419, "mutating data requires CSRF")

status, data = call("GET", "/auth/csrf")
expect(status, 200, "CSRF renewal after login")
csrf = data["csrf_token"]

status, data = call("POST", "/customers", {"name": "HTTP smoke customer"}, csrf)
expect(status, 201, "authorized customer create")
assert data.get("data", {}).get("name") == "HTTP smoke customer"

status, data = call("GET", "/customers")
expect(status, 200, "read client list using cookie")
assert any(item.get("name") == "HTTP smoke customer" for item in data["data"])

status, _ = call("POST", "/auth/logout", {}, csrf)
expect(status, 200, "server session logout")

status, _ = call("GET", "/auth/me")
expect(status, 401, "logout revokes session")

print("HTTP session+CSRF integration smoke passed")
