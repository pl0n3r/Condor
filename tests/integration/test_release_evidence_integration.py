"""Integración del CLI de evidencia de release sin acceso a producción."""

from __future__ import annotations

import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "release_evidence.py"
SHA = "a" * 40
VERSION = "0.1.12"


class ReleaseEvidenceIntegrationTests(unittest.TestCase):
    def run_cli(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["python3", str(SCRIPT), *args],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )

    def observation(self) -> dict:
        return {
            "estado": "VALIDATED_IN_PRODUCTION",
            "version_esperada": VERSION,
            "sha_esperado": SHA,
            "comprobaciones": {
                check_id: {
                    "ok": True,
                    "clase": "ok",
                    "detalle": "Comprobación correcta.",
                    "intento": 1,
                }
                for check_id in (
                    "health",
                    "home",
                    "admin_login",
                    "css_publico",
                    "css_admin",
                    "js_admin",
                )
            },
        }

    def test_manifest_and_finalize_round_trip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            version_file = root / "version.php"
            changes = root / "changes.txt"
            manifest_file = root / "manifest.json"
            observation_file = root / "observation.json"
            evidence_file = root / "evidence.json"

            version_file.write_text(
                "<?php\nreturn ['version' => '0.1.12'];\n",
                encoding="utf-8",
            )
            changes.write_text(
                "migrations/Version20260921010000.php\n",
                encoding="utf-8",
            )

            manifest = self.run_cli(
                "manifest",
                "--sha",
                SHA,
                "--expected-version",
                VERSION,
                "--version-file",
                str(version_file),
                "--changes",
                str(changes),
            )
            self.assertEqual(manifest.returncode, 0, manifest.stderr)
            manifest_file.write_text(manifest.stdout, encoding="utf-8")
            observation_file.write_text(
                json.dumps(self.observation()),
                encoding="utf-8",
            )

            pending = self.run_cli(
                "finalize",
                "--manifest",
                str(manifest_file),
                "--observation",
                str(observation_file),
                "--verified",
                "migraciones",
            )
            self.assertEqual(pending.returncode, 1)
            self.assertEqual(json.loads(pending.stdout)["estado"], "DEPLOY_OBSERVED")

            validated = self.run_cli(
                "finalize",
                "--manifest",
                str(manifest_file),
                "--observation",
                str(observation_file),
                "--verified",
                "migraciones",
                "--verified",
                "cache",
                "--json-out",
                str(evidence_file),
            )
            self.assertEqual(validated.returncode, 0, validated.stderr)
            evidence = json.loads(validated.stdout)
            self.assertEqual(evidence["estado"], "VALIDATED_IN_PRODUCTION")
            self.assertEqual(
                json.loads(evidence_file.read_text(encoding="utf-8")),
                evidence,
            )

    def test_manifest_rejects_version_mismatch_before_observation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            version_file = root / "version.php"
            changes = root / "changes.txt"
            version_file.write_text(
                "<?php\nreturn ['version' => '0.1.11'];\n",
                encoding="utf-8",
            )
            changes.write_text("README.md\n", encoding="utf-8")

            result = self.run_cli(
                "manifest",
                "--sha",
                SHA,
                "--expected-version",
                VERSION,
                "--version-file",
                str(version_file),
                "--changes",
                str(changes),
            )

        self.assertEqual(result.returncode, 2)
        self.assertIn("no coincide", result.stderr)


if __name__ == "__main__":
    unittest.main()
