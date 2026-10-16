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
mkdir -p "$stage/Applications" "$stage/Library/PrivilegedHelperTools/NexaCollector"
mkdir -p "$stage/Library/Application Support/NexaCollector/launchd"
mkdir -p "$stage/Library/Application Support/NexaCollector/package"
/usr/bin/ditto "$app" "$stage/Applications/Nexa Collector.app"
/usr/bin/install -m 0755 "$daemon" "$stage/Library/PrivilegedHelperTools/NexaCollector/nexa-collector-daemon"
/usr/bin/install -m 0644 "$plist" "$stage/Library/Application Support/NexaCollector/launchd/com.nexa.collector.plist"
/usr/bin/install -m 0644 "$config" "$stage/Library/Application Support/NexaCollector/package/config.example.json"
mkdir -p "$collector/target/release/bundle/pkg"
output="$collector/target/release/bundle/pkg/NexaCollector-$version-$architecture-unsigned.pkg"
/usr/bin/pkgbuild --root "$stage" --install-location / --scripts "$scripts" --identifier com.nexa.collector.installer --version "$version" --ownership recommended "$output"
test -s "$output"
echo "Created unsigned Nexa Collector system installer: $output"
