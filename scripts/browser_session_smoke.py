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
import uuid
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


# Exercise inventory over a real HTTP/cookie/CSRF boundary as well as PHPUnit.
status, data = call("POST", "/stock/items", {
    "sku": "HTTP-SMOKE-TONER", "name": "HTTP inventory toner",
    "category": "toner", "unit": "un",
}, csrf)
expect(status, 201, "inventory item create")
item_id = data["data"]["id"]

status, data = call("POST", "/stock/locations", {
    "name": "HTTP smoke warehouse", "kind": "provider",
}, csrf)
expect(status, 201, "warehouse creation")
location_id = data["data"]["id"]

movement_id = str(uuid.uuid4())
receipt = {
    "request_id": movement_id, "kind": "receipt", "item_id": item_id,
    "source_location_id": None, "destination_location_id": location_id,
    "quantity": 2, "reason": "Smoke authenticated inventory receipt",
}
status, data = call("POST", "/stock/movements", receipt, csrf)
expect(status, 201, "stock receipt")
stock_movement_id = data["data"]["id"]
assert data["data"]["destination_after"] == 2

status, data = call("POST", "/stock/movements", receipt, csrf)
expect(status, 201, "idempotent duplicate receipt")
assert data["data"]["id"] == stock_movement_id

status, _ = call("POST", "/stock/movements", {
    **receipt, "quantity": 9,
}, csrf)
expect(status, 422, "conflicting retry must fail")

status, _ = call("POST", "/stock/movements", {
    "request_id": str(uuid.uuid4()), "kind": "issue", "item_id": item_id,
    "source_location_id": location_id, "destination_location_id": None,
    "quantity": 3, "reason": "Reject insufficient balance",
}, csrf)
expect(status, 422, "prevent negative stock")

status, data = call("GET", "/stock/balances")
expect(status, 200, "read stock positions")
assert len(data["data"]) == 1
assert data["data"][0]["quantity"] == 2

status, _ = call("POST", "/auth/logout", {}, csrf)
expect(status, 200, "server session logout")

status, _ = call("GET", "/auth/me")
expect(status, 401, "logout revokes session")

print("HTTP session+CSRF integration smoke passed")
