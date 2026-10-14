#!/bin/bash
# Installs files but intentionally DOES NOT register a boot-time LaunchDaemon.
# Administrator must provision credentials and run activate.sh separately.
set -euo pipefail
umask 077

die() { printf '%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die 'Administrator privileges required.'
[[ "$#" -eq 1 ]] || die 'Usage: sudo bash collector/macos/install.sh /absolute/path/to/nexa-collector-daemon'
binary="$1"
[[ "$binary" = /* && -f "$binary" && -x "$binary" ]] || die 'Specify an absolute path to an executable macOS binary.'
command -v launchctl >/dev/null || die 'This installer requires macOS launchd.'
command -v dscl >/dev/null || die 'Directory Services CLI is required.'
/usr/bin/file -b "$binary" | /usr/bin/grep -q 'Mach-O' || die 'Expected a compiled Mach-O executable, not a script or Linux binary.'

base='/Library/Application Support/NexaCollector'
binary_dir='/Library/PrivilegedHelperTools/NexaCollector'
target_plist='/Library/LaunchDaemons/com.nexa.collector.plist'
log_dir='/Library/Logs/NexaCollector'
script_dir="$(cd -- "$(dirname -- "$0")" && pwd)"

[[ ! -e "$base" && ! -L "$base" ]] || die 'Collector directory exists: inspect and use a separate reviewed upgrade procedure.'
[[ ! -e "$binary_dir" && ! -L "$binary_dir" ]] || die 'Collector executable directory exists: refusing overwrite.'
[[ ! -e "$target_plist" && ! -L "$target_plist" ]] || die 'LaunchDaemon already staged/installed in system directory.'
[[ ! -e "$log_dir" && ! -L "$log_dir" ]] || die 'Collector logs already exist: inspect before installing.'
! dscl . -read /Users/_nexacollector >/dev/null 2>&1 || die 'Dedicated service account already exists: inspect before installing.'
! dscl . -read /Groups/_nexacollector >/dev/null 2>&1 || die 'Dedicated service group already exists: inspect before installing.'

# Choose a free, system-range UID/GID by checking BOTH Directory Services namespaces.
uid_list="$(dscl . -list /Users UniqueID | /usr/bin/awk '{print $2}')"
gid_list="$(dscl . -list /Groups PrimaryGroupID | /usr/bin/awk '{print $2}')"
selected=''
for candidate in $(/usr/bin/seq 430 499); do
    if ! printf '%s\n' "$uid_list" | /usr/bin/grep -qx "$candidate" &&
       ! printf '%s\n' "$gid_list" | /usr/bin/grep -qx "$candidate"; then
        selected="$candidate"
        break
    fi
done
[[ -n "$selected" ]] || die 'No free system-range service UID/GID was found.'

# A dedicated non-login identity isolates credentials and the local database.
dscl . -create /Groups/_nexacollector
dscl . -create /Groups/_nexacollector PrimaryGroupID "$selected"
dscl . -create /Groups/_nexacollector RealName 'Nexa Collector Service'
dscl . -create /Users/_nexacollector
dscl . -create /Users/_nexacollector UniqueID "$selected"
dscl . -create /Users/_nexacollector PrimaryGroupID "$selected"
dscl . -create /Users/_nexacollector UserShell /usr/bin/false
dscl . -create /Users/_nexacollector NFSHomeDirectory /var/empty
dscl . -create /Users/_nexacollector RealName 'Nexa Collector Service'
dscl . -create /Users/_nexacollector IsHidden 1

/usr/bin/install -d -o root -g _nexacollector -m 0750 "$base"
/usr/bin/install -d -o _nexacollector -g _nexacollector -m 0700 "$base/data"
/usr/bin/install -d -o root -g _nexacollector -m 0750 "$base/credentials"
/usr/bin/install -d -o root -g wheel -m 0700 "$base/launchd"
/usr/bin/install -d -o _nexacollector -g _nexacollector -m 0700 "$log_dir"
/usr/bin/install -d -o root -g wheel -m 0755 "$binary_dir"
/usr/bin/install -o root -g wheel -m 0755 "$binary" "$binary_dir/nexa-collector-daemon"
/usr/bin/install -o root -g _nexacollector -m 0640 "$script_dir/../config.example.json" "$base/config.json"
/usr/bin/install -o root -g wheel -m 0644 "$script_dir/com.nexa.collector.plist" "$base/launchd/com.nexa.collector.plist"

# Deliberately do NOT write to /Library/LaunchDaemons or invoke launchctl here.
printf '%s\n' \
  'Collector macOS binary and private folders installed, but NOT activated.' \
  'Edit config.json and provision credential files under:' \
  '  /Library/Application Support/NexaCollector/credentials/' \
  'Expected file names (owner root, group _nexacollector, mode 0640):' \
  '  NEXA_COLLECTOR_TOKEN' \
  '  NEXA_COLLECTOR_DB_KEY_HEX' \
  '  NEXA_SNMP_COMMUNITY' \
  'To enable only after review, run: sudo bash collector/macos/activate.sh'
