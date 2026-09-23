import os
import pathlib
import subprocess
import tempfile
import textwrap
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = REPO_ROOT / "scripts" / "post-deploy.sh"


class PostDeployStageTest(unittest.TestCase):
    def sandbox(self):
        temp = tempfile.TemporaryDirectory()
        root = pathlib.Path(temp.name)
        (root / "scripts").mkdir()
        (root / "var").mkdir()
        (root / "bin").mkdir()
        (root / "scripts" / "post-deploy.sh").write_text(
            SCRIPT.read_text(encoding="utf-8"),
            encoding="utf-8",
        )
        fake_bin = root / "fake-bin"
        fake_bin.mkdir()
        fake_php = fake_bin / "php85"
        fake_php.write_text(
            textwrap.dedent(
                """\
                #!/bin/sh
                set -eu
                root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
                printf '%s\n' "$*" >> "$root/php-calls.log"
                case "$*" in
                  *"doctrine:migrations:up-to-date"*)
                    if [ -f "$root/migrated" ]; then
                      exit 0
                    fi
                    echo "Database is not up to date; pending migration"
                    exit 1
                    ;;
                  *"doctrine:migrations:migrate"*)
                    : > "$root/migrated"
                    exit 0
                    ;;
                  *"cache:clear"*|*"cache:warmup"*)
                    exit 0
                    ;;
                esac
                exit 0
                """
            ),
            encoding="utf-8",
        )
        fake_php.chmod(0o755)
        return temp, root, fake_bin

    def run_script(self, stage):
        temp, root, fake_bin = self.sandbox()
        self.addCleanup(temp.cleanup)
        env = os.environ.copy()
        env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
        env["CONDOR_PRODUCTION_STAGE"] = stage
        env["CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS"] = "5"
        env["CONDOR_MIGRATION_TIMEOUT_SECONDS"] = "5"
        result = subprocess.run(
            ["sh", str(root / "scripts" / "post-deploy.sh")],
            cwd=root,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=15,
            check=False,
        )
        calls_path = root / "php-calls.log"
        calls = calls_path.read_text(encoding="utf-8") if calls_path.exists() else ""
        return result, calls, root

    def test_construction_reconciles_pending_schema_before_cache(self):
        result, calls, root = self.run_script("construction")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue((root / "migrated").exists())
        self.assertIn("doctrine:migrations:migrate", calls)
        self.assertLess(
            calls.index("doctrine:migrations:migrate"),
            calls.index("cache:clear"),
        )
        self.assertIn("cache:warmup", calls)

    def test_live_fails_closed_without_migrating_or_touching_cache(self):
        result, calls, root = self.run_script("live")

        self.assertEqual(2, result.returncode)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("doctrine:migrations:migrate", calls)
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)


if __name__ == "__main__":
    unittest.main()
