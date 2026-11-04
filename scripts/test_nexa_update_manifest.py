#!/usr/bin/env python3
import tempfile
import unittest
from pathlib import Path
from build_nexa_update_manifest import PACKAGES, produce


class SignedManifestTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.path = Path(temp.name)
        self.ver = "0.1.7"
        self.url = f"https://github.com/gabriell211/Nexa-System/releases/download/collector-v{self.ver}"
        for template in PACKAGES.values():
            name = template.format(version=self.ver)
            (self.path / name).write_bytes(b"test package data" * 9000)
            (self.path / (name + ".sig")).write_text(
                "untrusted comment: minisign signature\n" + "A" * 90
                + "\ntrusted comment: signed-version:0.1.7\n" + "B" * 90 + "\n")

    def test_complete(self):
        manifest, hashes = produce(self.ver, self.path, self.url)
        self.assertEqual(set(manifest["platforms"]), set(PACKAGES))
        self.assertEqual(len(hashes.splitlines()), 5)

    def test_missing_signature_fails_closed(self):
        name = next(iter(PACKAGES.values())).format(version=self.ver)
        (self.path / (name + ".sig")).unlink()
        with self.assertRaises(ValueError):
            produce(self.ver, self.path, self.url)

    def test_wrong_destination_fails_closed(self):
        for url in ("http:" + self.url[6:], "https://evil.example.com",
                    self.url.replace("collector-v0.1.7", "collector-v0.1.8")):
            with self.subTest(url=url), self.assertRaises(ValueError):
                produce(self.ver, self.path, url)

    def test_empty_package_and_unsigned_content_fail_closed(self):
        name = next(iter(PACKAGES.values())).format(version=self.ver)
        (self.path / name).write_bytes(b"")
        with self.assertRaises(ValueError):
            produce(self.ver, self.path, self.url)
        (self.path / name).write_bytes(b"test package data" * 9000)
        (self.path / (name + ".sig")).write_text("unsigned")
        with self.assertRaises(ValueError):
            produce(self.ver, self.path, self.url)


if __name__ == "__main__":
    unittest.main()
