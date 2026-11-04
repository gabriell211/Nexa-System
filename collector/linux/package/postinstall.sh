#!/bin/sh
# Package installation does not modify the persisted config, secrets or SQLite/WAL.
set -eu
umask 077
binary='/usr/lib/nexa-collector/nexa-collector-daemon'
console='/usr/bin/nexa-collector-console'
transaction='/var/lib/nexa-collector-updates'
service='nexa-collector.service'

restore_previous() {
    status=$?
    trap - EXIT
    if [ "$status" -ne 0 ] && [ -f "$transaction/pending" ]; then
        echo "Collector upgrade failed: restoring previous executable" >&2
        [ -f "$transaction/daemon.previous" ] &&
            cp -p "$transaction/daemon.previous" "$binary" || true
        [ -f "$transaction/console.previous" ] &&
            cp -p "$transaction/console.previous" "$console" || true
        if command -v systemctl >/dev/null 2>&1 &&
           [ "$(cat "$transaction/was-active" 2>/dev/null || true)" = active ]; then
            systemctl daemon-reload || true
            systemctl start "$service" || true
        fi
        echo "Private rollback snapshot retained for inspection" >&2
    fi
    exit "$status"
}
trap restore_previous EXIT

if ! command -v systemctl >/dev/null 2>&1; then
    echo "Nexa Collector requires systemd" >&2
    exit 1
fi
if ! getent passwd nexa-collector >/dev/null 2>&1; then
    useradd --system --no-create-home --home-dir /var/lib/nexa-collector --shell /usr/sbin/nologin nexa-collector
fi
install -d -o root -g nexa-collector -m 0750 /etc/nexa-collector
install -d -o root -g root -m 0700 /etc/nexa-collector/credentials
install -d -o nexa-collector -g nexa-collector -m 0700 /var/lib/nexa-collector

if [ ! -e /etc/nexa-collector/config.json ]; then
    install -o root -g nexa-collector -m 0640 /usr/share/nexa-collector/config.example.json /etc/nexa-collector/config.json
fi

[ -s "$binary" ] && [ -x "$binary" ] || {
    echo "Collector executable is unavailable after upgrade" >&2
    exit 1
}
systemctl daemon-reload

if [ -f "$transaction/pending" ]; then
    [ -f "$transaction/daemon.previous" ] || {
        echo "Prior daemon snapshot missing; cannot safely complete upgrade" >&2
        exit 1
    }
    if [ "$(cat "$transaction/was-active" 2>/dev/null || true)" = active ]; then
        systemctl start "$service"
        # systemd may briefly report active before a service exits.
        sleep 3
        systemctl is-active --quiet "$service" || {
            echo "New collector failed its service health check" >&2
            exit 1
        }
    fi
    rm -f "$transaction/pending" "$transaction/was-active" \
        "$transaction/daemon.previous" "$transaction/console.previous"
else
    # A fresh installation NEVER starts an unprovisioned collector.
    echo 'Nexa Collector installed. Provision credentials before: sudo systemctl enable --now nexa-collector.service'
fi

trap - EXIT
exit 0
