"""Aceptación de la tarjeta idempotente D-043 (#464)."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
if str(SCRIPTS) not in sys.path:
    sys.path.insert(0, str(SCRIPTS))

import d043_validation_card as card
import release_evidence

SHA = "a" * 40
VERSION = "0.1.123"


def manifest(*required: str, sha: str = SHA) -> dict[str, object]:
    required_set = set(required)
    return {
        "schema": release_evidence.SCHEMA,
        "version": VERSION,
        "sha": sha,
        "version_source": "config/version.php",
        "change_count": 3,
        "categories": ["release"],
        "selection_mode": "selectivo",
        "selection_reason": "test",
        "public_checks": release_evidence.public_checks_for_version(VERSION),
        "transition": {
            "required": bool(required_set),
            "checks": [
                {
                    "id": check_id,
                    "required": check_id in required_set,
                }
                for check_id in release_evidence.CHECK_IDS
            ],
        },
    }


class D043ValidationCardTests(unittest.TestCase):
    def test_card_contains_exact_identity_required_transition_table_and_prefilled_command(self):
        payload = manifest("migraciones", "cache")
        output = card.build_card(
            payload,
            repository="pl0n3r/Condor",
            tenant_slug="demo-store",
        )

        self.assertIn("## Validación humana D-043 pendiente", output)
        self.assertIn(f"V{VERSION}", output)
        self.assertIn(SHA, output)
        self.assertIn("demo-store", output)
        self.assertIn("migraciones_verificadas=true", output)
        self.assertIn("cache_verificada=true", output)
        self.assertIn("gh workflow run observar-release.yml", output)
        self.assertIn("--repo pl0n3r/Condor", output)
        self.assertIn(f"version={VERSION}", output)
        self.assertIn(f"sha={SHA}", output)

    def test_card_never_marks_human_flags_and_omits_unrequired_flags(self):
        output = card.build_card(
            manifest("cache"),
            repository="pl0n3r/Condor",
        )

        self.assertIn("cache_verificada=true", output)
        self.assertNotIn("migraciones_verificadas=true", output)
        self.assertNotIn("roles_verificados=true", output)
        self.assertNotIn("comandos_verificados=true", output)
        self.assertNotIn("configuracion_verificada=true", output)
        self.assertIn("no ejecuta", output)
        self.assertIn("no marca ningún flag", output)
        self.assertIn("únicamente si el dueño ejecuta el comando", output)

    def test_command_rejects_shell_metacharacters_in_external_inputs(self):
        payload = manifest("cache")

        with self.assertRaises(release_evidence.EvidenceError):
            card.workflow_command(
                payload,
                repository="pl0n3r/Condor;echo-pwned",
            )
        with self.assertRaises(release_evidence.EvidenceError):
            card.workflow_command(
                payload,
                repository="pl0n3r/Condor",
                tenant_slug="demo;echo-pwned",
            )

    def test_command_rejects_tainted_release_identity_before_rendering(self):
        bad_sha = manifest("cache", sha="a" * 39 + ";")
        bad_version = manifest("cache")
        bad_version["version"] = "0.1.123;echo-pwned"

        with self.assertRaises(release_evidence.EvidenceError):
            card.workflow_command(
                bad_sha,
                repository="pl0n3r/Condor",
            )
        with self.assertRaises(release_evidence.EvidenceError):
            card.workflow_command(
                bad_version,
                repository="pl0n3r/Condor",
            )

    def test_marker_is_stable_per_sha_and_changes_for_new_release(self):
        first = card.validation_marker(manifest("cache"))
        second = card.validation_marker(manifest("cache"))
        next_release = card.validation_marker(
            manifest("cache", sha="b" * 40),
        )

        self.assertEqual(first, second)
        self.assertNotEqual(first, next_release)
        self.assertIn(SHA, first)
        self.assertIn("b" * 40, next_release)

    def test_workflow_contract_updates_one_card_only_for_pending_transition(self):
        workflow = (
            ROOT / ".github" / "workflows" / "observar-deploy-automatico.yml"
        ).read_text(encoding="utf-8")

        self.assertIn("scripts/release_evidence.py manifest", workflow)
        self.assertIn("scripts/d043_validation_card.py", workflow)
        self.assertIn(
            "steps.smoke.outputs.estado == 'DEPLOY_OBSERVED'",
            workflow,
        )
        self.assertIn(
            "steps.transicion.outputs.requerida == 'true'",
            workflow,
        )
        self.assertIn("condor-d043-validation-card", workflow)
        self.assertIn("issues/1/comments?per_page=100", workflow)
        self.assertIn("--paginate --slurp", workflow)
        self.assertIn("--method PATCH", workflow)
        self.assertIn("--method POST", workflow)
        self.assertNotIn(
            "gh workflow run observar-release.yml",
            workflow,
        )


if __name__ == "__main__":
    unittest.main()
