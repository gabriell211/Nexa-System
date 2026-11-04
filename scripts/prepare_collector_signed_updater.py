#!/usr/bin/env python3
"""Generate the signed Tauri updater overlay without storing private keys."""
import base64
import json
import os
import re
from pathlib import Path

pubkey = os.environ.get("NEXA_TAURI_PUBLIC_KEY", "").strip()
if not pubkey:
    raise SystemExit("NEXA_TAURI_PUBLIC_KEY must contain a real Minisign public key")
# Tauri expects base64 encoding of the *entire* .pub text, not merely the
# underlying 42-byte Minisign key. Accept either form and normalize.
if pubkey.startswith("untrusted comment:"):
    public_file = pubkey.encode("utf-8")
else:
    try:
        public_file = base64.b64decode(pubkey, validate=True)
    except ValueError as error:
        raise SystemExit("Public key must be a Tauri base64 .pub or raw .pub text") from error
try:
    lines = public_file.decode("utf-8").splitlines()
    if len(lines) != 2 or not lines[0].startswith("untrusted comment: minisign public key"):
        raise ValueError("Not a Minisign public key file")
    if not re.fullmatch(r"[A-Za-z0-9+/]{40,}={0,2}", lines[1]):
        raise ValueError("Malformed Minisign public key body")
    raw = base64.b64decode(lines[1], validate=True)
except (ValueError, UnicodeDecodeError) as error:
    raise SystemExit("Malformed Minisign public key file") from error
if len(raw) != 42 or raw[:2] != b"Ed":
    raise SystemExit("Minisign public key must contain a valid 42-byte Ed25519 record")
pubkey = base64.b64encode(public_file).decode("ascii")
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
