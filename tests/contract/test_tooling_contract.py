#!/usr/bin/env python3
"""Contratos públicos de la infraestructura de pruebas de Condor."""

from __future__ import annotations

import json
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

    def test_post_deploy_blocks_pending_schema_before_cache_under_lock(self) -> None:
        """El cron detecta esquema pendiente y nunca migra producción automáticamente."""
        script = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")

        lock_index = script.index('LOCK_FILE="var/post-deploy.lock"')
        check_index = script.index("doctrine:migrations:up-to-date")
        clear_index = script.index("cache:clear")
        warmup_index = script.index("cache:warmup")

        self.assertLess(lock_index, check_index)
        self.assertLess(check_index, clear_index)
        self.assertLess(clear_index, warmup_index)
        self.assertNotIn("doctrine:migrations:migrate", script)
        self.assertNotIn("doctrine:schema:", script)
        self.assertIn("autorización explícita", script)
        self.assertIn('LOCK_MAX_AGE_SECONDS=21600', script)
        self.assertIn('LOCK_INVALID_GRACE_MINUTES=5', script)
        self.assertIn('LOCK_GUARD_FILE="var/post-deploy.lock.guard"', script)
        self.assertIn('FLOCK_BIN=""', script)
        self.assertIn('exec 9>"$LOCK_GUARD_FILE"', script)
        self.assertIn('"$FLOCK_BIN" -n 9', script)
        self.assertNotIn('mv "$LOCK_GUARD', script)
        self.assertIn('LOCK_TOKEN="$$-$(date +%s)"', script)
        self.assertEqual(
            script.count('printf \'%s\\n%s\\n%s\\n\' "$LOCK_TOKEN" "$$"'),
            1,
        )
        self.assertNotIn('"$LOCK_TOKEN" "$" "$(date +%s)"', script)
        self.assertNotIn("cleanup_guard\n                cleanup_guard", script)
        self.assertIn('kill -0 "$owner_pid"', script)
        self.assertIn('find "$LOCK_FILE" -mmin +', script)
        self.assertIn("set -C", script)
        self.assertIn("trap 'cleanup_schema_check_log; cleanup_guard; cleanup_lock' EXIT", script)
        self.assertIn("trap 'exit 130' INT", script)
        self.assertIn("trap 'exit 143' TERM", script)
        self.assertIn("umask 077", script)
        self.assertIn('SCHEMA_CHECK_LOG="$(mktemp', script)
        self.assertIn("cleanup_schema_check_log()", script)
        self.assertIn("schema_check_status=$?", script)
        self.assertIn("comprobación de esquema falló", script)
        self.assertIn("migraciones pendientes o historial de migraciones no reconciliado", script)
        self.assertIn("fallo de base de datos o conectividad", script)
        self.assertNotIn("up-to-date --env=prod --no-interaction >/dev/null 2>&1", script)

    def test_post_deploy_recovery_mutex_is_stable_under_concurrency(self) -> None:
        """El mutex estable impide que un segundo recuperador sustituya al primero."""
        with tempfile.TemporaryDirectory() as tmp:
            guard = str(Path(tmp) / "post-deploy.lock.guard")
            first = subprocess.Popen(
                ["flock", "-n", guard, "sleep", "1"],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            try:
                time.sleep(0.1)
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
        self.assertIn('evidencias["schema"]', observer)
        self.assertIn('carga.get("schema_up_to_date") is True', observer)

    def test_post_deploy_never_executes_production_migrations_automatically(self) -> None:
        """La política operativa exige autorización humana fuera del cron."""
        script = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")

        forbidden = (
            "doctrine:migrations:migrate",
            "doctrine:migrations:execute",
            "doctrine:schema:update",
            "doctrine:schema:drop",
        )
        for command in forbidden:
            self.assertNotIn(command, script)

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
