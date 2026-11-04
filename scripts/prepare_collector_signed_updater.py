#!/usr/bin/env python3
"""Generate the signed Tauri updater overlay without storing private keys."""
import base64
import json
import os
import re
from pathlib import Path

pubkey = os.environ.get("NEXA_TAURI_PUBLIC_KEY", "").strip()
if not pubkey or "\n" not in pubkey or "untrusted comment:" not in pubkey:
    raise SystemExit("NEXA_TAURI_PUBLIC_KEY must contain a real Minisign public key")
lines = pubkey.splitlines()
if len(lines) != 2 or not re.fullmatch(r"[A-Za-z0-9+/]{40,}={0,2}", lines[1]):
    raise SystemExit("Malformed Minisign public key; never use a placeholder")
try:
    raw = base64.b64decode(lines[1], validate=True)
except ValueError as error:
    raise SystemExit("Invalid public key base64") from error
if len(raw) != 42:
    raise SystemExit("Incorrect Minisign public key length")
if not (os.environ.get("TAURI_SIGNING_PRIVATE_KEY") or os.environ.get("TAURI_SIGNING_PRIVATE_KEY_PATH")):
    raise SystemExit("A protected Tauri signing private key is required for signed builds")
config = {
    "bundle": {"createUpdaterArtifacts": True},
    "plugins": {
        "updater": {
            "pubkey": pubkey,
            "endpoints": [
                "https://nexa-system-5nkp.vercel.app/api/v1/collector/updates/"
                "{{target}}/{{arch}}/{{current_version}}"
            ],
            "requireSignedVersion": True,
            "windows": {"installMode": "passive"},
        }
    },
}
output = Path(__file__).resolve().parents[1] / "collector/console/src-tauri/tauri.updater.generated.json"
output.write_text(json.dumps(config, ensure_ascii=False, indent=2) + "\n")
print("Signed update overlay prepared (public key only).")
