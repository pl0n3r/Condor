import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import textwrap
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = REPO_ROOT / "scripts" / "post-deploy.sh"
STATUS_ENDPOINT = REPO_ROOT / "public" / "post-deploy-status.php"


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
                printf '%s' "$DATABASE_URL" > "$root/backup-url"
                if [ "${FAKE_BACKUP_PDO_FAILURE:-0}" = "1" ]; then
                  printf '%s\n' "mariadb-dump: Access denied for user" >&2
                  printf '%s\n' "backup-database.sh: cliente seleccionado: pdo." >&2
                  printf '%s\n' "backup-database-pdo: stage=metadata; detalles internos redactados." >&2
                  exit 35
                fi
                if [ "${FAKE_BACKUP_PDO_BOOTSTRAP_FAILURE:-0}" = "1" ]; then
                  printf '%s\n' "mariadb-dump: Access denied for user" >&2
                  printf '%s\n' "backup-database.sh: cliente seleccionado: pdo." >&2
                  printf '%s\n' "Command app:database:backup-pdo is not defined." >&2
                  exit 1
                fi
                [ "${FAKE_BACKUP_FAILURE:-0}" = "1" ] && exit 23
                if [ "${FAKE_BACKUP_HANG:-0}" = "1" ]; then
                  while :; do sleep 1; done
                fi
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
                  case "${2:-}" in
                    *'new Symfony\Component\Dotenv\Dotenv();'*) ;;
                    *) echo "resolver dotenv inválido" >&2; exit 29 ;;
                  esac
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
                    if [ "${FAKE_DRYRUN_HANG:-0}" = "1" ]; then
                      while :; do sleep 1; done
                    fi
                    if [ "${FAKE_DRYRUN_FAILURE:-0}" = "1" ]; then
                      printf '%s\n' 'sandbox-private-error-marker' >&2
                      exit 23
                    fi
                    sql_file=""
                    for arg in "$@"; do
                      case "$arg" in
                        --write-sql=*) sql_file="${arg#--write-sql=}" ;;
                      esac
                    done
                    if [ "${FAKE_DRYRUN_EMPTY_SQL:-0}" = "1" ]; then
                      : > "$sql_file"
                    else
                      printf '%s\n' "${FAKE_MIGRATION_SQL:-CREATE TABLE safe_table (id INT);}" > "$sql_file"
                    fi
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
                  *"app:commercial:seed"*)
                    if [ "${FAKE_CATALOG_SEED_FAILURE:-0}" = "1" ]; then
                      echo "catalog-seed-failure" >&2
                      exit 31
                    fi
                    : > "$root/catalog-seeded"
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
        dryrun_failure=False,
        dryrun_hang=False,
        dryrun_empty_sql=False,
        backup_failure=False,
        backup_pdo_failure=False,
        backup_pdo_bootstrap_failure=False,
        backup_hang=False,
        auto_migrate="1",
        database_url_in_env=False,
        catalog_seed_failure=False,
    ):
        """Ejecuta post-deploy en sandbox con comportamiento Doctrine controlado."""
        temp, root, fake_bin = self.sandbox()
        self.addCleanup(temp.cleanup)
        env = os.environ.copy()
        env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
        env["CONDOR_PRODUCTION_STAGE"] = stage
        env["CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS"] = "5"
        env["CONDOR_MIGRATION_TIMEOUT_SECONDS"] = "5"
        env["CONDOR_BACKUP_TIMEOUT_SECONDS"] = "2"
        env["FAKE_MIGRATION_SQL"] = migration_sql
        env["FAKE_POSTCHECK_HANG"] = "1" if postcheck_hang else "0"
        env["FAKE_MIGRATION_FAILURE"] = "1" if migration_failure else "0"
        env["FAKE_DRYRUN_FAILURE"] = "1" if dryrun_failure else "0"
        env["FAKE_DRYRUN_HANG"] = "1" if dryrun_hang else "0"
        env["FAKE_DRYRUN_EMPTY_SQL"] = "1" if dryrun_empty_sql else "0"
        env["FAKE_BACKUP_FAILURE"] = "1" if backup_failure else "0"
        env["FAKE_BACKUP_PDO_FAILURE"] = "1" if backup_pdo_failure else "0"
        env["FAKE_BACKUP_PDO_BOOTSTRAP_FAILURE"] = (
            "1" if backup_pdo_bootstrap_failure else "0"
        )
        env["FAKE_BACKUP_HANG"] = "1" if backup_hang else "0"
        env["FAKE_CATALOG_SEED_FAILURE"] = (
            "1" if catalog_seed_failure else "0"
        )
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


    def test_dry_run_failures_emit_finite_public_codes_without_backup(self):
        """Cinco causas exactas, sin comprometer la guarda D-054 ni filtrar logs."""
        cases = (
            ("timeout", {"dryrun_hang": True}, "dry_run_timeout", 124),
            ("cli", {"dryrun_failure": True}, "dry_run_command_failure", 23),
            ("missing", {"dryrun_empty_sql": True}, "dry_run_sql_missing", 4),
            (
                "destructive",
                {"migration_sql": "DROP TABLE condor_customer;"},
                "dry_run_sql_destructive",
                2,
            ),
            (
                "unapproved",
                {"migration_sql": "GRANT ALL ON *.* TO 'fake'@'%';"},
                "dry_run_sql_unapproved",
                3,
            ),
        )
        for label, kwargs, reason, subcode in cases:
            with self.subTest(case=label):
                result, calls, root = self.run_script("construction", **kwargs)
                self.assertEqual(2, result.returncode, result.stderr)
                status = json.loads(
                    (root / "var/runtime/post-deploy-status.json").read_text(
                        encoding="utf-8",
                    )
                )
                self.assertEqual("dry-run", status["phase"])
                self.assertEqual("failure", status["result"])
                self.assertEqual(2, status["code"])
                self.assertEqual(reason, status["reason"])
                self.assertEqual(subcode, status["subcode"])
                self.assertEqual("unknown", status["backup_client"])
                self.assertIn("--dry-run", calls)
                self.assertNotIn("backup", calls.splitlines())
                self.assertFalse((root / "backup-created").exists())
                self.assertFalse((root / "migrated").exists())
                self.assertNotIn("app:commercial:seed", calls)
                self.assertNotIn("cache:clear", calls)
                self.assertNotIn("cache:warmup", calls)
                self.assertNotIn("sandbox-private-error-marker", json.dumps(status))
                self.assertNotIn("GRANT ALL", json.dumps(status))
                self.assertNotIn("DROP TABLE", json.dumps(status))

    def test_comment_only_sql_reports_missing_without_executing_migration(self):
        result, calls, root = self.run_script(
            "construction",
            migration_sql="-- only a SQL comment, no statement",
        )
        self.assertEqual(2, result.returncode, result.stderr)
        status = json.loads(
            (root / "var/runtime/post-deploy-status.json").read_text(encoding="utf-8")
        )
        self.assertEqual("dry_run_sql_missing", status["reason"])
        self.assertEqual(4, status["subcode"])
        self.assertNotIn("backup", calls.splitlines())
        self.assertFalse((root / "migrated").exists())

    @unittest.skipUnless(shutil.which("php"), "PHP CLI no disponible")
    def test_public_endpoint_allowlists_only_five_dry_run_reasons(self):
        """Contrato público jamás serializa SQL, stderr ni campos adicionales."""
        temp, root, _ = self.sandbox()
        self.addCleanup(temp.cleanup)
        (root / "public").mkdir()
        (root / "config").mkdir()
        (root / "public" / "post-deploy-status.php").write_text(
            STATUS_ENDPOINT.read_text(encoding="utf-8"), encoding="utf-8"
        )
        (root / "config" / "version.php").write_text(
            "<?php return ['version' => '0.1.198'];", encoding="utf-8"
        )
        path = root / "var/runtime/post-deploy-status.json"
        path.parent.mkdir(parents=True)
        all_reasons = (
            "dry_run_timeout",
            "dry_run_command_failure",
            "dry_run_sql_missing",
            "dry_run_sql_destructive",
            "dry_run_sql_unapproved",
        )
        for reason in (*all_reasons, "private-sql-and-credentials"):
            with self.subTest(reason=reason):
                payload = {
                    "version": "0.1.198",
                    "phase": "dry-run",
                    "result": "failure",
                    "code": 2,
                    "reason": reason,
                    "subcode": 23,
                    "backup_client": "unknown",
                    "updated_at": "2026-11-06T16:00:05Z",
                    "private_log": "sandbox-private-error-marker",
                }
                path.write_text(json.dumps(payload), encoding="utf-8")
                result = subprocess.run(
                    ["php", str(root / "public/post-deploy-status.php")],
                    cwd=root,
                    capture_output=True,
                    text=True,
                    timeout=10,
                    check=False,
                )
                self.assertEqual(0, result.returncode, result.stderr)
                exposed = json.loads(result.stdout)["post_deploy"]
                self.assertEqual(
                    reason if reason in all_reasons else "unknown",
                    exposed["reason"],
                )
                self.assertEqual(
                    "dry-run" if reason in all_reasons else "missing",
                    exposed["phase"],
                )
                self.assertEqual(
                    {
                        "version", "phase", "result", "code", "reason",
                        "subcode", "backup_client", "updated_at",
                    },
                    set(exposed),
                )
                self.assertNotIn("sandbox-private-error-marker", result.stdout)
                self.assertNotIn("private_log", result.stdout)
                self.assertNotIn("private-sql-and-credentials", result.stdout)

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
        self.assertLess(
            calls.splitlines().index("backup"),
            calls.splitlines().index(actual_migrate),
        )
        self.assertLess(calls.index(actual_migrate), calls.index("cache:clear"))
        self.assertIn("cache:warmup", calls)

    def test_construction_seeds_catalog_after_schema_before_cache(self):
        """El catálogo se activa tras reconciliar schema y antes de cache."""
        result, calls, root = self.run_script("construction")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue((root / "catalog-seeded").exists())
        self.assertIn("app:commercial:seed --env=prod --no-interaction", calls)
        self.assertLess(
            calls.rindex("doctrine:migrations:up-to-date"),
            calls.index("cache:clear"),
        )
        self.assertLess(
            calls.index("cache:clear"),
            calls.index("app:commercial:seed"),
        )
        self.assertLess(
            calls.index("app:commercial:seed"),
            calls.index("cache:warmup"),
        )

    def test_catalog_seed_failure_blocks_cache_and_success(self):
        """Un seed fallido deja phase=failure y no regenera cache."""
        result, calls, root = self.run_script(
            "construction",
            catalog_seed_failure=True,
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("materialización del catálogo comercial falló", result.stderr)
        self.assertIn("app:commercial:seed", calls)
        self.assertIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)

        import json
        payload = json.loads(
            (root / "var/runtime/post-deploy-status.json").read_text(
                encoding="utf-8",
            )
        )
        self.assertEqual("catalog-seed", payload["phase"])
        self.assertEqual("failure", payload["result"])

    def test_construction_resolves_database_url_from_dotenv_for_backup(self):
        """Sin DATABASE_URL exportada, Symfony dotenv alimenta el backup previo."""
        result, calls, root = self.run_script(
            "construction",
            database_url_in_env=False,
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("backup", calls)
        self.assertTrue((root / "backup-created").exists())
        self.assertEqual(
            "mysql://dotenv.example/condor",
            (root / "backup-url").read_text(encoding="utf-8"),
        )

    def test_construction_prefers_exported_database_url_for_backup(self):
        """DATABASE_URL exportada conserva precedencia sobre Symfony dotenv."""
        result, calls, root = self.run_script(
            "construction",
            database_url_in_env=True,
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("backup", calls)
        self.assertEqual(
            "mysql://env.example/condor",
            (root / "backup-url").read_text(encoding="utf-8"),
        )

    def test_backup_timeout_prevents_migration_and_cache(self):
        """Un backup bloqueado vence por timeout sin migrar ni tocar caché."""
        result, calls, root = self.run_script(
            "construction",
            backup_hang=True,
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("backup previo a migración excedió 2s", result.stderr)
        self.assertFalse((root / "migrated").exists())
        actual = [
            line for line in calls.splitlines()
            if "doctrine:migrations:migrate" in line and "--dry-run" not in line
        ]
        self.assertEqual([], actual)
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)

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

    def test_pdo_failure_does_not_inherit_native_access_denied(self):
        """El fallo final PDO conserva causa/subcódigo propios y bloquea migrate."""
        result, calls, root = self.run_script(
            "construction",
            backup_pdo_failure=True,
        )

        self.assertEqual(2, result.returncode, result.stderr)
        import json
        payload = json.loads(
            (root / "var/runtime/post-deploy-status.json").read_text(
                encoding="utf-8",
            )
        )
        self.assertEqual("backup", payload["phase"])
        self.assertEqual("failure", payload["result"])
        self.assertEqual("pdo_failure", payload["reason"])
        self.assertEqual(35, payload["subcode"])
        self.assertEqual("pdo", payload["backup_client"])
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)

    def test_pdo_bootstrap_failure_does_not_inherit_native_access_denied(self):
        """Un exit 1 terminal PDO sigue siendo pdo_failure, no access_denied."""
        result, calls, root = self.run_script(
            "construction",
            backup_pdo_bootstrap_failure=True,
        )

        self.assertEqual(2, result.returncode, result.stderr)
        import json
        payload = json.loads(
            (root / "var/runtime/post-deploy-status.json").read_text(
                encoding="utf-8",
            )
        )
        self.assertEqual("backup", payload["phase"])
        self.assertEqual("failure", payload["result"])
        self.assertEqual("pdo_failure", payload["reason"])
        self.assertEqual(1, payload["subcode"])
        self.assertEqual("pdo", payload["backup_client"])
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)

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
        self.assertNotIn("app:commercial:seed", calls)


    def test_exact_safe_doctrine_index_renames_preserve_d054_order(self):
        """Admite solo los cuatro renombres ya probados, sin saltar el backup."""
        sql = (
            "ALTER TABLE condor_tenant RENAME INDEX UNIQ_TENANT_SLUG "
            "TO UNIQ_7B8C3E58989D9B62;\n"
            "ALTER TABLE condor_tenant_domain RENAME INDEX UNIQ_DOMAIN_HOSTNAME "
            "TO UNIQ_B8F8428BE551C011;\n"
            "ALTER TABLE condor_tenant_domain RENAME INDEX "
            "uniq_domain_primary_verified_tenant TO UNIQ_B8F8428B9676AA7E;\n"
            "ALTER TABLE condor_storefront_profile RENAME INDEX "
            "uniq_storefront_profile_tenant TO UNIQ_5DBD65159033212A;"
        )
        result, calls, root = self.run_script("construction", migration_sql=sql)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue((root / "backup-created").exists())
        self.assertTrue((root / "migrated").exists())
        steps = calls.splitlines()
        dry = next(i for i, line in enumerate(steps) if "--dry-run" in line)
        backup = steps.index("backup")
        migrated = next(
            i for i, line in enumerate(steps)
            if "doctrine:migrations:migrate" in line and "--dry-run" not in line
        )
        cleared = next(i for i, line in enumerate(steps) if "cache:clear" in line)
        self.assertLess(dry, backup)
        self.assertLess(backup, migrated)
        self.assertLess(migrated, cleared)
        self.assertTrue(any("cache:warmup" in line for line in steps))
        import json
        status = json.loads(
            (root / "var/runtime/post-deploy-status.json").read_text(encoding="utf-8")
        )
        self.assertEqual("success", status["result"])
        self.assertEqual("complete", status["phase"])

        # El permiso SQL no elimina ninguna puerta D-054 ni activa live.
        for options in (
            {"backup_failure": True},
            {"auto_migrate": "0"},
            {"stage": "live"},
        ):
            stage = options.pop("stage", "construction")
            stopped, history, blocked = self.run_script(
                stage, migration_sql=sql, **options,
            )
            self.assertNotEqual(0, stopped.returncode)
            self.assertFalse((blocked / "migrated").exists())
            self.assertNotIn("cache:clear", history)

    def test_index_rename_allowlist_rejects_unapproved_statements(self):
        """Un renombre distinto o SQL concatenado no pasa al backup/migrate."""
        cases = {
            "tabla no listada": (
                "ALTER TABLE another_table RENAME INDEX old TO new;"
            ),
            "indice diferente": (
                "ALTER TABLE condor_tenant RENAME INDEX UNIQ_TENANT_SLUG TO other;"
            ),
            "renombrar tabla": (
                "RENAME TABLE condor_tenant TO another_tenant;"
            ),
            "renombrar columna": (
                "ALTER TABLE condor_tenant RENAME COLUMN slug TO legacy_slug;"
            ),
            "cambio de columna": (
                "ALTER TABLE condor_tenant CHANGE COLUMN slug legacy_slug VARCHAR(255);"
            ),
            "SQL anadido despues de instruccion valida": (
                "ALTER TABLE condor_tenant RENAME INDEX UNIQ_TENANT_SLUG "
                "TO UNIQ_7B8C3E58989D9B62; DROP TABLE condor_tenant;"
            ),
            "revertir indice no autorizado": (
                "ALTER TABLE condor_tenant RENAME INDEX UNIQ_7B8C3E58989D9B62 "
                "TO UNIQ_TENANT_SLUG;"
            ),
        }
        for label, statement in cases.items():
            with self.subTest(sql_case=label):
                result, calls, root = self.run_script(
                    "construction", migration_sql=statement,
                )
                self.assertEqual(2, result.returncode, result.stderr)
                self.assertNotIn("backup", calls.splitlines())
                self.assertFalse((root / "backup-created").exists())
                self.assertFalse((root / "migrated").exists())
                self.assertNotIn("cache:clear", calls)

    def test_construction_blocks_sql_outside_allowlist(self):
        """SQL desconocido falla cerrado antes de backup, migrate y caché."""
        result, calls, root = self.run_script(
            "construction",
            migration_sql="GRANT ALL ON *.* TO 'x'@'%';",
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("SQL de migración fuera del allowlist", result.stderr)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("backup", calls)
        actual = [
            line for line in calls.splitlines()
            if "doctrine:migrations:migrate" in line and "--dry-run" not in line
        ]
        self.assertEqual([], actual)
        self.assertNotIn("cache:clear", calls)
        self.assertNotIn("cache:warmup", calls)

    def test_construction_rejects_comment_only_dry_run(self):
        """Un dry-run sin sentencias SQL validables falla cerrado."""
        result, calls, root = self.run_script(
            "construction",
            migration_sql=(
                "-- Doctrine Migration File Generated on 2026-09-23\n"
                "-- Version DoctrineMigrations\\Version20260923000100"
            ),
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("no produjo SQL validable", result.stderr)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("backup", calls)
        self.assertNotIn("cache:clear", calls)

    def test_construction_blocks_destructive_sql_after_punctuation(self):
        """DROP tras coma también se clasifica como destructivo."""
        result, calls, root = self.run_script(
            "construction",
            migration_sql=(
                "ALTER TABLE safe_table ADD COLUMN next_value INT,"
                "DROP COLUMN legacy_value;"
            ),
        )

        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("migración destructiva/contract detectada", result.stderr)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("backup", calls)
        self.assertNotIn("cache:clear", calls)

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
