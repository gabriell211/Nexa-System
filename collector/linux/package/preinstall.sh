#!/bin/sh
# Package-manager-only update transaction. No network downloads or unsigned binaries.
set -eu
umask 077

binary='/usr/lib/nexa-collector/nexa-collector-daemon'
console='/usr/bin/nexa-collector-console'
transaction='/var/lib/nexa-collector-updates'
service='nexa-collector.service'

[ "$(id -u)" -eq 0 ] || { echo "Root required for package upgrade" >&2; exit 1; }
[ ! -L "$transaction" ] || { echo "Unsafe update transaction path" >&2; exit 1; }

# New installation: do not auto-enable an unprovisioned service.
[ -e "$binary" ] || exit 0
[ -f "$binary" ] && [ ! -L "$binary" ] || { echo "Unsafe existing service binary" >&2; exit 1; }
[ ! -e "$transaction/pending" ] || {
    echo "A previous upgrade requires recovery before proceeding." >&2
    exit 1
}

install -d -o root -g root -m 0700 "$transaction"
[ "$(stat -c '%u:%a' "$transaction")" = '0:700' ] || {
    echo "Update backup must be root-owned and private" >&2
    exit 1
}
cp -p "$binary" "$transaction/daemon.previous.tmp"
mv -f "$transaction/daemon.previous.tmp" "$transaction/daemon.previous"
if [ -f "$console" ] && [ ! -L "$console" ]; then
    cp -p "$console" "$transaction/console.previous.tmp"
    mv -f "$transaction/console.previous.tmp" "$transaction/console.previous"
fi

if command -v systemctl >/dev/null 2>&1 &&
   systemctl is-active --quiet "$service"; then
    printf 'active\n' > "$transaction/was-active"
    if ! systemctl stop "$service"; then
        rm -f "$transaction/was-active"
        echo "Could not stop the collector; declining to replace an active binary" >&2
        exit 1
    fi
else
    printf 'inactive\n' > "$transaction/was-active"
fi
printf 'pending\n' > "$transaction/pending"
