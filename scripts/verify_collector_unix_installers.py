#!/usr/bin/env python3
"""Structural safety checks for native Linux/macOS installers; field tests still required."""
import json
import plistlib
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[1]
bundle = json.loads((root / "collector/console/src-tauri/tauri.conf.json").read_text())["bundle"]
assert bundle["active"] is True
assert bundle["externalBin"] == ["binaries/nexa-collector-daemon"]
assert set(bundle["linux"]) == {"deb", "rpm"}
for flavor in ("deb", "rpm"):
    cfg = bundle["linux"][flavor]
    assert cfg["files"]["/usr/lib/nexa-collector/nexa-collector-daemon"] == "binaries/nexa-collector-daemon-x86_64-unknown-linux-gnu"
    assert cfg["postInstallScript"] == "../../linux/package/postinstall.sh"
    assert cfg["preRemoveScript"] == "../../linux/package/preremove.sh"
    assert "nexa-collector.service" in str(cfg["files"])
    assert "config.example.json" in str(cfg["files"])

package_unit = (root / "collector/linux/package/nexa-collector.service").read_text()
assert "ExecStart=/usr/lib/nexa-collector/nexa-collector-daemon" in package_unit
assert "User=nexa-collector" in package_unit
assert "StateDirectory=nexa-collector" in package_unit
assert "ProtectSystem=strict" in package_unit
for secret in ("NEXA_COLLECTOR_TOKEN", "NEXA_COLLECTOR_DB_KEY_HEX", "NEXA_SNMP_COMMUNITY"):
    assert "LoadCredential=" + secret + ":" in package_unit
linux_post = (root / "collector/linux/package/postinstall.sh").read_text()
linux_pre = (root / "collector/linux/package/preremove.sh").read_text()
assert "if [ ! -e /etc/nexa-collector/config.json ]" in linux_post
assert "useradd --system" in linux_post
assert "systemctl daemon-reload" in linux_post
assert "systemctl try-restart" in linux_post
assert "systemctl enable --now" not in linux_post.split('echo "')[0]
assert "systemctl disable --now" in linux_pre
assert "rm -rf" not in linux_pre + linux_post
mac_pre = (root / "collector/macos/pkg/preinstall").read_text()
mac_post = (root / "collector/macos/pkg/postinstall").read_text()
assert "launchctl print system/com.nexa.collector" in mac_pre
assert "Refusing unattended overwrite" in mac_pre
assert "dscl . -create /Users/_nexacollector" in mac_post
assert "dscl . -create /Groups/_nexacollector" in mac_post
assert 'if [[ ! -e "$base/config.json" ]]' in mac_post
assert "launchctl bootstrap" not in mac_post
assert "rm -rf" not in mac_post + mac_pre
assert "chmod 0644" in mac_post
assert "chmod 0755" in mac_post
plist = plistlib.loads((root / "collector/macos/com.nexa.collector.plist").read_bytes())
assert plist["UserName"] == "_nexacollector"
assert plist["GroupName"] == "_nexacollector"
assert plist["RunAtLoad"] is True
build_script = (root / "collector/macos/build-pkg.sh").read_text()
assert "pkgbuild --root" in build_script
assert "pkgbuild --analyze --root" in build_script
assert "--component-plist" in build_script
assert 'component.get("RootRelativeBundlePath") == "Applications/Nexa Collector.app"' in build_script
assert 'matches[0]["BundleIsRelocatable"] = False' in build_script
assert "Nexa Collector.app" in build_script
assert "nexa-collector-daemon" in build_script
assert "com.nexa.collector.plist" in build_script
assert "config.example.json" in build_script
for f in ("collector/linux/package/postinstall.sh", "collector/linux/package/preremove.sh"):
    subprocess.run(["sh", "-n", str(root / f)], check=True)
for f in ("collector/macos/pkg/preinstall", "collector/macos/pkg/postinstall", "collector/macos/build-pkg.sh"):
    subprocess.run(["bash", "-n", str(root / f)], check=True)
print("Linux DEB/RPM and macOS PKG native installer static safety checks passed.")
