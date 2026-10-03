"""Pruebas del manifiesto y consolidación de evidencia de release."""

from __future__ import annotations

import importlib.util
import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
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

    def manifest_for(
        self,
        root: str,
        *,
        version: str,
        sha: str,
        paths: list[str],
    ) -> dict:
        return module.build_manifest(
            version_file=self.version_file(root, version),
            sha=sha,
            changed_paths=paths,
            expected_version=version,
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

    def test_ac07_version_identity_manifest_is_coherent_without_transition(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["config/version.php"])

        self.assertFalse(manifest["transition"]["required"])
        self.assertTrue(all(
            item["required"] is False
            for item in manifest["transition"]["checks"]
        ))
        module.validate_manifest(manifest)

        with tempfile.TemporaryDirectory() as tmp:
            runtime = self.manifest(
                tmp,
                ["config/version.php", "config/packages/framework.yaml"],
            )

        checks = {
            item["id"]: item["required"]
            for item in runtime["transition"]["checks"]
        }
        self.assertTrue(runtime["transition"]["required"])
        self.assertTrue(checks["configuracion"])

    def test_storefront_release_requiere_dos_checks_en_manifiesto(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = module.build_manifest(
                version_file=self.version_file(tmp, "0.1.13"),
                sha=SHA,
                changed_paths=["config/version.php"],
                expected_version="0.1.13",
            )
        self.assertFalse(manifest["transition"]["required"])
        self.assertEqual(
            manifest["public_checks"][-2:],
            ["storefront", "slug_desconocido"],
        )

        observation = self.observation()
        observation["version_esperada"] = "0.1.13"
        evidence = module.finalize(manifest, observation, [])
        self.assertEqual(evidence["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(evidence["public_checks"]["storefront"]["ok"])
        self.assertFalse(evidence["public_checks"]["slug_desconocido"]["ok"])

        observation["comprobaciones"].update({
            "storefront": {"ok": True, "clase": "ok", "detalle": "SSR probado."},
            "slug_desconocido": {"ok": True, "clase": "ok", "detalle": "HTTP 404."},
        })
        evidence = module.finalize(manifest, observation, [])
        self.assertEqual(evidence["estado"], "VALIDATED_IN_PRODUCTION")


    def test_v0187_entitlements_manifest_has_no_operational_transition(self) -> None:
        paths = [
            "config/version.php",
            "src/Application/Commercial/EntitlementContext.php",
            "src/Application/Commercial/EntitlementResolver.php",
            "src/Application/Commercial/EntitlementSnapshot.php",
            "src/Domain/Commercial/EntitlementOverride.php",
            "tests/php/Application/Commercial/EntitlementResolverTest.php",
            "tests/php/Domain/Commercial/EntitlementOverrideTest.php",
            "tests/test_entitlements_acceptance.py",
        ]
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, paths)

        self.assertFalse(manifest["transition"]["required"])
        self.assertTrue(
            all(item["required"] is False for item in manifest["transition"]["checks"])
        )
        module.validate_manifest(manifest)

    def test_v0194_control_center_manifest_has_no_operational_transition(self) -> None:
        paths = [
            "config/version.php",
            "frontend/admin/PlatformOwnerApp.tsx",
            "public/build/admin.js",
            "src/Application/Commercial/PlatformCommercialTenantSummary.php",
            "src/Http/Controller/PlatformOwnerContextController.php",
            "tests/php/Http/PlatformOwnerCommercialSubscriptionTest.php",
            "tests/test_saas_control_center_subscription_overview.py",
        ]
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, paths)

        self.assertFalse(manifest["transition"]["required"])
        self.assertTrue(
            all(item["required"] is False for item in manifest["transition"]["checks"])
        )
        module.validate_manifest(manifest)

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

    def test_explicit_manifest_matches_canonical_builder_for_same_version_sha_and_paths(self) -> None:
        paths = ["scripts/release_evidence.py", "config/version.php"]
        with tempfile.TemporaryDirectory() as tmp:
            canonical = module.build_manifest(
                version_file=self.version_file(tmp, VERSION),
                sha=SHA,
                changed_paths=paths,
                expected_version=VERSION,
            )

        explicit = module.build_manifest_explicit(
            version=VERSION,
            sha=SHA,
            changed_paths=paths,
        )

        self.assertEqual(explicit, canonical)

    def test_explicit_manifest_rejects_invalid_identity_and_accumulate_cli_unions_pending_checks(self) -> None:
        with self.assertRaises(module.EvidenceError):
            module.build_manifest_explicit(
                version="v0.1.12",
                sha=SHA,
                changed_paths=["README.md"],
            )
        with self.assertRaises(module.EvidenceError):
            module.build_manifest_explicit(
                version=VERSION,
                sha="not-a-sha",
                changed_paths=["README.md"],
            )

        current = module.build_manifest_explicit(
            version=VERSION,
            sha=SHA,
            changed_paths=["README.md"],
        )
        pending = module.build_manifest_explicit(
            version="0.1.11",
            sha="b" * 40,
            changed_paths=["migrations/Version20260921010000.php"],
        )
        envelope = {
            "current_manifest": current,
            "pending_manifests": [pending],
        }

        original_stdin = sys.stdin
        output = io.StringIO()
        try:
            sys.stdin = io.StringIO(json.dumps(envelope))
            with redirect_stdout(output):
                result = module.main(["accumulate"])
        finally:
            sys.stdin = original_stdin

        self.assertEqual(result, 0)
        accumulated = json.loads(output.getvalue())
        required = {
            item["id"]: item["required"]
            for item in accumulated["transition"]["checks"]
        }
        self.assertEqual(
            accumulated["covered_releases"],
            [{"version": "0.1.11", "sha": "b" * 40}],
        )
        self.assertTrue(required["migraciones"])
        self.assertTrue(required["cache"])

    def test_accumulate_pending_manifests_unions_required_transition_checks_and_records_exact_coverage(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            current = self.manifest_for(
                tmp,
                version=VERSION,
                sha=SHA,
                paths=["README.md"],
            )
            pending = self.manifest_for(
                tmp,
                version="0.1.11",
                sha="b" * 40,
                paths=["migrations/Version20260921010000.php"],
            )

        accumulated = module.accumulate_pending_manifests(current, [pending])
        checks = {
            item["id"]: item["required"]
            for item in accumulated["transition"]["checks"]
        }

        self.assertEqual(accumulated["version"], VERSION)
        self.assertEqual(accumulated["sha"], SHA)
        self.assertEqual(
            accumulated["covered_releases"],
            [{"version": "0.1.11", "sha": "b" * 40}],
        )
        self.assertTrue(accumulated["transition"]["required"])
        self.assertTrue(checks["migraciones"])
        self.assertTrue(checks["cache"])
        self.assertFalse(checks["roles"])

    def test_accumulate_without_pending_manifests_preserves_current_manifest_semantics(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            current = self.manifest(tmp, ["README.md"])

        accumulated = module.accumulate_pending_manifests(current, [])

        self.assertEqual(accumulated["version"], current["version"])
        self.assertEqual(accumulated["sha"], current["sha"])
        self.assertEqual(accumulated["transition"], current["transition"])
        self.assertEqual(accumulated["covered_releases"], [])
        module.validate_manifest(accumulated)

    def test_accumulate_rejects_conflicting_or_invalid_pending_release_identity(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            current = self.manifest(tmp, ["README.md"])
            conflicting = self.manifest_for(
                tmp,
                version=VERSION,
                sha="b" * 40,
                paths=["README.md"],
            )
            invalid = self.manifest_for(
                tmp,
                version="0.1.11",
                sha="c" * 40,
                paths=["README.md"],
            )
        invalid["schema"] = "invalid"

        with self.subTest("same version different sha"):
            with self.assertRaises(module.EvidenceError):
                module.accumulate_pending_manifests(current, [conflicting])
        with self.subTest("invalid canonical manifest"):
            with self.assertRaises(module.EvidenceError):
                module.accumulate_pending_manifests(current, [invalid])

    def test_finalize_accumulated_manifest_stays_deploy_observed_until_every_required_union_flag_is_verified(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            current = self.manifest(tmp, ["README.md"])
            pending = self.manifest_for(
                tmp,
                version="0.1.11",
                sha="b" * 40,
                paths=["migrations/Version20260921010000.php"],
            )

        accumulated = module.accumulate_pending_manifests(current, [pending])
        partial = module.finalize(
            accumulated,
            self.observation(),
            ["migraciones"],
        )
        complete = module.finalize(
            accumulated,
            self.observation(),
            ["migraciones", "cache"],
        )

        self.assertEqual(partial["estado"], "DEPLOY_OBSERVED")
        self.assertEqual(partial["transition"]["pending"], ["cache"])
        self.assertEqual(complete["estado"], "VALIDATED_IN_PRODUCTION")

    def test_accumulated_manifest_is_secret_free_and_exact_identity_bound(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            current = self.manifest(tmp, ["README.md"])
            pending = self.manifest_for(
                tmp,
                version="0.1.11",
                sha="b" * 40,
                paths=["config/packages/security.yaml"],
            )

        accumulated = module.accumulate_pending_manifests(
            current,
            [pending, pending],
        )
        serialized = json.dumps(accumulated, sort_keys=True).lower()

        self.assertEqual(accumulated["version"], VERSION)
        self.assertEqual(accumulated["sha"], SHA)
        self.assertEqual(
            accumulated["covered_releases"],
            [{"version": "0.1.11", "sha": "b" * 40}],
        )
        self.assertEqual(
            set(accumulated["covered_releases"][0]),
            {"version", "sha"},
        )
        self.assertNotIn("password", serialized)
        self.assertNotIn("token", serialized)
        self.assertNotIn("secret", serialized)

    def test_manifest_rejects_non_boolean_transition_required(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        manifest["transition"]["required"] = "false"
        observation = self.observation()

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, observation, [])

    def test_manifest_rejects_non_boolean_check_required(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        manifest["transition"]["checks"][0]["required"] = 1
        observation = self.observation()

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, observation, [])

    def test_manifest_rejects_incoherent_transition_flag(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = self.manifest(tmp, ["README.md"])
        manifest["transition"]["required"] = True
        observation = self.observation()

        with self.assertRaises(module.EvidenceError):
            module.finalize(manifest, observation, [])

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
