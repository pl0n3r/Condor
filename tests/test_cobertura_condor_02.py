#!/usr/bin/env python3
"""Condor #1321: ejecuta contratos locales reales sin atribuirles estado live."""
from __future__ import annotations

import re
import runpy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import release_evidence as release
from scripts import release_identity_guard as identity

ROOT = Path(__file__).resolve().parents[1]
POST_TEST = ROOT / "tests/test_post_deploy.py"
A = ("AGENTES-06-03", "AGENTES-06-06", "AGENTES-07-01",
     "AGENTES-07-02", "AGENTES-07-04", "AGENTES-07-05",
     "AGENTES-07-06")
B = {
    "AGENTES-05-06": "Auditoría real npm/Composer requiere advisories y CI.",
    "AGENTES-06-01": "Versión humana requiere observar cada deploy en Hostinger.",
    "AGENTES-07-03": "Backup previo real exige recibo y migración reales.",
    "AGENTES-08-03": "Carreras V3 multirrepositorio requieren coordinador auténtico.",
}
D = {"AGENTES-06-02": "Guard SemVer admite saltos positivos de patch."}
ALL = set(A) | set(B) | set(D)


def synthetic_migration(sql: str) -> str:
    return ("<?php\nclass MigrationFixture {\n"
            "public function up(Schema $schema): void {\n"
            "    $this->addSql('" + sql.replace("'", "\\'") + "');\n"
            "}\npublic function down(Schema $schema): void {}\n}\n")


class CoberturaCondor02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.post_stage = runpy.run_path(str(POST_TEST))["PostDeployStageTest"]

    def post(self, **options):
        fixture = self.post_stage("runTest")
        self.addCleanup(fixture.doCleanups)
        return fixture.run_script("construction", **options)

    def test_contrato_principal(self):
        """AC-01: probar código real, no contar marcadores de cobertura."""
        release.validate_covered_releases({
            "version": "0.1.196", "sha": "b" * 40,
            "covered_releases": [{"version": "0.1.195", "sha": "a" * 40}],
        })
        self.assertEqual((0, 1, 196), release.version_key("0.1.196"))
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "config"
            config.mkdir()
            (config / "version.php").write_text(
                "<?php\nreturn ['version' => '0.1.196'];\n", encoding="utf-8")
            with patch.object(identity, "git", return_value=""):
                self.assertEqual(("v0.1.196", "none"), identity.validate(
                    directory, "test: synthetic (V 0.1.196)", "a" * 40))

        path = "migrations/VersionSynthetic.php"
        proof = release.migration_source_safety(
            [path], [{"path": path, "sha": "a" * 40,
                      "source": synthetic_migration(
                          "CREATE TABLE synthetic_safe (id INT);")}])
        self.assertTrue(proof["safe"], proof)
        self.assertEqual("additive", proof["class"])

        result, calls, root = self.post()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue((root / "migrated").exists())
        self.assertTrue((root / "backup-created").exists())
        steps = calls.splitlines()
        dry = next(i for i, line in enumerate(steps)
                   if "doctrine:migrations:migrate" in line and "--dry-run" in line)
        backup = steps.index("backup")
        migrate = next(i for i, line in enumerate(steps)
                       if "doctrine:migrations:migrate" in line and "--dry-run" not in line)
        cache = next(i for i, line in enumerate(steps) if "cache:clear" in line)
        self.assertTrue(dry < backup < migrate < cache)
        self.assertIn("doctrine:migrations:up-to-date", calls)

        result, calls, root = self.post(auto_migrate="0")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertNotIn("doctrine:migrations:migrate", calls)
        self.assertFalse((root / "backup-created").exists())
        self.assertNotIn("cache:clear", calls)

        result, calls, root = self.post(backup_failure=True)
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("cache:clear", calls)

    def test_falla_cerrado(self):
        """AC-02: datos ambiguos, versión falsa o SQL peligroso fallan cerrado."""
        with self.assertRaises(identity.GuardError):
            identity.sv("not-semver")
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "config"
            config.mkdir()
            (config / "version.php").write_text(
                "<?php\nreturn ['version' => '0.1.196'];\n", encoding="utf-8")
            with self.assertRaises(identity.GuardError):
                identity.validate(directory, "wrong (V 0.1.197)", "a" * 40)

        with self.assertRaises(release.EvidenceError):
            release.validate_covered_releases({
                "version": "0.1.196", "sha": "b" * 40,
                "covered_releases": [
                    {"version": "0.1.195", "sha": "a" * 40},
                    {"version": "0.1.195", "sha": "c" * 40}],
            })

        path = "migrations/VersionSynthetic.php"
        for source in (None, synthetic_migration("DROP TABLE test_synthetic;"),
                       synthetic_migration("UPDATE test_synthetic SET id=2;")):
            with self.subTest(migration=source):
                result = release.migration_source_safety(
                    [path], [{"path": path, "sha": "a" * 40, "source": source}])
                self.assertFalse(result["safe"])
                self.assertEqual("human", result["class"])

        result, calls, root = self.post(
            migration_sql="GRANT ALL ON *.* TO 'fixture'@'%';")
        self.assertEqual(2, result.returncode, result.stderr)
        self.assertIn("fuera del allowlist", result.stderr)
        self.assertFalse((root / "migrated").exists())
        self.assertNotIn("cache:clear", calls)

    def test_gate_validar_presente(self):
        """AC-04: contrato local de agregación; CI real exige HEAD exacto."""
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertEqual(1, ci.count("\n  validar:\n"))
        job = ci.split("\n  validar:\n", 1)[1]
        self.assertIn("name: Validar", job)
        self.assertIn("if: always()", job)
        declared = job.split("\n    if: always()", 1)[0]
        for expected in ("factory-ci", "preflight", "coordinacion", "pruebas-base",
                         "static-analysis", "audit-npm", "audit-composer",
                         "backup-restore", "e2e"):
            with self.subTest(gate=expected):
                self.assertIn(expected, declared)

    def test_sin_datos_reales(self):
        """AC-03: solo fixtures sin red, secretos, cuentas ni producción."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^(?:from|import)\s+(?:requests|httpx|socket)\b")
        self.assertNotRegex(source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertEqual(ALL, set(A) | set(B) | set(D))
        self.assertTrue((ROOT / "scripts/post-deploy.sh").is_file())
        self.assertTrue(POST_TEST.is_file())
        self.assertTrue(all(v for v in (*B.values(), *D.values())))


if __name__ == "__main__":
    unittest.main()
