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
        """Crea un root descartable con PHP/lock compatibles con post-deploy."""
        temp = tempfile.TemporaryDirectory()
        root = pathlib.Path(temp.name)
        (root / "scripts").mkdir()
        (root / "var").mkdir()
        (root / "bin").mkdir()
        (root / "scripts" / "post-deploy.sh").write_text(
            SCRIPT.read_text(encoding="utf-8"),
            encoding="utf-8",
        )
        fake_backup = root / "scripts" / "backup-database.sh"
        fake_backup.write_text(
            textwrap.dedent(
                """\
                #!/bin/sh
                set -eu
                root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
                printf '%s\n' "backup" >> "$root/php-calls.log"
                [ -n "${DATABASE_URL:-}" ] || exit 19
                [ "${FAKE_BACKUP_FAILURE:-0}" = "1" ] && exit 23
                : > "$root/backup-created"
                """
            ),
            encoding="utf-8",
        )
        fake_backup.chmod(0o755)
        fake_bin = root / "fake-bin"
        fake_bin.mkdir()
        fake_php = fake_bin / "php85"
        fake_php.write_text(
            textwrap.dedent(
                """\
                #!/bin/sh
                set -eu
                root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
                if [ "${1:-}" = "-r" ]; then
                  printf '%s' "${FAKE_DATABASE_URL:-mysql://dotenv.example/condor}"
                  exit 0
                fi
                printf '%s\n' "$*" >> "$root/php-calls.log"
                case "$*" in
                  *"doctrine:migrations:up-to-date"*)
                    if [ -f "$root/migrated" ]; then
                      if [ "${FAKE_POSTCHECK_HANG:-0}" = "1" ]; then
                        while :; do :; done
                      fi
                      exit 0
                    fi
                    echo "Database is not up to date; pending migration"
                    exit 1
                    ;;
                  *"doctrine:migrations:migrate"*"--dry-run"*)
                    sql_file=""
                    for arg in "$@"; do
                      case "$arg" in
                        --write-sql=*) sql_file="${arg#--write-sql=}" ;;
                      esac
                    done
                    printf '%s\n' "${FAKE_MIGRATION_SQL:-CREATE TABLE safe_table (id INT);}" > "$sql_file"
                    exit 0
                    ;;
                  *"doctrine:migrations:migrate"*)
                    if [ "${FAKE_MIGRATION_FAILURE:-0}" = "1" ]; then
                      echo "detalle-fallo-migracion" >&2
                      exit 17
                    fi
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

    def run_script(
        self,
        stage,
        *,
        migration_sql="CREATE TABLE safe_table (id INT);",
        postcheck_hang=False,
        migration_failure=False,
        backup_failure=False,
        auto_migrate="1",
        database_url_in_env=False,
    ):
        """Ejecuta post-deploy en sandbox con comportamiento Doctrine controlado."""
        temp, root, fake_bin = self.sandbox()
        self.addCleanup(temp.cleanup)
        env = os.environ.copy()
        env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
        env["CONDOR_PRODUCTION_STAGE"] = stage
        env["CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS"] = "5"
        env["CONDOR_MIGRATION_TIMEOUT_SECONDS"] = "5"
        env["FAKE_MIGRATION_SQL"] = migration_sql
        env["FAKE_POSTCHECK_HANG"] = "1" if postcheck_hang else "0"
        env["FAKE_MIGRATION_FAILURE"] = "1" if migration_failure else "0"
        env["FAKE_BACKUP_FAILURE"] = "1" if backup_failure else "0"
        env["CONDOR_AUTO_MIGRATE"] = auto_migrate
        env["FAKE_DATABASE_URL"] = "mysql://dotenv.example/condor"
        if database_url_in_env:
            env["DATABASE_URL"] = "mysql://env.example/condor"
        else:
            env.pop("DATABASE_URL", None)
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
        """Construction valida SQL, migra y solo después regenera caché."""
        result, calls, root = self.run_script("construction")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue((root / "migrated").exists())
        self.assertIn("--dry-run", calls)
        actual_migrate = next(
            line for line in calls.splitlines()
            if "doctrine:migrations:migrate" in line and "--dry-run" not in line
        )
        self.assertIn("backup", calls)
        self.assertTrue((root / "backup-created").exists())
        self.assertLess(calls.index("--dry-run"), calls.index("backup"))
        self.assertLess(calls.index("backup"), calls.index(actual_migrate))
        self.assertLess(calls.index(actual_migrate), calls.index("cache:clear"))
        self.assertIn("cache:warmup", calls)

    def test_construction_resolves_database_url_from_dotenv_for_backup(self):
        """Sin DATABASE_URL exportada, Symfony dotenv alimenta el backup previo."""
        result, calls, root = self.run_script(
            "construction",
            database_url_in_env=False,
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("backup", calls)
        self.assertTrue((root / "backup-created").exists())

    def test_backup_failure_prevents_migration_and_cache(self):
        """Si el backup falla, no se ejecuta migrate real ni se toca caché."""
        result, calls, root = self.run_script(
            "construction",
            backup_failure=True,
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("backup previo a migración falló", result.stderr)
        self.assertFalse((root / "migrated").exists())
        self.assertIn("--dry-run", calls)
        actual = [
            line for line in calls.splitlines()
            if "doctrine:migrations:migrate" in line and "--dry-run" not in line
        ]
        self.assertEqual([], actual)
        self.assertNotIn("cache:clear", calls)

    def test_auto_migrate_opt_out_preserves_fail_closed_behavior(self):
        """CONDOR_AUTO_MIGRATE=0 detecta deriva sin dry-run, backup ni migrate."""
        result, calls, root = self.run_script(
            "construction",
            auto_migrate="0",
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("CONDOR_AUTO_MIGRATE=0", result.stderr)
        self.assertNotIn("doctrine:migrations:migrate", calls)
        self.assertNotIn("backup", calls)
        self.assertFalse((root / "backup-created").exists())
        self.assertNotIn("cache:clear", calls)

    def test_live_fails_closed_without_migrating_or_touching_cache(self):
        """Live conserva fail-closed y no ejecuta ni dry-run de migración."""
        result, calls, root = self.run_script("live")

        self.assertEqual(2, result.returncode)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("doctrine:migrations:migrate", calls)
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)


    def test_construction_blocks_destructive_versioned_migration(self):
        """El dry-run destructivo se bloquea antes de ejecutar migrate real."""
        result, calls, root = self.run_script(
            "construction",
            migration_sql="ALTER TABLE condor_customer DROP COLUMN email;",
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("migración destructiva/contract detectada", result.stderr)
        self.assertFalse((root / "migrated").exists())
        actual = [
            line for line in calls.splitlines()
            if "doctrine:migrations:migrate" in line and "--dry-run" not in line
        ]
        self.assertEqual([], actual)
        self.assertNotIn("cache:clear", calls)

    def test_post_migration_schema_recheck_times_out_fail_closed(self):
        """La recomprobación posterior usa timeout y libera el flujo sin caché."""
        result, calls, _ = self.run_script("construction", postcheck_hang=True)

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("recomprobación de esquema excedió 5s", result.stderr)
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)

    def test_failed_migration_emits_diagnostic_tail_before_cleanup(self):
        """Un fallo Doctrine conserva diagnóstico útil en stderr antes de limpiar."""
        result, calls, _ = self.run_script("construction", migration_failure=True)

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("detalle-fallo-migracion", result.stderr)
        self.assertIn("código 17", result.stderr)
        self.assertNotIn("cache:clear", calls)

if __name__ == "__main__":
    unittest.main()
