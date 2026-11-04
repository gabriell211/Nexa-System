#!/usr/bin/env python3
"""Generate an update manifest only when all native packages are signed."""
import argparse
import hashlib
import json
import re
from pathlib import Path
from urllib.parse import quote, urlparse

PACKAGES = {
    "windows-x86_64": "Nexa.Collector_{version}_x64-setup.exe",
    "linux-deb-x86_64": "Nexa-Collector-{version}-linux-x64.deb",
    "linux-rpm-x86_64": "Nexa-Collector-{version}-linux-x64.rpm",
    "darwin-x86_64": "NexaCollector-{version}-x86_64.pkg",
    "darwin-aarch64": "NexaCollector-{version}-arm64.pkg",
}
SEMVER = re.compile(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\Z")


def produce(version: str, directory: Path, url: str) -> tuple[dict, str]:
    if not SEMVER.fullmatch(version):
        raise ValueError("Use a stable SemVer version")
    parsed = urlparse(url)
    if parsed.scheme != "https" or parsed.netloc != "github.com" or parsed.query or parsed.fragment:
        raise ValueError("Only HTTPS GitHub release URLs are allowed")
    if parsed.path != f"/gabriell211/Nexa-System/releases/download/collector-v{version}":
        raise ValueError("Release tag or repository does not match")
    if not directory.is_dir() or directory.is_symlink():
        raise ValueError("Unsafe artifact directory")

    platforms, checksums = {}, []
    for platform, template in PACKAGES.items():
        name = template.format(version=version)
        path, sig = directory / name, directory / (name + ".sig")
        if not all(p.is_file() and not p.is_symlink() for p in (path, sig)):
            raise ValueError("Missing native package or signature: " + name)
        if not 102400 <= path.stat().st_size <= 400 * 1024 * 1024:
            raise ValueError("Unexpected native package size: " + name)
        raw = sig.read_bytes()
        if not 90 <= len(raw) <= 4096:
            raise ValueError("Malformed Minisign signature: " + name)
        signature = raw.decode("utf-8").strip()
        if not signature.startswith("untrusted comment:") or "\ntrusted comment:" not in signature:
            raise ValueError("Missing Minisign release signature: " + name)
        digest = hashlib.sha256()
        with path.open("rb") as source:
            for chunk in iter(lambda: source.read(1 << 20), b""):
                digest.update(chunk)
        checksums.append(f"{digest.hexdigest()}  {name}")
        platforms[platform] = {"url": url + "/" + quote(name), "signature": signature}
    return {"version": version, "platforms": platforms}, "\n".join(checksums) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", required=True)
    parser.add_argument("--assets", required=True, type=Path)
    parser.add_argument("--base-url", required=True)
    args = parser.parse_args()
    try:
        manifest, hashes = produce(args.version, args.assets, args.base_url)
    except (ValueError, UnicodeError, OSError) as error:
        parser.error(str(error))
    (args.assets / "NEXA_UPDATE_MANIFEST.json").write_text(
        json.dumps(manifest, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    (args.assets / "SHA256SUMS.txt").write_text(hashes, encoding="utf-8")
    print("Five signed packages validated; manifest created but not published.")


if __name__ == "__main__":
    main()
