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


# Real shipment workflow: reservation does not consume physical units,
# dispatch removes origin stock, and only confirmed receipt adds destination.
status, data = call("POST", "/stock/locations", {
    "name": "HTTP destination warehouse", "kind": "provider",
}, csrf)
expect(status, 201, "shipment destination warehouse")
destination_id = data["data"]["id"]

status, data = call("POST", "/stock/shipments", {
    "request_id": str(uuid.uuid4()), "item_id": item_id,
    "source_location_id": location_id, "destination_location_id": destination_id,
    "quantity": 2, "reason": "HTTP shipment smoke reservation",
}, csrf)
expect(status, 201, "reserve shipment stock")
shipment_id = data["data"]["id"]
assert data["data"]["reserved_quantity"] == 2

status, data = call("GET", "/stock/balances")
expect(status, 200, "reserved stock is still physical")
assert data["data"][0]["quantity"] == 2
assert data["data"][0]["available_quantity"] == 0

event_uuid = str(uuid.uuid4())
shipment_dispatch = {
    "request_id": event_uuid, "quantity": 2, "reason": "HTTP shipment dispatched",
}
status, data = call("POST", f"/stock/shipments/{shipment_id}/dispatch", shipment_dispatch, csrf)
expect(status, 200, "dispatch shipment")
assert data["data"]["in_transit_quantity"] == 2

status, data = call("POST", f"/stock/shipments/{shipment_id}/dispatch", shipment_dispatch, csrf)
expect(status, 200, "shipment dispatch remains idempotent")
assert data["data"]["shipped_quantity"] == 2

status, data = call("POST", f"/stock/shipments/{shipment_id}/receive", {
    "request_id": str(uuid.uuid4()), "quantity": 1, "reason": "Partial receipt at warehouse",
}, csrf)
expect(status, 200, "partial shipment receipt")
assert data["data"]["received_quantity"] == 1
assert data["data"]["in_transit_quantity"] == 1

status, data = call("POST", f"/stock/shipments/{shipment_id}/close", {
    "request_id": str(uuid.uuid4()), "reason": "Remainder confirmed lost in transit",
}, csrf)
expect(status, 200, "close shipment with shortage")
assert data["data"]["lost_quantity"] == 1
assert data["data"]["status"] == "closed_with_loss"

status, data = call("GET", "/stock/balances")
expect(status, 200, "inspect final shipment physical positions")
positions = {row["location_id"]: row["quantity"] for row in data["data"]}
assert positions[location_id] == 0
assert positions[destination_id] == 1

# Documented supplier receipt and compatibility checks through the actual HTTP API.
status, data = call("POST", "/stock/suppliers", {
    "code": "HTTP-SUPPLIER", "name": "HTTP smoke supplier",
    "email": "supplier@example.test",
}, csrf)
expect(status, 201, "register stock supplier")
supplier_id = data["data"]["id"]

status, data = call("POST", f"/stock/items/{item_id}/compatibilities", {
    "printer_manufacturer": "  Kyocera  ", "printer_model": "  MA5500    IFX  ",
}, csrf)
expect(status, 201, "register model compatibility")
assert data["data"]["printer_manufacturer"] == "kyocera"
assert data["data"]["printer_model"] == "ma5500 ifx"

receipt_id = str(uuid.uuid4())
documented = {
    "request_id": receipt_id, "supplier_id": supplier_id, "item_id": item_id,
    "destination_location_id": destination_id, "quantity": 2,
    "lot_number": "HTTP-LOT-10", "invoice_reference": "NF-HTTP-2026",
    "unit_cost_cents": 6899, "reason": "Documented purchase shipment receipt",
}
status, data = call("POST", "/stock/receipts", documented, csrf)
expect(status, 201, "documented lot receipt")
receipt_record_id = data["data"]["id"]
assert data["data"]["unit_cost_cents"] == 6899
assert data["data"]["movement"]["quantity"] == 2

status, data = call("POST", "/stock/receipts", documented, csrf)
expect(status, 201, "documented lot receipt idempotent retry")
assert data["data"]["id"] == receipt_record_id

status, _ = call("POST", "/stock/receipts", {
    **documented, "unit_cost_cents": 6999,
}, csrf)
expect(status, 422, "documented receipt cost conflict rejected")

status, data = call("GET", "/stock/balances")
expect(status, 200, "documented lot receipt affects stock ledger")
positions = {row["location_id"]: row["quantity"] for row in data["data"]}
assert positions[destination_id] == 3

# Lot allocation is observable in the real HTTP API, without inventing
# provenance for the legacy units received earlier.
status, data = call("GET", "/stock/lots")
expect(status, 200, "read tracked lot positions")
assert len(data["data"]) == 1
assert data["data"][0]["lot_number"] == "HTTP-LOT-10"
assert data["data"][0]["quantity"] == 2
assert data["data"][0]["expired"] is False

status, data = call("POST", "/stock/movements", {
    "request_id": str(uuid.uuid4()), "kind": "issue", "item_id": item_id,
    "source_location_id": destination_id, "destination_location_id": None,
    "quantity": 1, "reason": "FEFO consumption over real HTTP",
}, csrf)
expect(status, 201, "consume one tracked lot unit")
assert data["data"]["lot_allocations"][0]["lot_number"] == "HTTP-LOT-10"
assert data["data"]["lot_allocations"][0]["quantity"] == 1

status, data = call("GET", "/stock/lots")
expect(status, 200, "tracked lot balance after consumption")
assert data["data"][0]["quantity"] == 1

status, _ = call("POST", "/auth/logout", {}, csrf)
expect(status, 200, "server session logout")

status, _ = call("GET", "/auth/me")
expect(status, 401, "logout revokes session")

print("HTTP session+CSRF integration smoke passed")
