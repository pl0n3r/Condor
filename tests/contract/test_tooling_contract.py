#!/usr/bin/env python3
"""Contratos públicos de la infraestructura de pruebas de Condor."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
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
        check_index = script.index("doctrine:migrations:up-to-date")
        migrate_index = script.index("doctrine:migrations:migrate")
        clear_index = script.index("cache:clear")
        warmup_index = script.index("cache:warmup")

        self.assertLess(lock_index, check_index)
        self.assertLess(check_index, migrate_index)
        self.assertLess(migrate_index, clear_index)
        self.assertLess(clear_index, warmup_index)
        self.assertIn(
            'PRODUCTION_STAGE="${CONDOR_PRODUCTION_STAGE:-construction}"',
            script,
        )
        self.assertIn('if [ "$PRODUCTION_STAGE" = "construction" ]; then', script)
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
            "esquema pendiente en construction; ejecutando migraciones versionadas",
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
                    self.assertEqual(invoked, "")

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
