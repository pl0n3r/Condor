"""Contrato del wiring de manifiestos D-043 acumulados en workflows."""

from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUTO = ROOT / ".github" / "workflows" / "observar-deploy-automatico.yml"
MANUAL = ROOT / ".github" / "workflows" / "observar-release.yml"


class D043AccumulatedWorkflowContractTests(unittest.TestCase):
    def read(self, path: Path) -> str:
        return path.read_text(encoding="utf-8")

    def test_automatic_observer_reduces_roadmap_rebuilds_pending_manifests_and_accumulates_before_card(self) -> None:
        workflow = self.read(AUTO)
        self.assertIn("scripts/d043_pending_releases.py", workflow)
        self.assertIn("manifest-explicit", workflow)
        self.assertIn("release_evidence.py accumulate", workflow)
        self.assertIn('git show "${sha}:config/version.php"', workflow)
        self.assertIn("steps.acumulado.outputs.requerida", workflow)
        self.assertLess(
            workflow.index("release_evidence.py accumulate"),
            workflow.index("scripts/d043_validation_card.py"),
        )

    def test_manual_observer_accumulates_pending_manifests_before_finalize(self) -> None:
        workflow = self.read(MANUAL)
        self.assertIn("issues: read", workflow)
        self.assertIn("ref: main", workflow)
        self.assertIn("fetch-depth: 0", workflow)
        self.assertNotIn("ref: ${{ inputs.sha }}", workflow)
        self.assertIn('git merge-base --is-ancestor "$SHA_ESPERADO" HEAD', workflow)
        self.assertIn('git show "${SHA_ESPERADO}:config/version.php"', workflow)
        self.assertIn("manifest-explicit", workflow)
        self.assertIn("scripts/d043_pending_releases.py", workflow)
        self.assertIn("manifest-explicit", workflow)
        self.assertIn("release_evidence.py accumulate", workflow)
        self.assertLess(
            workflow.index("release_evidence.py accumulate"),
            workflow.index("finalize"),
        )

    def test_manual_observer_never_executes_code_from_user_supplied_sha(self) -> None:
        workflow = self.read(MANUAL)
        checkout = workflow[
            workflow.index("- name: Descargar código confiable de main"):
            workflow.index("- name: Probar evidencia sin tocar producción")
        ]
        self.assertIn("ref: main", checkout)
        self.assertNotIn("inputs.sha", checkout)
        self.assertIn('git merge-base --is-ancestor "$SHA_ESPERADO" HEAD', workflow)
        self.assertLess(
            workflow.index('git merge-base --is-ancestor "$SHA_ESPERADO" HEAD'),
            workflow.index("python3 -m unittest"),
        )

    def test_workflows_fail_closed_on_missing_or_mismatched_historical_identity(self) -> None:
        for workflow in (self.read(AUTO), self.read(MANUAL)):
            self.assertIn('git cat-file -e "${sha}^{commit}"', workflow)
            self.assertIn('git show "${sha}:config/version.php"', workflow)
            self.assertIn('if [[ "$historical_version" != "$version" ]]', workflow)
            self.assertNotIn('git cat-file -e "${sha}^{commit}" || true', workflow)

    def test_no_automatic_workflow_sets_human_verification_flags(self) -> None:
        automatic = self.read(AUTO)
        manual = self.read(MANUAL)
        for token in (
            "migraciones_verificadas=true",
            "roles_verificados=true",
            "comandos_verificados=true",
            "configuracion_verificada=true",
            "cache_verificada=true",
            "--verified",
        ):
            self.assertNotIn(token, automatic)
        self.assertIn("inputs.migraciones_verificadas", manual)
        self.assertIn("inputs.cache_verificada", manual)


if __name__ == "__main__":
    unittest.main()
