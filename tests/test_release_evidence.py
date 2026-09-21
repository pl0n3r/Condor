"""Pruebas del manifiesto y consolidación de evidencia de release."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "release_evidence.py"
spec = importlib.util.spec_from_file_location("release_evidence", SCRIPT)
assert spec is not None
assert spec.loader is not None
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

SHA = "a" * 40
VERSION = "0.1.12"


class ReleaseEvidenceTests(unittest.TestCase):
    def version_file(self, root: str, version: str = VERSION) -> Path:
        path = Path(root) / "version.php"
        path.write_text(
            "<?php\nreturn [\n    'version' => '" + version + "',\n];\n",
            encoding="utf-8",
        )
        return path

    def manifest(self, root: str, paths: list[str]) -> dict:
        return module.build_manifest(
            version_file=self.version_file(root),
            sha=SHA,
            changed_paths=paths,
            expected_version=VERSION,
        )

    def observation(self, *, state: str = "VALIDATED_IN_PRODUCTION") -> dict:
        checks = {
            check_id: {
                "ok": True,
                "clase": "ok",
                "detalle": "Comprobación correcta.",
                "intento": 1,
            }
            for check_id in module.PUBLIC_CHECKS
        }
        return {
            "estado": state,
            "version_esperada": VERSION,
            "sha_esperado": SHA,
            "comprobaciones": checks,
        }

    def test_manifest_uses_canonical_version_and_exact_sha(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])

        self.assertEqual(manifest["schema"], module.SCHEMA)
        self.assertEqual(manifest["version"], VERSION)
        self.assertEqual(manifest["sha"], SHA)
        self.assertEqual(manifest["version_source"], "config/version.php")
        self.assertFalse(manifest["transition"]["required"])
        self.assertEqual(manifest["public_checks"], list(module.PUBLIC_CHECKS))

    def test_manifest_rejects_requested_version_different_from_source(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            version_file = self.version_file(tmp, "0.1.11")
            with self.assertRaises(module.EvidenceError):
                module.build_manifest(
                    version_file=version_file,
                    sha=SHA,
                    changed_paths=["README.md"],
                    expected_version=VERSION,
                )

    def test_migration_requires_migration_and_cache_checks(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(
                tmp,
                ["migrations/Version20260921010000.php"],
            )

        checks = {
            item["id"]: item["required"]
            for item in manifest["transition"]["checks"]
        }
        self.assertTrue(manifest["transition"]["required"])
        self.assertTrue(checks["migraciones"])
        self.assertTrue(checks["cache"])
        self.assertFalse(checks["comandos"])

    def test_identity_change_marks_roles_configuration_and_cache(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(
                tmp,
                ["config/packages/security.yaml"],
            )

        checks = {
            item["id"]: item["required"]
            for item in manifest["transition"]["checks"]
        }
        self.assertTrue(checks["roles"])
        self.assertTrue(checks["configuracion"])
        self.assertTrue(checks["cache"])

    def test_transition_pending_cannot_validate_production(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(
                tmp,
                ["migrations/Version20260921010000.php"],
            )

        evidence = module.finalize(
            manifest,
            self.observation(),
            ["migraciones"],
        )
        self.assertEqual(evidence["estado"], "DEPLOY_OBSERVED")
        self.assertEqual(evidence["transition"]["pending"], ["cache"])

    def test_all_required_transition_checks_allow_validation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(
                tmp,
                ["migrations/Version20260921010000.php"],
            )

        evidence = module.finalize(
            manifest,
            self.observation(),
            ["migraciones", "cache"],
        )
        self.assertEqual(evidence["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertEqual(evidence["transition"]["pending"], [])

    def test_manifest_rejects_non_boolean_transition_required(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        manifest["transition"]["required"] = "false"

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, self.observation(), [])

    def test_manifest_rejects_non_boolean_check_required(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        manifest["transition"]["checks"][0]["required"] = 1

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, self.observation(), [])

    def test_manifest_rejects_incoherent_transition_flag(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        manifest["transition"]["required"] = True

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, self.observation(), [])

    def test_unknown_observation_state_never_promotes_release(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        observation = self.observation(state="UNKNOWN")

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, observation, [])

    def test_deploy_observed_state_is_not_promoted_by_complete_checks(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])

        evidence = module.finalize(
            manifest,
            self.observation(state="DEPLOY_OBSERVED"),
            [],
        )
        self.assertEqual(evidence["estado"], "DEPLOY_OBSERVED")

    def test_missing_public_check_keeps_deploy_only_observed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        observation = self.observation()
        del observation["comprobaciones"]["js_admin"]

        evidence = module.finalize(manifest, observation, [])
        self.assertEqual(evidence["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(evidence["public_checks"]["js_admin"]["ok"])

    def test_wrong_identity_never_observes_expected_deploy(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        observation = self.observation()
        observation["sha_esperado"] = "b" * 40

        evidence = module.finalize(manifest, observation, [])
        self.assertEqual(evidence["estado"], "NO_OBSERVADO")
        self.assertFalse(evidence["identity_match"])

    def test_markdown_contains_machine_readable_evidence(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        evidence = module.finalize(manifest, self.observation(), [])

        output = module.markdown(evidence)
        self.assertIn("Evidencia JSON", output)
        embedded = json.dumps(evidence, ensure_ascii=False, sort_keys=True)
        self.assertIn(embedded, output)
        self.assertNotIn("password", output.lower())


if __name__ == "__main__":
    unittest.main()
