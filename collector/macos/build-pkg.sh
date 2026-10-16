#!/bin/bash
# Build full GUI + service installer for the CURRENT macOS host architecture.
set -euo pipefail
repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
collector="$repo_root/collector"
app="$collector/target/release/bundle/macos/Nexa Collector.app"
daemon="$collector/target/release/nexa-collector-daemon"
plist="$collector/macos/com.nexa.collector.plist"
config="$collector/config.example.json"
scripts="$collector/macos/pkg"
version="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["version"])' "$collector/console/package.json")"
architecture="$(uname -m)"
[[ "$architecture" = arm64 || "$architecture" = x86_64 ]] || { echo "Unsupported macOS architecture: $architecture" >&2; exit 1; }
[[ -d "$app" && -x "$daemon" ]] || { echo 'Build Tauri .app and daemon --release first' >&2; exit 1; }
/usr/bin/file -b "$daemon" | /usr/bin/grep -q Mach-O || { echo 'Collector daemon is not macOS Mach-O' >&2; exit 1; }
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
stage="$tmp/payload"
# GitHub Contents API stores new shell files as 0644; pkgbuild requires executable scripts.
buildscripts="$tmp/pkg-scripts"
mkdir -p "$buildscripts"
/usr/bin/install -m 0755 "$scripts/preinstall" "$buildscripts/preinstall"
/usr/bin/install -m 0755 "$scripts/postinstall" "$buildscripts/postinstall"
mkdir -p "$stage/Applications" "$stage/Library/PrivilegedHelperTools/NexaCollector"
mkdir -p "$stage/Library/Application Support/NexaCollector/launchd"
mkdir -p "$stage/Library/Application Support/NexaCollector/package"
/usr/bin/ditto "$app" "$stage/Applications/Nexa Collector.app"
/usr/bin/install -m 0755 "$daemon" "$stage/Library/PrivilegedHelperTools/NexaCollector/nexa-collector-daemon"
/usr/bin/install -m 0644 "$plist" "$stage/Library/Application Support/NexaCollector/launchd/com.nexa.collector.plist"
/usr/bin/install -m 0644 "$config" "$stage/Library/Application Support/NexaCollector/package/config.example.json"
/usr/bin/install -m 0750 "$collector/macos/activate.sh" "$stage/Library/Application Support/NexaCollector/package/activate.sh"
# pkgbuild otherwise treats .app bundles as relocatable and may install the app
# into the Tauri build directory instead of /Applications (notably on Intel).
component_plist="$tmp/components.plist"
/usr/bin/pkgbuild --analyze --root "$stage" "$component_plist"
/usr/bin/python3 - "$component_plist" <<'PY'
import plistlib
import sys

path = sys.argv[1]
with open(path, "rb") as source:
    components = plistlib.load(source)
matches = [
    component
    for component in components
    if component.get("RootRelativeBundlePath") == "Applications/Nexa Collector.app"
]
if len(matches) != 1:
    raise SystemExit("Expected exactly one Nexa Collector.app component in the package")
matches[0]["BundleIsRelocatable"] = False
with open(path, "wb") as destination:
    plistlib.dump(components, destination)
PY
mkdir -p "$collector/target/release/bundle/pkg"
output="$collector/target/release/bundle/pkg/NexaCollector-$version-$architecture-unsigned.pkg"
/usr/bin/pkgbuild --root "$stage" --component-plist "$component_plist" --install-location / --scripts "$buildscripts" --identifier com.nexa.collector.installer --version "$version" --ownership recommended "$output"
test -s "$output"
echo "Created unsigned Nexa Collector system installer: $output"
