"""Regresión del option-file temporal de MySQL."""

import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

PARSER = Path(__file__).resolve().parents[2] / "scripts" / "parse-database-url.php"


class DatabaseUrlOptionFileTest(unittest.TestCase):
    def test_client_config_uses_dynamic_password_and_private_mode_under_permissive_umask(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI no está disponible")

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for index, password in enumerate(("synthetic/first", "synthetic/second")):
                target = root / f"mysql-{index}.cnf"
                encoded = password.replace("/", "%2F")
                environment = {
                    **os.environ,
                    "DATABASE_URL": f"mysql://condor_test:{encoded}@127.0.0.1:3306/condor_test",
                }
                previous_umask = os.umask(0o022)
                try:
                    result = subprocess.run(
                        [php, str(PARSER), "client-config", str(target)],
                        env=environment,
                        capture_output=True,
                        text=True,
                        check=False,
                    )
                finally:
                    os.umask(previous_umask)

                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, "")
                self.assertNotIn(password, result.stderr)
                self.assertIn(f'password="{password}"', target.read_text(encoding="utf-8"))
                self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)


if __name__ == "__main__":
    unittest.main()
