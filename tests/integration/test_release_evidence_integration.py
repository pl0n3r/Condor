"""Integración del CLI de evidencia de release sin acceso a producción."""

from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "release_evidence.py"
SHA = "a" * 40
VERSION_PATTERN = re.compile(
    r"['\"]version['\"]\s*=>\s*['\"](\d+\.\d+\.\d+)['\"]",
    re.ASCII,
)


class ReleaseEvidenceIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        source = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        match = VERSION_PATTERN.search(source)
        assert match is not None
        cls.version = match.group(1)

    def run_cli(
        self,
        *args: str,
        input_text: str = "",
    ) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["python3", str(SCRIPT), *args],
            cwd=ROOT,
            input=input_text,
            text=True,
            capture_output=True,
            check=False,
        )

    def observation(self) -> dict:
        return {
            "estado": "VALIDATED_IN_PRODUCTION",
            "version_esperada": self.version,
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
                ) + (
                    ("storefront", "slug_desconocido")
                    if tuple(map(int, self.version.split("."))) >= (0, 1, 13)
                    else ()
                )
            },
        }

    def test_manifest_and_finalize_round_trip(self) -> None:
        manifest = self.run_cli(
            "manifest",
            "--sha",
            SHA,
            "--expected-version",
            self.version,
            input_text="migrations/Version20260921010000.php\n",
        )
        self.assertEqual(manifest.returncode, 0, manifest.stderr)
        manifest_payload = json.loads(manifest.stdout)
        envelope = json.dumps(
            {
                "manifest": manifest_payload,
                "observation": self.observation(),
            }
        )

        pending = self.run_cli(
            "finalize",
            "--verified",
            "migraciones",
            input_text=envelope,
        )
        self.assertEqual(pending.returncode, 1)
        self.assertEqual(json.loads(pending.stdout)["estado"], "DEPLOY_OBSERVED")

        validated = self.run_cli(
            "finalize",
            "--verified",
            "migraciones",
            "--verified",
            "cache",
            input_text=envelope,
        )
        self.assertEqual(validated.returncode, 0, validated.stderr)
        self.assertEqual(
            json.loads(validated.stdout)["estado"],
            "VALIDATED_IN_PRODUCTION",
        )

    def test_manifest_rejects_version_mismatch_before_observation(self) -> None:
        result = self.run_cli(
            "manifest",
            "--sha",
            SHA,
            "--expected-version",
            "9.9.9",
            input_text="README.md\n",
        )

        self.assertEqual(result.returncode, 2)
        self.assertIn("no coincide", result.stderr)


if __name__ == "__main__":
    unittest.main()
