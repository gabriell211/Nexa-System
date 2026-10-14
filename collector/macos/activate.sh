#!/bin/bash
# Explicit activation AFTER config, key distribution and ACL review.
set -euo pipefail
umask 077
die() { printf '%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die 'Administrator privileges required.'
[[ "$#" -eq 0 ]] || die 'Usage: sudo bash collector/macos/activate.sh'
base='/Library/Application Support/NexaCollector'
staged="$base/launchd/com.nexa.collector.plist"
installed='/Library/LaunchDaemons/com.nexa.collector.plist'
daemon='/Library/PrivilegedHelperTools/NexaCollector/nexa-collector-daemon'

[[ -f "$staged" && ! -L "$staged" ]] || die 'Install the Collector and its staged plist first.'
[[ -f "$daemon" && -x "$daemon" && ! -L "$daemon" ]] || die 'Collector binary is missing or untrusted.'
[[ -f "$base/config.json" && ! -L "$base/config.json" ]] || die 'Collector configuration is missing.'
[[ ! -e "$installed" && ! -L "$installed" ]] || die 'LaunchDaemon already registered; refusing overwrite.'
if /bin/launchctl print system/com.nexa.collector >/dev/null 2>&1; then
    die 'Collector is already loaded by launchd.'
fi
/usr/bin/plutil -lint "$staged" >/dev/null || die 'Staged launchd plist is invalid.'

assert_secret() {
    local name="$1"
    local path="$base/credentials/$name"
    [[ -f "$path" && ! -L "$path" ]] || die "Missing credential file $name"
    [[ "$(/usr/bin/stat -f '%Su' "$path")" == 'root' ]] || die "Credential $name must belong to root"
    [[ "$(/usr/bin/stat -f '%Sg' "$path")" == '_nexacollector' ]] || die "Credential $name must use the service group"
    [[ "$(/usr/bin/stat -f '%Sp' "$path")" == '-rw-r-----' ]] || die "Credential $name must use permissions 0640"
    [[ "$(/usr/bin/stat -f '%z' "$path")" -le 4096 ]] || die "Credential $name exceeds 4096 bytes"
    [[ "$(/usr/bin/stat -f '%z' "$path")" -gt 0 ]] || die "Credential $name is empty"
}
assert_secret 'NEXA_COLLECTOR_TOKEN'
assert_secret 'NEXA_COLLECTOR_DB_KEY_HEX'
assert_secret 'NEXA_SNMP_COMMUNITY'

# The config itself is readable by the service account but not world readable.
[[ "$(/usr/bin/stat -f '%Su' "$base/config.json")" == 'root' ]] || die 'config.json must belong to root'
[[ "$(/usr/bin/stat -f '%Sg' "$base/config.json")" == '_nexacollector' ]] || die 'config.json must use the service group'
[[ "$(/usr/bin/stat -f '%Sp' "$base/config.json")" == '-rw-r-----' ]] || die 'config.json must use 0640'

/usr/bin/install -o root -g wheel -m 0644 "$staged" "$installed"
if ! /bin/launchctl bootstrap system "$installed"; then
    /bin/launchctl bootout system/com.nexa.collector >/dev/null 2>&1 || true
    /bin/rm -f "$installed"
    die 'launchctl bootstrap failed; copied plist removed. Inspect logs and configuration.'
fi

printf '%s\n' \
  'NexaCollector was explicitly activated with launchd.' \
  'Check: sudo launchctl print system/com.nexa.collector' \
  'Stop/unload: sudo launchctl bootout system/com.nexa.collector' \
  'Note: a loaded LaunchDaemon restarts on crashes and is eligible to start after reboot.'
