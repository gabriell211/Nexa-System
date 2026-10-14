#!/usr/bin/env python3
"""Safety and packaging assertions; cannot substitute a real SCM field test."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
installer = (root / "collector/windows/install.ps1").read_text(encoding="utf-8")
runtime = (root / "collector/daemon/src/windows_service_runtime.rs").read_text(encoding="utf-8")
daemon = (root / "collector/daemon/src/main.rs").read_text(encoding="utf-8")
manifest = (root / "collector/daemon/Cargo.toml").read_text(encoding="utf-8")

for required in [
    "obj= 'NT AUTHORITY\\LocalService'",
    "start= demand",
    "sc.exe failure ",
    "sc.exe failureflag ",
    "NEXA_COLLECTOR_DB_KEY_HEX",
    "NEXA_COLLECTOR_TOKEN",
    "NEXA_SNMP_COMMUNITY",
    "S-1-5-19",
    "S-1-5-18",
    "S-1-5-32-544",
    " /inheritance:r /T",
    "refusing to overwrite files or inherited ACLs",
    "Start-Service -Name NexaCollector",
]:
    assert required.lower() in installer.lower(), f"Missing installer safety control: {required}"
for forbidden in ["& sc.exe start ", "Start-Service -Name $serviceName", "New-Service", "Set-Service -StartupType Automatic"]:
    assert forbidden.lower() not in installer.lower(), f"Unexpected autostart: {forbidden}"
assert '" --service' in installer
assert "define_windows_service!" in runtime
assert "service_control_handler::register" in runtime
assert "ServiceControl::Stop" in runtime
assert "ServiceControl::Interrogate" in runtime
assert "ServiceState::StartPending" in runtime
assert "ServiceState::Running" in runtime
assert "ServiceState::Stopped" in runtime
assert "super::run_application(" in runtime
assert "Ordering::SeqCst" in runtime
assert "windows_service_mode && args.len() != 3" in daemon
assert "load_secret(\"NEXA_COLLECTOR_DB_KEY_HEX\", windows_service_mode, macos_launchd_mode)" in daemon
assert "shutdown.load(Ordering::Relaxed)" in daemon
watchdog = (root / "collector/daemon/src/progress_watchdog.rs").read_text()
assert "std::process::exit(WATCHDOG_EXIT_CODE)" in watchdog
assert "supervised_mode && !once" in daemon
assert "watcher.progressed()" in daemon
assert "macos_launchd_mode: bool," in daemon
assert 'windows-service = "=0.8.1"' in manifest
print("Nexa Windows SCM packaging static checks passed (not a real service execution test)")
