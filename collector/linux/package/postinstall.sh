#!/bin/sh
# Native Debian/RPM package lifecycle; never ship prefilled credentials.
set -eu
if ! command -v systemctl >/dev/null 2>&1; then
  echo "Nexa Collector: systemd is required for the background collector." >&2
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
systemctl daemon-reload
# dpkg passes (configure, previous_version); rpm passes (1 new, 2 upgrade).
# Existing active collectors receive new code; fresh installs do NOT start
# without real credentials, validated config and customer registration.
if { [ "${1:-}" = configure ] && [ -n "${2:-}" ]; } || [ "${1:-}" = 2 ]; then
  systemctl try-restart nexa-collector.service || echo "Nexa Collector: service restart failed; inspect systemctl status." >&2
fi
echo "Nexa Collector installed. Link customer and provision protected credentials before: sudo systemctl enable --now nexa-collector.service"
