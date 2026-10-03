"""Regresiones de auto-validación D-043 durante la fase de construcción."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "release_evidence.py"
WORKFLOW = ROOT / ".github" / "workflows" / "observar-deploy-automatico.yml"

spec = importlib.util.spec_from_file_location("release_evidence_d043_dev", SCRIPT)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

SHA = "a" * 40
VERSION = "0.1.137"


def manifest() -> dict:
    return {
        "schema": module.SCHEMA,
        "version": VERSION,
        "sha": SHA,
        "version_source": "config/version.php",
        "change_count": 1,
        "categories": ["infraestructura"],
        "selection_mode": "conservative",
        "selection_reason": "test",
        "public_checks": list(module.public_checks_for_version(VERSION)),
        "transition": {
            "required": True,
            "checks": [
                {"id": "migraciones", "required": False},
                {"id": "roles", "required": False},
                {"id": "comandos", "required": True},
                {"id": "configuracion", "required": False},
                {"id": "cache", "required": True},
            ],
        },
    }


def observation() -> dict:
    checks = {
        check_id: {"ok": True, "clase": "ok", "detalle": "ok"}
        for check_id in module.public_checks_for_version(VERSION)
    }
    checks["schema"] = {"ok": True, "clase": "ok", "detalle": "schema al día"}
    checks["transicion_release"] = {
        "ok": False,
        "clase": "funcional",
        "detalle": "pendiente de evidencia automática",
    }
    checks["post_deploy_status"] = {
        "ok": True,
        "phase": "complete",
        "result": "success",
        "detalle": "exact-sha post-deploy complete",
    }
    return {
        "estado": "DEPLOY_OBSERVED",
        "version_esperada": VERSION,
        "sha_esperado": SHA,
        "comprobaciones": checks,
    }


class D043DevAutoValidationTests(unittest.TestCase):
    def test_auto_validation_requires_exact_sha_health_schema_smoke_and_manifest_evidence(self) -> None:
        evidence = module.development_auto_validation(
            manifest(),
            observation(),
            phase="construccion",
            transition_safety={"comandos": True, "migraciones": True},
        )
        self.assertEqual(evidence["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertTrue(evidence["development_auto_validation"]["eligible"])
        self.assertEqual(
            evidence["development_auto_validation"]["auto_verified"],
            ["cache", "comandos"],
        )

    def test_missing_or_failing_condition_stays_deploy_observed(self) -> None:
        cases = []

        wrong_sha = observation()
        wrong_sha["sha_esperado"] = "b" * 40
        cases.append(wrong_sha)

        bad_schema = observation()
        bad_schema["comprobaciones"]["schema"]["ok"] = False
        cases.append(bad_schema)

        bad_smoke = observation()
        bad_smoke["comprobaciones"]["home"]["ok"] = False
        cases.append(bad_smoke)

        bad_post = observation()
        bad_post["comprobaciones"]["post_deploy_status"]["result"] = "failure"
        cases.append(bad_post)

        for payload in cases:
            with self.subTest(payload=payload):
                evidence = module.development_auto_validation(
                    manifest(),
                    payload,
                    phase="construccion",
                    transition_safety={"comandos": True, "migraciones": True},
                )
                self.assertNotEqual(evidence["estado"], "VALIDATED_IN_PRODUCTION")
                self.assertFalse(evidence["development_auto_validation"]["eligible"])

    def test_disabled_outside_construction_phase_and_for_destructive_migrations(self) -> None:
        outside = module.development_auto_validation(
            manifest(),
            observation(),
            phase="live",
            transition_safety={"comandos": True, "migraciones": True},
        )
        destructive_manifest = manifest()
        for item in destructive_manifest["transition"]["checks"]:
            if item["id"] == "migraciones":
                item["required"] = True
        destructive = module.development_auto_validation(
            destructive_manifest,
            observation(),
            phase="construccion",
            transition_safety={"comandos": True, "migraciones": False},
        )
        self.assertFalse(outside["development_auto_validation"]["eligible"])
        self.assertEqual(
            outside["development_auto_validation"]["reason"], "phase_disabled"
        )
        self.assertFalse(destructive["development_auto_validation"]["eligible"])
        self.assertEqual(
            destructive["development_auto_validation"]["reason"],
            "migration_not_proven_safe",
        )


    def test_transition_provenance_is_fail_closed_for_migrations_and_unknown_commands(self) -> None:
        safe = module.development_transition_safety([
            "scripts/release_evidence.py",
            "scripts/d043_pending_releases.py",
            "config/version.php",
        ])
        unsafe_command = module.development_transition_safety([
            "scripts/provision-production.php",
        ])
        migration = module.development_transition_safety([
            "migrations/Version20261003000100.php",
        ])

        self.assertTrue(safe["comandos"])
        self.assertTrue(safe["migraciones"])
        self.assertFalse(unsafe_command["comandos"])
        self.assertEqual(
            unsafe_command["unsafe_command_paths"],
            ["scripts/provision-production.php"],
        )
        self.assertFalse(migration["migraciones"])
        self.assertEqual(
            migration["migration_paths"],
            ["migrations/Version20261003000100.php"],
        )

    def test_auto_validation_is_recorded_distinguishable_and_idempotent(self) -> None:
        evidence = module.development_auto_validation(
            manifest(),
            observation(),
            phase="construccion",
            transition_safety={"comandos": True, "migraciones": True},
        )
        first = module.development_validation_comment(evidence)
        second = module.development_validation_comment(evidence)
        self.assertEqual(first, second)
        self.assertIn("condor-d043-dev-auto", first)
        self.assertIn("validación automática de desarrollo", first)
        self.assertIn("#389", first)

        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("issues/389", workflow)
        self.assertIn("steps.fase.outputs.value", workflow)
        self.assertNotIn("FASE_CONDOR: construccion", workflow)
        self.assertIn("for issue_number in 1 389", workflow)
        self.assertIn("steps.version.outputs.bootstrap_only", workflow)
        self.assertIn("steps.version.outputs.bootstrap_previous", workflow)
        self.assertIn(
            "bootstrap_only == 'true' || steps.version.outputs.bootstrap_previous == 'true'",
            workflow,
        )
        self.assertIn('bootstrap_previous=true', workflow)


if __name__ == "__main__":
    unittest.main()
