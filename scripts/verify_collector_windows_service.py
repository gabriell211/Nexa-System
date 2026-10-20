#!/usr/bin/env python3
"""Safety and packaging assertions; cannot substitute a real SCM field test."""
from pathlib import Path
import re
from xml.etree import ElementTree

root = Path(__file__).resolve().parents[1]
runtime = (root / "collector/daemon/src/windows_service_runtime.rs").read_text(encoding="utf-8")
daemon = (root / "collector/daemon/src/main.rs").read_text(encoding="utf-8")
manifest = (root / "collector/daemon/Cargo.toml").read_text(encoding="utf-8")

# NSIS is the only supported installation route; no PowerShell installer.
assert not (root / "collector/windows/install.ps1").exists()
config = (root / "collector/console/src-tauri/tauri.conf.json").read_text(encoding="utf-8")
import json
bundle = json.loads(config)["bundle"]
assert bundle["active"] and bundle["targets"] == ["nsis"]
assert bundle["windows"]["nsis"]["installMode"] == "perMachine"
assert bundle["windows"]["nsis"]["installerHooks"] == "./windows/installer-hooks.nsh"
nsis = bundle["windows"]["nsis"]
assert nsis["installerIcon"] == "./icons/icon.ico"
assert nsis["uninstallerIcon"] == "./icons/icon.ico"
assert nsis["sidebarImage"] == "./windows/installer-sidebar.bmp"
assert nsis["headerImage"] == "./windows/installer-header.bmp"
assert (root / "collector/console/src-tauri/icons/icon.ico").stat().st_size > 1000
for filename, width, height in [
    ("installer-sidebar.bmp", 164, 314),
    ("installer-header.bmp", 150, 57),
]:
    bmp = (root / "collector/console/src-tauri/windows" / filename).read_bytes()
    import struct
    assert bmp[:2] == b"BM"
    assert struct.unpack_from("<ii", bmp, 18) == (width, height)
    assert struct.unpack_from("<H", bmp, 28)[0] == 24

assert bundle["externalBin"] == ["binaries/nexa-collector-daemon"]
# The installed graphical executable, not only the MSI/NSIS installer,
# must carry the UAC elevation requirement. Assert the Tauri build hook.
manifest_file = root / "collector/console/src-tauri/windows/admin.manifest"
manifest_xml = ElementTree.parse(manifest_file)
required_level = manifest_xml.findall(
    ".//{urn:schemas-microsoft-com:asm.v3}requestedExecutionLevel"
)
assert len(required_level) == 1
assert required_level[0].get("level") == "requireAdministrator"
assert required_level[0].get("uiAccess") == "false"
common_controls = manifest_xml.findall(
    ".//{urn:schemas-microsoft-com:asm.v1}assemblyIdentity"
)
assert any(item.get("name") == "Microsoft.Windows.Common-Controls"
           and item.get("version") == "6.0.0.0" for item in common_controls)
build = (root / "collector/console/src-tauri/build.rs").read_text(encoding="utf-8")
assert 'include_str!("windows/admin.manifest")' in build
assert 'tauri_build::WindowsAttributes::new().app_manifest(' in build
assert '#[cfg(windows)]' in build and '#[cfg(not(windows))]' in build

installer = (root / "collector/console/src-tauri/windows/installer-hooks.nsh").read_text(encoding="utf-8")
message_boxes = [line for line in installer.splitlines() if line.lstrip().startswith("MessageBox ")]
assert message_boxes and all(" /SD IDOK" in line for line in message_boxes), "Silent install must never wait for a dialog"
hooks = installer.split("!macro NSIS_HOOK_PREINSTALL", 1)[1]
hook_boxes = [line for line in hooks.splitlines() if line.lstrip().startswith("MessageBox ")]
error_levels = [line.strip() for line in hooks.splitlines() if line.lstrip().startswith("SetErrorLevel ")]
assert len(error_levels) == len(hook_boxes) == len(set(error_levels)), "Each NSIS hook failure needs its own exit code"
assert len(re.findall(r"IfSilent 0 \+2\s+Quit\s+Abort ", hooks)) == len(hook_boxes), "Silent failures must preserve their exit codes"
assert hooks.count("!insertmacro NEXA_DIAGNOSTIC ") == len(hook_boxes)
assert 'FileWrite $9 "${CODE}$\\r$\\n"' in installer
assert 'SCM exit: $0$\\r$\\nSCM output: $1$\\r$\\n' in installer
assert not any("StrCmp " in line and "+" in line for line in installer.splitlines()), "NSIS failure blocks must not use fragile relative jumps"
for required in [
    "!macro NSIS_HOOK_PREINSTALL",
    "!macro NSIS_HOOK_POSTINSTALL",
    "!macro NSIS_HOOK_PREUNINSTALL",
    "!macro NSIS_HOOK_POSTUNINSTALL",
    "sc.exe",
    "create NexaCollector",
    "nsExec::ExecToStack",
    "SCM create returned exit code",
    "qc NexaCollector",
    "type= own",
    r'\$\"$INSTDIR\nexa-collector-daemon.exe\$\" --service',
    "start= demand",
    'obj= "NT AUTHORITY\\LocalService"',
    "failure NexaCollector",
    "failureflag NexaCollector",
    "NexaCollector\\credentials",
    "NexaCollector\\data",
    "S-1-5-19",
    "S-1-5-18",
    "S-1-5-32-544",
    "/inheritance:r",
    "$COMMONPROGRAMDATA",
    "Page custom NexaProvisionPage NexaProvisionLeave",
    "FileWriteUTF16LE /BOM",
    "--provision-windows",
    "NEXA_INSTALL_COLLECTOR_ID",
    "NEXA_INSTALL_TOKEN",
    "NEXA_INSTALL_PROXY_URL",
    "IfFileExists",
    "Abort",
]:
    assert required.lower() in installer.lower(), f"Missing NSIS installer control: {required}"
# Only the actual NSIS known folder is accepted, not the literal unrecognized
# $COMMONAPPDATA path that caused the customer installation to fail.
assert "$COMMONAPPDATA" not in "\\n".join(
    line for line in installer.splitlines() if not line.lstrip().startswith(";")
)
for forbidden in ["powershell.exe", "Start-Service", "sc.exe start NexaCollector", "start= auto", "RMDir /r", "Delete $COMMONAPPDATA"]:
    assert forbidden.lower() not in installer.lower(), f"Unsafe installer action: {forbidden}"
assert "NSIS_HOOK_PREUNINSTALL" in installer
assert "sc.exe\" delete NexaCollector" in installer
preuninstall = installer.split("!macro NSIS_HOOK_PREUNINSTALL", 1)[1].split("!macroend", 1)[0]
assert '${If} $0 == "1060"' in preuninstall
assert '${ElseIf} $0 != "0"' in preuninstall
assert 'Abort "Nexa Collector uninstall service state unavailable"' in preuninstall
assert " --service" in installer
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
assert 'args[1] == "--provision-windows"' in daemon
assert "pub fn provision()" in runtime
assert 'root.join("config.json")' in runtime
assert "NEXA_COLLECTOR_TOKEN" in runtime
assert "NEXA_COLLECTOR_DB_KEY_HEX" in runtime
assert "load_secret(\"NEXA_COLLECTOR_DB_KEY_HEX\", windows_service_mode, macos_launchd_mode)" in daemon
assert "shutdown.load(Ordering::Relaxed)" in daemon
watchdog = (root / "collector/daemon/src/progress_watchdog.rs").read_text()
assert "std::process::exit(WATCHDOG_EXIT_CODE)" in watchdog
assert "supervised_mode && !once" in daemon
assert "watcher.progressed()" in daemon
assert "monitor.heartbeat_progressed()" in daemon
assert "ProgressState::HeartbeatStalled" in watchdog
assert "ProgressState::MainStalled" in watchdog
assert "progress_watchdog::start_watchdog(Arc::clone(monitor), recovery_guard.clone())" in daemon
guard = (root / "collector/core/src/recovery_guard.rs").read_text()
assert "pub const MAX_STALLS: usize = 3" in guard
assert "const WINDOW_MINUTES: i64 = 30" in guard
assert 'journal.events.push(StallEvent' in guard
assert 'std::process::exit(WATCHDOG_EXIT_CODE)' in watchdog
assert 'guard.record(kind, Utc::now())' in watchdog
assert '--watchdog-status' in daemon
assert '--reset-watchdog-circuit' in daemon
assert 'if supervised_mode && args.len() == 3 {' in daemon
assert 'recovery_guard.circuit_open(Utc::now())' in daemon
assert 'pub mod recovery_guard;' in (root / 'collector/core/src/lib.rs').read_text()
assert "macos_launchd_mode: bool," in daemon
assert 'windows-service = "=0.8.1"' in manifest
print("Nexa NSIS Windows ACL and admin-only executable manifest checks passed (not a real install test)")
