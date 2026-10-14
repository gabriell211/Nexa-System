#!/usr/bin/env bash
# Run explicitly with bash; never starts or enables the service by itself.
set -euo pipefail

usage() {
  printf 'Usage: sudo bash %s /absolute/path/to/nexa-collector-daemon\n' "$0" >&2
  exit 2
}

[[ ${EUID} -eq 0 ]] || { echo 'Root privileges required.' >&2; exit 1; }
[[ $# -eq 1 ]] || usage
binary="$1"
[[ "$binary" = /* && -f "$binary" && -x "$binary" ]] || usage
command -v systemctl >/dev/null || { echo 'systemd is required.' >&2; exit 1; }
if systemctl is-active --quiet nexa-collector.service; then
  echo 'Stop the running Collector before updating its binary or unit.' >&2
  exit 1
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if ! getent passwd nexa-collector >/dev/null; then
  nologin="$(command -v nologin || printf /usr/sbin/nologin)"
  useradd --system --no-create-home --home-dir /var/lib/nexa-collector --shell "$nologin" nexa-collector
fi

install -d -o root -g nexa-collector -m 0750 /etc/nexa-collector
install -d -o root -g root -m 0700 /etc/nexa-collector/credentials
if [[ ! -e /etc/nexa-collector/config.json ]]; then
  install -o root -g nexa-collector -m 0640 "$script_dir/../config.example.json" /etc/nexa-collector/config.json
  echo 'Installed example config; update collector ID, endpoint, CIDR and printers.'
fi

install -d -o root -g root -m 0755 /usr/local/libexec
install -o root -g root -m 0755 "$binary" /usr/local/libexec/nexa-collector-daemon
install -o root -g root -m 0644 "$script_dir/nexa-collector.service" /etc/systemd/system/nexa-collector.service
systemctl daemon-reload

printf '%s\n' \
  'Service installed but NOT started/enabled.' \
  'Provide root-owned credential files (mode 0600):' \
  '  /etc/nexa-collector/credentials/token' \
  '  /etc/nexa-collector/credentials/db-key-hex' \
  '  /etc/nexa-collector/credentials/snmp-community' \
  'Then validate /etc/nexa-collector/config.json and run:' \
  '  sudo systemctl enable --now nexa-collector.service' \
  '  sudo systemctl status nexa-collector.service' \
  '  sudo journalctl -u nexa-collector.service --no-pager -n 100'
