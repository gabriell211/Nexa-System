#!/bin/sh
# RPM passes 0 for erase, 1 for upgrade. Debian passes "remove"/"upgrade".
# Never remove config, credentials or the persistent SQLite/WAL queue.
set -eu
if [ "${1:-}" = remove ] || [ "${1:-}" = 0 ]; then
  if command -v systemctl >/dev/null 2>&1; then
    systemctl disable --now nexa-collector.service || true
    systemctl daemon-reload || true
  fi
fi
