#!/usr/bin/env python3
"""Fail CI when a requirement or acceptance scenario silently disappears."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
README = (ROOT / "README.md").read_text(encoding="utf-8")
MATRIX = (ROOT / "docs/REQUIREMENT-VERIFICATION-MATRIX.md").read_text(encoding="utf-8")


def ids(text: str, pattern: str) -> Counter[str]:
    return Counter(re.findall(pattern, text, re.MULTILINE))


checks = {
    "README NX": (ids(README, r"^-\s*\[ \]\s*\*\*NX-(\d{3})\*\*"), {f"{i:03d}" for i in range(1, 325)}),
    "matrix NX": (ids(MATRIX, r"^\|\s*NX-(\d{3})\s*\|"), {f"{i:03d}" for i in range(1, 325)}),
    "README QA": (ids(README, r"^\|\s*QA-(\d{2})\s*\|"), {f"{i:02d}" for i in range(1, 63)}),
    "matrix QA": (ids(MATRIX, r"^\|\s*QA-(\d{2})\s*\|"), {f"{i:02d}" for i in range(1, 63)}),
}

failure = False
for label, (found, expected) in checks.items():
    missing = sorted(expected - found.keys())
    extra = sorted(found.keys() - expected)
    duplicate = sorted(identifier for identifier, count in found.items() if count != 1)
    if missing or extra or duplicate:
        failure = True
        print(f"[FAIL] {label}: missing={missing} extra={extra} repeated={duplicate}")
    else:
        print(f"[OK] {label}: {len(expected)} uniquely numbered entries")

sys.exit(1 if failure else 0)
