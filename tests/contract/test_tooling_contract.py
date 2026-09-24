#!/usr/bin/env python3
"""Contratos públicos de la infraestructura de pruebas de Condor."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import textwrap
import time
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class ToolingContractTests(unittest.TestCase):
    """Protege interfaces estables usadas por personas, agentes y CI."""

    def test_package_exposes_canonical_test_commands(self) -> None:
        """Los comandos públicos de pruebas permanecen disponibles."""
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        scripts = package["scripts"]
        self.assertIn("test:contract", scripts)
        self.assertIn("test:integration", scripts)
        self.assertIn("test:e2e", scripts)
        self.assertIn("test:e2e:webkit", scripts)

    def test_playwright_dependency_is_exact_and_locked(self) -> None:
        """Playwright usa la misma versión exacta en manifest y lockfile."""
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
        requested = package["devDependencies"]["@playwright/test"]
        locked = lock["packages"]["node_modules/@playwright/test"]["version"]
        self.assertRegex(requested, r"^\d+\.\d+\.\d+$")
        self.assertEqual(requested, locked)

    def test_release_workflow_requires_canonical_semver_and_recovers_partial_release(self) -> None:
        """El release automático rechaza ceros iniciales y completa estados parciales."""
        workflow = (
            ROOT / ".github/workflows/tag-release.yml"
        ).read_text(encoding="utf-8")

        self.assertIn(
            r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$",
            workflow,
        )
        self.assertIn("group: condor-tag-release-main", workflow)
        self.assertIn("queue: max", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertIn('git show-ref --verify --quiet "refs/tags/$TAG"', workflow)
        self.assertIn('git cat-file -t "refs/tags/$TAG"', workflow)
        self.assertIn("(409|422)([[:space:]]|$)", workflow)
        self.assertIn('git/ref/tags/$TAG', workflow)
        self.assertNotIn("jq -r", workflow)
        self.assertIn('git/tags/$race_tag_object_sha', workflow)
        self.assertIn('[[ "$race_target_sha" != "$TARGET_SHA" ]]', workflow)
        self.assertIn('gh release view "$TAG"', workflow)
        self.assertIn('gh release create "$TAG"', workflow)
        self.assertIn('--title "Release $TAG (V ${TAG#v})"', workflow)
        self.assertNotIn("if: steps.tag.outputs.created == 'true'", workflow)

    def test_post_deploy_reconciles_construction_schema_before_cache_under_lock(self) -> None:
        """Construction reconcilia migraciones versionadas antes de tocar caché."""
        script = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")

        lock_index = script.index('LOCK_FILE="var/post-deploy.lock"')
        main_check_index = script.index(
            "if run_schema_check; then",
            script.index("# D-053/D-054 / AGENTES.md §10"),
        )
        migrate_call_index = script.index(
            "if run_construction_migrations; then",
            main_check_index,
        )
        clear_index = script.index("cache:clear", migrate_call_index)
        warmup_index = script.index("cache:warmup", clear_index)

        self.assertLess(lock_index, main_check_index)
        self.assertLess(main_check_index, migrate_call_index)
        self.assertLess(migrate_call_index, clear_index)
        self.assertLess(clear_index, warmup_index)
        self.assertIn(
            'PRODUCTION_STAGE="${CONDOR_PRODUCTION_STAGE:-construction}"',
            script,
        )
        self.assertIn(
            'AUTO_MIGRATE="${CONDOR_AUTO_MIGRATE:-1}"',
            script,
        )
        self.assertIn(
            'if [ "$PRODUCTION_STAGE" = "construction" ] && [ "$AUTO_MIGRATE" = "1" ]; then',
            script,
        )
        self.assertNotIn("doctrine:migrations:execute", script)
        self.assertNotIn("doctrine:schema:update", script)
        self.assertNotIn("doctrine:schema:drop", script)

    def test_post_deploy_lock_creation_failure_is_not_success(self) -> None:
        """Un fallo real al publicar/adquirir el guard termina distinto de cero."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )

            # ENOTDIR determinista al intentar abrir var/post-deploy.lock.guard.
            (root / "var").write_text("not-a-directory", encoding="utf-8")

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
                binary.chmod(0o755)

            env = dict(os.environ)
            env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
            result = subprocess.run(
                ["sh", str(script)],
                cwd=root,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                timeout=5,
            )

            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn(
                "no fue posible preparar el directorio del lock",
                result.stderr,
            )
            self.assertIn(
                "no fue posible adquirir el lock de forma segura",
                result.stderr,
            )

    def test_post_deploy_concurrent_guard_is_safe_skip(self) -> None:
        """Un guard ocupado representa concurrencia legítima y termina con éxito."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            var = root / "var"
            var.mkdir()
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
                binary.chmod(0o755)

            guard = str(var / "post-deploy.lock.guard")
            holder = subprocess.Popen(
                ["flock", "-n", guard, "sh", "-c", "printf ready; sleep 2"],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            try:
                self.assertIsNotNone(holder.stdout)
                self.assertEqual(holder.stdout.read(5), "ready")

                env = dict(os.environ)
                env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
                result = subprocess.run(
                    ["sh", str(script)],
                    cwd=root,
                    env=env,
                    check=False,
                    capture_output=True,
                    text=True,
                    timeout=5,
                )

                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(
                    "otra recuperación de lock sigue activa; se omite",
                    result.stderr,
                )
            finally:
                holder.wait(timeout=4)

    def test_post_deploy_classifies_schema_drift_before_database_word(self) -> None:
        """Pending migra en construction; historial desconocido sigue fallando cerrado."""
        pending, pending_calls = self._run_post_deploy_with_fake_php(
            schema_output="Out-of-date! 1 migration to execute.",
            schema_status=1,
        )
        self.assertEqual(pending.returncode, 2, pending.stderr)
        self.assertIn(
            "esquema pendiente en construction; validando migraciones versionadas",
            pending.stderr,
        )
        self.assertIn("doctrine:migrations:migrate", pending_calls)
        self.assertIn(
            "la migración terminó pero el esquema no quedó reconciliado",
            pending.stderr,
        )
        self.assertNotIn("cache:clear", pending_calls)
        self.assertNotIn("cache:warmup", pending_calls)

        unregistered, unregistered_calls = self._run_post_deploy_with_fake_php(
            schema_output=(
                "You have 1 previously executed migrations in the database "
                "that are not registered migrations."
            ),
            schema_status=1,
        )
        self.assertEqual(unregistered.returncode, 2, unregistered.stderr)
        self.assertIn(
            "el historial de migraciones no coincide con el catálogo desplegado",
            unregistered.stderr,
        )
        self.assertNotIn("doctrine:migrations:migrate", unregistered_calls)
        self.assertNotIn("cache:clear", unregistered_calls)
        self.assertNotIn("cache:warmup", unregistered_calls)

    def test_post_deploy_recovers_orphan_and_stale_incomplete_locks(self) -> None:
        """Locks sin guard activo se recuperan; metadata incompleta reciente se omite."""
        now = int(time.time())
        cases = (
            (
                "orphan-valid",
                f"old-token\n99999999\n{now}\n",
                None,
                "lock huérfano detectado",
                True,
            ),
            (
                "stale-incomplete",
                "broken-token\n",
                now - 400,
                "lock incompleto huérfano",
                True,
            ),
            (
                "recent-incomplete",
                "broken-token\n",
                None,
                "lock incompleto reciente",
                False,
            ),
        )
        for name, metadata, mtime, diagnostic, should_run_schema in cases:
            with self.subTest(case=name):
                result, invoked = self._run_post_deploy_with_fake_php(
                    schema_output="Up-to-date!",
                    schema_status=0,
                    lock_metadata=metadata,
                    lock_mtime=mtime,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(diagnostic, result.stderr)
                if should_run_schema:
                    self.assertIn("doctrine:migrations:up-to-date", invoked)
                    self.assertIn("cache:clear", invoked)
                    self.assertIn("cache:warmup", invoked)
                else:
                    self.assertNotIn("doctrine:migrations:up-to-date", invoked)
                    self.assertNotIn("cache:clear", invoked)
                    self.assertNotIn("cache:warmup", invoked)

    def test_post_deploy_schema_check_timeout_never_touches_cache(self) -> None:
        """Doctrine colgado vence el límite de pared antes de cualquier caché."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            (root / "var").mkdir()
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            calls = root / "php-calls.log"
            fake_php = """#!/bin/sh
printf '%s\\n' "$*" >> "$FAKE_PHP_LOG"
case "$*" in
  *doctrine:migrations:up-to-date*)
    while :; do :; done
    ;;
  *)
    exit 0
    ;;
esac
"""
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text(fake_php, encoding="utf-8")
                binary.chmod(0o755)

            env = dict(os.environ)
            env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
            env["FAKE_PHP_LOG"] = str(calls)
            env["CONDOR_SCHEMA_CHECK_TIMEOUT_SECONDS"] = "1"
            result = subprocess.run(
                ["sh", str(script)],
                cwd=root,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                timeout=6,
            )

            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertIn(
                "comprobación de esquema excedió 1s",
                result.stderr,
            )
            invoked = calls.read_text(encoding="utf-8")
            self.assertIn("doctrine:migrations:up-to-date", invoked)
            self.assertNotIn("cache:clear", invoked)
            self.assertNotIn("cache:warmup", invoked)

    def test_post_deploy_recovery_mutex_is_stable_under_concurrency(self) -> None:
        """El mutex estable impide que un segundo recuperador sustituya al primero."""
        with tempfile.TemporaryDirectory() as tmp:
            guard = str(Path(tmp) / "post-deploy.lock.guard")
            first = subprocess.Popen(
                ["flock", "-n", guard, "sh", "-c", "printf ready; sleep 1"],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            try:
                self.assertIsNotNone(first.stdout)
                self.assertEqual(first.stdout.read(5), "ready")
                second = subprocess.run(
                    ["flock", "-n", guard, "true"],
                    check=False,
                    capture_output=True,
                    text=True,
                )
                self.assertNotEqual(second.returncode, 0)
            finally:
                first.wait(timeout=3)

            third = subprocess.run(
                ["flock", "-n", guard, "true"],
                check=False,
                capture_output=True,
                text=True,
            )
            self.assertEqual(third.returncode, 0, third.stderr)

    def test_health_exposes_schema_state_for_remote_release_validation(self) -> None:
        """El smoke remoto recibe solo un booleano de esquema, sin internals."""
        controller = (
            ROOT / "src/Http/Controller/HealthController.php"
        ).read_text(encoding="utf-8")
        observer = (
            ROOT / "scripts/observar_release.py"
        ).read_text(encoding="utf-8")

        self.assertIn("'schema_up_to_date' => $schemaUpToDate", controller)
        self.assertIn("getMigrationStatusCalculator()", controller)
        self.assertIn("getNewMigrations()", controller)
        self.assertIn("getExecutedUnavailableMigrations()", controller)
        self.assertIn('evidencias["schema"]', observer)
        self.assertIn('carga.get("schema_up_to_date") is True', observer)

    def test_backup_uses_only_supported_shared_hosting_options(self) -> None:
        """El dump reduce privilegios globales sin romper MariaDB/legacy."""

        def run_backup(
            dump_name: str,
            *,
            supports_no_tablespaces: bool,
            supports_gtid_purged: bool = False,
            supports_column_statistics: bool = False,
        ) -> tuple[list[str], str]:
            """Ejecuta el backup y devuelve argumentos más login-path observado."""
            with tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                scripts = root / "scripts"
                scripts.mkdir()
                (scripts / "backup-database.sh").write_text(
                    (ROOT / "scripts/backup-database.sh").read_text(encoding="utf-8"),
                    encoding="utf-8",
                )
                (scripts / "parse-database-url.php").write_text(
                    (ROOT / "scripts/parse-database-url.php").read_text(encoding="utf-8"),
                    encoding="utf-8",
                )
                runtime = root / "src" / "Shared" / "Runtime"
                runtime.mkdir(parents=True)
                (runtime / "DatabaseDsn.php").write_text(
                    (
                        ROOT / "src" / "Shared" / "Runtime" / "DatabaseDsn.php"
                    ).read_text(encoding="utf-8"),
                    encoding="utf-8",
                )

                fake_bin = root / "fake-bin"
                fake_bin.mkdir()
                dump_args = root / "dump-args.log"
                login_path = root / "login-path.log"
                dump = fake_bin / dump_name
                dump.write_text(
                    textwrap.dedent(
                        """\
                        #!/bin/sh
                        set -eu
                        if [ "${1:-}" = "--help" ]; then
                          [ "${FAKE_SUPPORTS_NO_TABLESPACES:-0}" = "1" ] &&
                            printf '%s\\n' '  --no-tablespaces'
                          [ "${FAKE_SUPPORTS_GTID_PURGED:-0}" = "1" ] &&
                            printf '%s\\n' '  --set-gtid-purged=value'
                          [ "${FAKE_SUPPORTS_COLUMN_STATISTICS:-0}" = "1" ] &&
                            printf '%s\\n' '  --column-statistics'
                          exit 0
                        fi
                        printf '%s\\n' "$@" > "$FAKE_DUMP_ARGS"
                        printf '%s' "${MYSQL_TEST_LOGIN_FILE:-}" > "$FAKE_LOGIN_PATH"
                        printf '%s\\n' 'CREATE TABLE backup_probe (id INT);'
                        """
                    ),
                    encoding="utf-8",
                )
                dump.chmod(0o755)

                env = dict(os.environ)
                env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
                env["DATABASE_URL"] = (
                    "mysql://backup_user:backup_pass@127.0.0.1:3306/condor"
                )
                env["BACKUP_DIR"] = str(root / "backups")
                env["FAKE_DUMP_ARGS"] = str(dump_args)
                env["FAKE_LOGIN_PATH"] = str(login_path)
                env["FAKE_SUPPORTS_NO_TABLESPACES"] = (
                    "1" if supports_no_tablespaces else "0"
                )
                env["FAKE_SUPPORTS_GTID_PURGED"] = (
                    "1" if supports_gtid_purged else "0"
                )
                env["FAKE_SUPPORTS_COLUMN_STATISTICS"] = (
                    "1" if supports_column_statistics else "0"
                )

                result = subprocess.run(
                    ["sh", str(scripts / "backup-database.sh")],
                    cwd=root,
                    env=env,
                    check=False,
                    capture_output=True,
                    text=True,
                    timeout=10,
                )

                self.assertEqual(result.returncode, 0, result.stderr)
                return (
                    dump_args.read_text(encoding="utf-8").splitlines(),
                    login_path.read_text(encoding="utf-8"),
                )

        mariadb_supported, mariadb_supported_login = run_backup(
            "mariadb-dump",
            supports_no_tablespaces=True,
            supports_gtid_purged=True,
        )
        mariadb_legacy, mariadb_legacy_login = run_backup(
            "mariadb-dump",
            supports_no_tablespaces=False,
        )
        mysql_supported, mysql_supported_login = run_backup(
            "mysqldump",
            supports_no_tablespaces=True,
            supports_gtid_purged=True,
            supports_column_statistics=True,
        )
        mysql_legacy, mysql_legacy_login = run_backup(
            "mysqldump",
            supports_no_tablespaces=False,
            supports_gtid_purged=False,
            supports_column_statistics=False,
        )

        self.assertIn("--no-tablespaces", mariadb_supported)
        self.assertNotIn("--no-tablespaces", mariadb_legacy)
        self.assertNotIn("--set-gtid-purged=OFF", mariadb_supported)
        self.assertNotIn("--set-gtid-purged=OFF", mariadb_legacy)

        self.assertIn("--no-tablespaces", mysql_supported)
        self.assertIn("--set-gtid-purged=OFF", mysql_supported)
        self.assertIn("--column-statistics=0", mysql_supported)
        self.assertNotIn("--no-tablespaces", mysql_legacy)
        self.assertNotIn("--set-gtid-purged=OFF", mysql_legacy)
        self.assertNotIn("--column-statistics=0", mysql_legacy)

        self.assertEqual(mariadb_supported_login, "")
        self.assertEqual(mariadb_legacy_login, "")
        self.assertTrue(mysql_supported_login.endswith(".cnf.login"))
        self.assertTrue(mysql_legacy_login.endswith(".cnf.login"))

        for args in (
            mariadb_supported,
            mariadb_legacy,
            mysql_supported,
            mysql_legacy,
        ):
            self.assertTrue(args[0].startswith("--defaults-file="), args)
            self.assertFalse(
                any(arg.startswith("--defaults-extra-file=") for arg in args),
                args,
            )
            self.assertIn("--single-transaction", args)
            self.assertIn("--quick", args)
            self.assertIn("--skip-lock-tables", args)
            self.assertIn("--skip-triggers", args)
            self.assertNotIn("--triggers", args)
            self.assertEqual(args[-1], "condor")

    def test_post_deploy_client_extraction_avoids_nonportable_sed_alternation(self) -> None:
        """La detección del cliente usa patrones BRE portables y explícitos."""
        script = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")

        self.assertNotIn(r"mariadb-dump\|mysqldump", script)
        self.assertIn(
            "cliente seleccionado: mariadb-dump\\.$/mariadb-dump/p",
            script,
        )
        self.assertIn(
            "cliente seleccionado: mysqldump\\.$/mysqldump/p",
            script,
        )

    def test_post_deploy_persists_sanitized_terminal_status(self) -> None:
        """El cron deja una fase terminal segura sin logs, SQL ni credenciales."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            (root / "var").mkdir()
            (root / "config").mkdir()
            (root / "config/version.php").write_text(
                "<?php return ['version' => '0.1.28'];\n",
                encoding="utf-8",
            )
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            fake_php = """#!/bin/sh
case "$*" in
  *"-r "*"config/version.php"*)
    printf '%s' '0.1.28'
    exit 0
    ;;
  *doctrine:migrations:up-to-date*)
    printf '%s\\n' 'Up-to-date!' >&2
    exit 0
    ;;
  *)
    exit 0
    ;;
esac
"""
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text(fake_php, encoding="utf-8")
                binary.chmod(0o755)

            env = dict(os.environ)
            env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
            result = subprocess.run(
                ["sh", str(script)],
                cwd=root,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                timeout=6,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            payload = json.loads(
                (root / "var/runtime/post-deploy-status.json").read_text(
                    encoding="utf-8"
                )
            )
            self.assertEqual(payload["version"], "0.1.28")
            self.assertEqual(payload["phase"], "complete")
            self.assertEqual(payload["result"], "success")
            self.assertEqual(payload["code"], 0)
            self.assertEqual(payload["reason"], "none")
            self.assertEqual(payload["subcode"], 0)
            self.assertEqual(payload["backup_client"], "unknown")
            self.assertEqual(
                set(payload),
                {
                    "version",
                    "phase",
                    "result",
                    "code",
                    "reason",
                    "subcode",
                    "backup_client",
                    "updated_at",
                },
            )

    def test_post_deploy_marks_early_configuration_failure_terminal(self) -> None:
        """Fallos de bootstrap nunca dejan el probe falsamente running."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            (root / "config").mkdir()
            (root / "config/version.php").write_text(
                "<?php return ['version' => '0.1.28'];\n",
                encoding="utf-8",
            )
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text(
                    "#!/bin/sh\nprintf '%s' '0.1.28'\n",
                    encoding="utf-8",
                )
                binary.chmod(0o755)

            env = dict(os.environ)
            env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
            env["CONDOR_AUTO_MIGRATE"] = "invalid"
            result = subprocess.run(
                ["sh", str(script)],
                cwd=root,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                timeout=6,
            )

            self.assertEqual(result.returncode, 1, result.stderr)
            payload = json.loads(
                (root / "var/runtime/post-deploy-status.json").read_text(
                    encoding="utf-8"
                )
            )
            self.assertEqual(payload["version"], "0.1.28")
            self.assertEqual(payload["phase"], "bootstrap")
            self.assertEqual(payload["result"], "failure")
            self.assertEqual(payload["code"], 1)

    def test_post_deploy_classifies_backup_failure_without_exposing_raw_error(self) -> None:
        """El probe persiste solo enums seguros ante un fallo realista del dump."""
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            (root / "var").mkdir()
            (root / "config").mkdir()
            (root / "config/version.php").write_text(
                "<?php return ['version' => '0.1.30'];\n",
                encoding="utf-8",
            )
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            backup = scripts / "backup-database.sh"
            backup.write_text(
                textwrap.dedent(
                    """\
                    #!/bin/sh
                    set -eu
                    printf '%s\\n' 'backup-database.sh: cliente seleccionado: mariadb-dump.' >&2
                    printf '%s\\n' "mariadb-dump: Got error: 1044 Access denied for user 'secret-user'@'secret-host' to database 'secret_db' when using LOCK TABLES" >&2
                    exit 2
                    """
                ),
                encoding="utf-8",
            )
            backup.chmod(0o755)

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            fake_php = """#!/bin/sh
case "$*" in
  *"-r "*"config/version.php"*)
    printf '%s' '0.1.30'
    exit 0
    ;;
  *doctrine:migrations:up-to-date*)
    printf '%s\\n' 'Out-of-date! 1 migration to execute.' >&2
    exit 1
    ;;
  *doctrine:migrations:migrate*"--dry-run"*)
    for arg in "$@"; do
      case "$arg" in
        --write-sql=*) printf '%s\\n' 'CREATE TABLE safe_table (id INT);' > "${arg#--write-sql=}" ;;
      esac
    done
    exit 0
    ;;
  *)
    exit 0
    ;;
esac
"""
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text(fake_php, encoding="utf-8")
                binary.chmod(0o755)

            env = dict(os.environ)
            env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
            env["DATABASE_URL"] = (
                "mysql://backup_user:backup_pass@127.0.0.1:3306/condor"
            )
            result = subprocess.run(
                ["sh", str(script)],
                cwd=root,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                timeout=8,
            )

            self.assertEqual(result.returncode, 2, result.stderr)
            payload = json.loads(
                (root / "var/runtime/post-deploy-status.json").read_text(
                    encoding="utf-8"
                )
            )
            self.assertEqual(payload["phase"], "backup")
            self.assertEqual(payload["result"], "failure")
            self.assertEqual(payload["code"], 2)
            self.assertEqual(payload["reason"], "access_denied")
            self.assertEqual(payload["subcode"], 2)
            self.assertEqual(payload["backup_client"], "mariadb-dump")
            rendered = json.dumps(payload)
            self.assertNotIn("secret-user", rendered)
            self.assertNotIn("secret-host", rendered)
            self.assertNotIn("secret_db", rendered)
            self.assertNotIn("secret-user", result.stderr)
            self.assertNotIn("secret-host", result.stderr)
            self.assertNotIn("secret_db", result.stderr)
            self.assertIn("reason=access_denied", result.stderr)
            self.assertIn("client=mariadb-dump", result.stderr)
            self.assertIn("subcode=2", result.stderr)

    def test_pdo_backup_hardening_is_wired_end_to_end(self) -> None:
        """PDO queda bajo watchdog, checksum y allowlists de telemetría."""
        backup = (ROOT / "scripts/backup-database.sh").read_text(encoding="utf-8")
        pdo = (ROOT / "scripts/backup-database-pdo.php").read_text(encoding="utf-8")
        post_deploy = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")
        observer = (ROOT / "scripts/observar_release.py").read_text(encoding="utf-8")
        endpoint = (ROOT / "public/post-deploy-status.php").read_text(encoding="utf-8")

        self.assertIn(
            '"$PHP_BIN" "$script_dir/backup-database-pdo.php" "$raw_tmp" &',
            backup,
        )
        self.assertIn("hash_init('sha256')", pdo)
        self.assertIn("hash_equals($expectedChecksum, $actualChecksum)", pdo)
        self.assertIn("mariadb-dump|mysqldump|pdo", post_deploy)
        self.assertIn('"pdo"', observer)
        self.assertIn("'pdo'", endpoint)

    def test_post_deploy_status_endpoint_never_exposes_logs_or_secrets(self) -> None:
        """El probe público solo publica identidad y estado operacional acotado."""
        endpoint = (
            ROOT / "public/post-deploy-status.php"
        ).read_text(encoding="utf-8")
        self.assertIn("'post_deploy' => $postDeploy", endpoint)
        self.assertIn("'release_sha' => $releaseSha", endpoint)
        self.assertNotIn("DATABASE_URL", endpoint)
        self.assertNotIn("SCHEMA_CHECK_LOG", endpoint)
        self.assertNotIn("MIGRATION_LOG", endpoint)
        self.assertNotIn("BACKUP_LOG", endpoint)
        self.assertIn("'reason' => $reason", endpoint)
        self.assertIn("'subcode' => $subcode", endpoint)
        self.assertIn("'backup_client' => $backupClient", endpoint)

    def test_post_deploy_never_executes_destructive_schema_mutations_automatically(self) -> None:
        """Solo migrate versionado puede automatizarse; operaciones destructivas no."""
        script = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")

        self.assertIn("doctrine:migrations:migrate", script)
        forbidden = (
            "doctrine:migrations:execute",
            "doctrine:schema:update",
            "doctrine:schema:drop",
        )
        for command in forbidden:
            self.assertNotIn(command, script)

    def _run_post_deploy_with_fake_php(
        self,
        *,
        schema_output: str,
        schema_status: int,
        lock_metadata: str | None = None,
        lock_mtime: int | None = None,
    ) -> tuple[subprocess.CompletedProcess[str], str]:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            var = root / "var"
            var.mkdir()
            script = scripts / "post-deploy.sh"
            script.write_text(
                (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            backup = scripts / "backup-database.sh"
            backup.write_text(
                "#!/bin/sh\nset -eu\n[ -n \"${DATABASE_URL:-}\" ] || exit 19\nexit 0\n",
                encoding="utf-8",
            )
            backup.chmod(0o755)

            if lock_metadata is not None:
                lock_file = var / "post-deploy.lock"
                lock_file.write_text(lock_metadata, encoding="utf-8")
                if lock_mtime is not None:
                    os.utime(lock_file, (lock_mtime, lock_mtime))

            fake_bin = root / "fake-bin"
            fake_bin.mkdir()
            calls = root / "php-calls.log"
            fake_php = """#!/bin/sh
printf '%s\\n' "$*" >> "$FAKE_PHP_LOG"
case "$*" in
  *doctrine:migrations:up-to-date*)
    printf '%s\\n' "$FAKE_SCHEMA_OUTPUT" >&2
    exit "$FAKE_SCHEMA_STATUS"
    ;;
  *doctrine:migrations:migrate*"--dry-run"*)
    for arg in "$@"; do
      case "$arg" in
        --write-sql=*) printf '%s\\n' 'CREATE TABLE safe_table (id INT);' > "${arg#--write-sql=}" ;;
      esac
    done
    exit 0
    ;;
  *)
    exit 0
    ;;
esac
"""
            for name in ("php85", "php"):
                binary = fake_bin / name
                binary.write_text(fake_php, encoding="utf-8")
                binary.chmod(0o755)

            env = dict(os.environ)
            env["PATH"] = str(fake_bin) + os.pathsep + env.get("PATH", "")
            env["FAKE_PHP_LOG"] = str(calls)
            env["FAKE_SCHEMA_OUTPUT"] = schema_output
            env["FAKE_SCHEMA_STATUS"] = str(schema_status)
            env["DATABASE_URL"] = "mysql://contract.example/condor"
            result = subprocess.run(
                ["sh", str(script)],
                cwd=root,
                env=env,
                check=False,
                capture_output=True,
                text=True,
                timeout=6,
            )
            invoked = (
                calls.read_text(encoding="utf-8")
                if calls.exists()
                else ""
            )
            return result, invoked

    def test_throughput_cli_preserves_json_report_contract(self) -> None:
        """El CLI de throughput entrega el esquema consumido por automatizaciones."""
        payload = {
            "jobs": [
                {
                    "jobs": [
                        {
                            "name": "Preflight",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:00Z",
                            "completed_at": "2026-09-19T10:00:02Z",
                        },
                        {
                            "name": "Validar",
                            "conclusion": "success",
                            "started_at": "2026-09-19T10:00:02Z",
                            "completed_at": "2026-09-19T10:00:04Z",
                        },
                    ]
                }
            ],
            "history": {"workflow_runs": []},
        }
        command = [
            "python3",
            "scripts/ci_throughput_report.py",
            "report",
            "--run-id",
            "42",
            "--source-sha",
            "abc123",
            "--event",
            "push",
            "--conclusion",
            "success",
            "--started-at",
            "2026-09-19T10:00:00Z",
            "--updated-at",
            "2026-09-19T10:00:04Z",
        ]
        result = subprocess.run(
            command,
            cwd=ROOT,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report["run_id"], 42)
        self.assertEqual(report["source_sha"], "abc123")
        self.assertEqual(report["workflow_wall_seconds"], 4)
        self.assertIn("critical_path", report)
        self.assertIn("regression", report)
        self.assertIsInstance(report["jobs"], list)


if __name__ == "__main__":
    unittest.main()
