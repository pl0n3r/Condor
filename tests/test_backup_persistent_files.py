"""Pruebas desconectadas del backup y restore de archivos persistentes."""

import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/backup-persistent-files.py"


class PersistentFileBackupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        (self.source / "uploads").mkdir()
        (self.source / "uploads" / "sentinel.txt").write_bytes(b"condor-synthetic-binary-0001\x00")
        (self.source / "file with space.txt").write_text("ejercicio sintético", encoding="utf-8")
        self.backup_dir = self.root / "private-backups"

    def command(self, *args, ok=True):
        result = subprocess.run([sys.executable, str(SCRIPT), *map(str, args)],
                                cwd=self.root, capture_output=True, text=True, check=False)
        if ok:
            self.assertEqual(result.returncode, 0, result.stderr)
            return json.loads(result.stdout)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertNotIn("sentinel.txt", result.stderr)
        return result

    def archive(self):
        output = self.command("backup", "--source", self.source, "--backup-dir", self.backup_dir)
        self.assertEqual(output["file_count"], 2)
        self.assertEqual(len(output["sha256"]), 64)
        archive = self.backup_dir / output["archive_name"]
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        return archive

    def test_round_trip_verifies_bytes_nested_paths_and_private_permissions(self):
        archive = self.archive()
        destination = self.root / "isolated-restore"
        output = self.command("restore", "--archive", archive, "--destination", destination)
        self.assertEqual(output["status"], "restored")
        self.assertEqual(output["file_count"], 2)
        self.assertEqual((destination / "uploads/sentinel.txt").read_bytes(),
                         (self.source / "uploads/sentinel.txt").read_bytes())
        self.assertEqual((destination / "file with space.txt").read_bytes(),
                         (self.source / "file with space.txt").read_bytes())
        self.assertEqual((destination / "uploads/sentinel.txt").stat().st_mode & 0o777, 0o600)

    def test_rejects_symlinks_and_destination_inside_source(self):
        (self.source / "secret-link").symlink_to(self.root)
        self.command("backup", "--source", self.source, "--backup-dir", self.backup_dir, ok=False)
        (self.source / "secret-link").unlink()
        self.command("backup", "--source", self.source, "--backup-dir", self.source / "backups", ok=False)

    def test_rejects_existing_destination_and_tampered_archive(self):
        archive = self.archive()
        destination = self.root / "existing"
        destination.mkdir()
        self.command("restore", "--archive", archive, "--destination", destination, ok=False)
        archive.write_bytes(archive.read_bytes()[:40])
        self.command("restore", "--archive", archive, "--destination", self.root / "new", ok=False)
        self.assertFalse((self.root / "new").exists())

    def test_rejects_traversal_and_non_regular_members(self):
        archive = self.root / "malicious.tar.gz"
        with tarfile.open(archive, "w:gz") as out:
            item = tarfile.TarInfo("files/../../escape")
            item.size = 4
            out.addfile(item, io.BytesIO(b"oops"))
            link = tarfile.TarInfo("files/link")
            link.type = tarfile.SYMTYPE
            link.linkname = "/etc/passwd"
            out.addfile(link)
            manifest = b'{"version":1,"created_at":"2026-11-04T00:00:00+00:00","files":[]}'
            entry = tarfile.TarInfo("MANIFEST.json")
            entry.size = len(manifest)
            out.addfile(entry, io.BytesIO(manifest))
        self.command("restore", "--archive", archive, "--destination", self.root / "safe", ok=False)
        self.assertFalse((self.root / "safe").exists())
        self.assertFalse((self.root / "escape").exists())


if __name__ == "__main__":
    unittest.main()
