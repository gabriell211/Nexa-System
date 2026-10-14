#!/usr/bin/env python3
"""Offline macOS packaging/security checks, not a substitute for a real boot test."""
from pathlib import Path
import plistlib
import subprocess

root = Path(__file__).resolve().parents[1]
plist_path = root / "collector/macos/com.nexa.collector.plist"
plist = plistlib.loads(plist_path.read_bytes())
installer = (root / "collector/macos/install.sh").read_text()
activator = (root / "collector/macos/activate.sh").read_text()
daemon = (root / "collector/daemon/src/main.rs").read_text()
runtime = (root / "collector/daemon/src/macos_launchd_runtime.rs").read_text()
cargo = (root / "collector/daemon/Cargo.toml").read_text()

assert plist["Label"] == "com.nexa.collector"
assert plist["ProgramArguments"] == [
    "/Library/PrivilegedHelperTools/NexaCollector/nexa-collector-daemon",
    "--launchd",
]
assert plist["UserName"] == plist["GroupName"] == "_nexacollector"
assert plist["WorkingDirectory"] == "/Library/Application Support/NexaCollector/data"
assert plist["RunAtLoad"] is True
assert plist["KeepAlive"] == {"SuccessfulExit": False}
assert plist["Umask"] == 0o077
assert 20 <= plist["ThrottleInterval"] <= 120
assert plist["ExitTimeOut"] >= 30
assert not plist.get("EnvironmentVariables"), "Credentials must not be placed in plist environment"
assert all("TOKEN" not in arg and "KEY" not in arg for arg in plist["ProgramArguments"])

assert "CREDENTIALS_DIRECTORY" in daemon
assert 'macos_launchd_mode: bool' in daemon
assert 'if macos_launchd_mode {' in daemon
assert 'if supervised_mode && db_key.is_none()' in daemon
assert 'join("credentials").join(name)' in daemon
assert 'load_secret("NEXA_COLLECTOR_DB_KEY_HEX", windows_service_mode, macos_launchd_mode)' in daemon
assert 'run_application(arguments(), stop, || Ok(()), false, true)' in runtime
assert 'signal_hook::flag::register(SIGTERM' in runtime
assert 'signal_hook::flag::register(SIGINT' in runtime
assert "signal-hook" in cargo

assert "dscl . -create /Users/_nexacollector" in installer
assert "dscl . -create /Groups/_nexacollector" in installer
assert '/usr/bin/install -d -o _nexacollector -g _nexacollector -m 0700 "$base/data"' in installer
assert '/usr/bin/install -d -o root -g _nexacollector -m 0750 "$base/credentials"' in installer
assert '/usr/bin/install -o root -g _nexacollector -m 0640' in installer
assert 'launchd/com.nexa.collector.plist' in installer
assert "/Library/LaunchDaemons/com.nexa.collector.plist" in activator
assert "/bin/launchctl bootstrap system" in activator
assert "assert_secret 'NEXA_COLLECTOR_DB_KEY_HEX'" in activator
assert "assert_secret 'NEXA_COLLECTOR_TOKEN'" in activator
assert "'-rw-r-----'" in activator
assert "if ! /bin/launchctl bootstrap system" in activator
assert "launchctl bootout system/com.nexa.collector" in activator
assert "launchctl bootstrap" not in installer
# Installer may check whether a LaunchDaemon already exists, but must never
# install or bootstrap it as a side effect.
assert 'install -o root -g wheel -m 0644 "$staged" "$installed"' not in installer
assert 'launchctl bootstrap' not in installer
assert "file -b" in installer

for script in ("install.sh", "activate.sh"):
    subprocess.run(["bash", "-n", str(root / "collector/macos" / script)], check=True)
print("macOS launchd packaging and fail-closed credential checks passed (static only)")
