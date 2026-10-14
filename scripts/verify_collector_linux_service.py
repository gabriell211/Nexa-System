#!/usr/bin/env python3
"""Offline structural checks only; does not assert a booted systemd service."""
from pathlib import Path
import re
import subprocess

root = Path(__file__).resolve().parents[1]
unit = (root / "collector/linux/nexa-collector.service").read_text()
installer = (root / "collector/linux/install.sh").read_text()
daemon = (root / "collector/daemon/src/main.rs").read_text()

required = {
    "Type": "simple",
    "User": "nexa-collector",
    "Group": "nexa-collector",
    "ProtectSystem": "strict",
    "ProtectHome": "true",
    "NoNewPrivileges": "true",
    "PrivateTmp": "true",
    "StateDirectory": "nexa-collector",
    "StateDirectoryMode": "0700",
    "UMask": "0077",
    "Restart": "on-failure",
}
for key, expected in required.items():
    assert re.search(rf"^{re.escape(key)}={re.escape(expected)}$", unit, re.M), key

for key, filename in {
    "NEXA_COLLECTOR_TOKEN": "token",
    "NEXA_COLLECTOR_DB_KEY_HEX": "db-key-hex",
    "NEXA_SNMP_COMMUNITY": "snmp-community",
}.items():
    directive = f"LoadCredential={key}:/etc/nexa-collector/credentials/{filename}"
    assert directive in unit, directive
    assert f'load_secret("{key}", windows_service_mode, macos_launchd_mode)' in daemon, key

assert "CREDENTIALS_DIRECTORY" in daemon
assert not re.search(r"^Environment=.*(KEY|TOKEN|COMMUNITY)", unit, re.M)
assert "ExecStart=/usr/local/libexec/nexa-collector-daemon /etc/nexa-collector/config.json /var/lib/nexa-collector/queue.sqlite" in unit
assert "ProtectSystem=strict" in unit
assert "RestartSec=15s" in unit
assert "StartLimitBurst=5" in unit
watchdog = (root / "collector/daemon/src/progress_watchdog.rs").read_text()
assert "STALL_TIMEOUT: Duration = Duration::from_secs(180)" in watchdog
assert "std::process::exit(WATCHDOG_EXIT_CODE)" in watchdog
assert "supervised_mode && !once" in daemon
assert "watcher.progressed()" in daemon
assert "monitor.heartbeat_progressed()" in daemon
assert "ProgressState::HeartbeatStalled" in watchdog
assert "ProgressState::MainStalled" in watchdog
assert "progress_watchdog::start_watchdog(Arc::clone(monitor))" in daemon
assert "StateDirectoryMode=0700" in unit
assert "install -o root -g root -m 0644" in installer
assert "install -d -o root -g root -m 0700 /etc/nexa-collector/credentials" in installer
assert "if systemctl is-active --quiet nexa-collector.service" in installer
# The installer MUST NOT invoke startup as a side effect.
assert not re.search(r"^\s*systemctl\s+(?:enable|start|restart|try-restart)\b", installer, re.M)
subprocess.run(["bash", "-n", str(root / "collector/linux/install.sh")], check=True)
print("Linux Collector systemd packaging static checks passed")
