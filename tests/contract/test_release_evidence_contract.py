"""Contratos estables de la evidencia de release de Condor."""

from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "release_evidence.py"
spec = importlib.util.spec_from_file_location("release_evidence_contract", SCRIPT)
assert spec is not None
assert spec.loader is not None
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class ReleaseEvidenceContractTests(unittest.TestCase):
    def test_manifest_contract_exposes_identity_smoke_and_transition(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            version_file = Path(tmp) / "version.php"
            version_file.write_text(
                "<?php\nreturn ['version' => '0.1.12'];\n",
                encoding="utf-8",
            )
            manifest = module.build_manifest(
                version_file=version_file,
                sha="a" * 40,
                changed_paths=["migrations/Version20260921010000.php"],
                expected_version="0.1.12",
            )

        self.assertEqual(manifest["schema"], "condor.release-evidence.v1")
        self.assertEqual(
            manifest["public_checks"],
            [
                "health",
                "home",
                "admin_login",
                "css_publico",
                "css_admin",
                "js_admin",
            ],
        )
        self.assertEqual(
            [item["id"] for item in manifest["transition"]["checks"]],
            ["migraciones", "roles", "comandos", "configuracion", "cache"],
        )

    def test_release_workflow_requires_granular_transition_evidence(self) -> None:
        workflow = (
            ROOT / ".github" / "workflows" / "observar-release.yml"
        ).read_text(encoding="utf-8")

        for token in (
            "migraciones_verificadas:",
            "roles_verificados:",
            "comandos_verificados:",
            "configuracion_verificada:",
            "cache_verificada:",
            "scripts/release_evidence.py manifest",
            "scripts/release_evidence.py",
            "finalize",
            "--json-out /tmp/release-evidence.json",
        ):
            self.assertIn(token, workflow)
        self.assertNotIn("transicion_verificada:", workflow)


if __name__ == "__main__":
    unittest.main()
