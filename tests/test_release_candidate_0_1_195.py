import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ReleaseCandidate01195Tests(unittest.TestCase):
    def test_version_and_release_notes(self) -> None:
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        self.assertRegex(version, r"'version'\s*=>\s*'0\.1\.195'")
        notes = (ROOT / "docs" / "release-notes" / "0.1.195.md").read_text(encoding="utf-8")
        self.assertTrue(notes.startswith("# Condor 0.1.195"))
        self.assertIn("### Funciones nuevas", notes)
        self.assertIn("0.1.194", notes)
        # Cada línea de detalle cita un PR real (#NNN).
        details = [l for l in notes.splitlines() if re.match(r"- #\d+ ", l)]
        self.assertGreaterEqual(len(details), 100)


if __name__ == "__main__":
    unittest.main()
