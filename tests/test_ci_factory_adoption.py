#!/usr/bin/env python3
"""Regresiones de adopción del núcleo Factory v1 en Condor."""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from scripts.ci_self_audit import job_blocks, job_value


ROOT = Path(__file__).resolve().parents[1]


class FactoryAdoptionTests(unittest.TestCase):
    def read(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_decisions_include_owner_contract(self) -> None:
        payload = json.loads(self.read("decisiones.yml"))
        self.assertEqual(payload["version"], 1)
        self.assertEqual(payload["review_round_limit"], 3)
        decisions = {item["id"]: item for item in payload["decisions"]}
        self.assertEqual(set(decisions), {"D-054", "D-055", "D-056", "D-057", "D-058", "D-059"})
        self.assertTrue(all(item["status"] == "active" for item in decisions.values()))
        self.assertIn("backup previo", decisions["D-054"]["text"])
        self.assertIn("datos.yml", decisions["D-059"]["text"])
        self.assertIn("puerta legal", decisions["D-059"]["text"])
        self.assertIn("[COMPLETAR POR EL DUEÑO]", decisions["D-059"]["text"])

    def test_agent_manual_consumes_factory_core_and_keeps_condor_layer(self) -> None:
        manual = self.read("AGENTES.md")
        self.assertIn("factory/blob/main/PLAN-AGENTES.md", manual)
        self.assertIn("factory/blob/v1/agentes/NUCLEO.md", manual)
        self.assertIn("PHP 8.5 + Symfony 7.4 LTS", manual)
        self.assertIn("Hostinger shared hosting", manual)
        self.assertIn("CONDOR_AUTO_MIGRATE=0", manual)
        self.assertIn("Issue #1", manual)

    def test_common_workflows_delegate_to_factory_v1(self) -> None:
        expected = {
            ".github/workflows/sincronizar-gobierno.yml":
                "pl0n3r/factory/.github/workflows/etiquetas.yml@v1",
            ".github/workflows/politica.yml":
                "pl0n3r/factory/.github/workflows/politica.yml@v1",
            ".github/workflows/tag-release.yml":
                "pl0n3r/factory/.github/workflows/release.yml@v1",
        }
        for path, reference in expected.items():
            with self.subTest(path=path):
                workflow = self.read(path)
                self.assertIn(reference, workflow)
                self.assertNotIn("@main", workflow)
                self.assertNotIn("secrets: inherit", workflow)

    def test_local_coordination_copy_is_not_executed_by_active_workflows(self) -> None:
        ci = self.read(".github/workflows/ci.yml")
        coordination = self.read(".github/workflows/coordinacion-trabajo.yml")
        self.assertNotIn("python3 scripts/coordinar_trabajo.py", ci)
        self.assertNotIn("tests/test_coordinar_trabajo.py", ci)
        self.assertNotIn("python3 scripts/coordinar_trabajo.py", coordination)
        self.assertIn(".factory/scripts/coordinar_trabajo.py validar-pr", ci)
        self.assertIn("repository: pl0n3r/factory", coordination)
        self.assertIn("ref: v1", coordination)
        self.assertIn(".factory/scripts/coordinar_trabajo.py", coordination)
        self.assertIn("/migrar-contrato ", coordination)

    def test_coordination_wrapper_routes_v2_commands(self) -> None:
        coordination = self.read(".github/workflows/coordinacion-trabajo.yml")
        for token in (
            "github.event.comment.body == '/adoptar-contrato-huerfana'",
            "startsWith(github.event.comment.body, '/renovar-contrato ')",
            "github.event.comment.body == '/tomar'",
            "startsWith(github.event.comment.body, '/migrar-contrato ')",
        ):
            with self.subTest(token=token):
                self.assertIn(token, coordination)

    def test_coordination_sensitive_jobs_have_checks_write_only(self) -> None:
        coordination = self.read(".github/workflows/coordinacion-trabajo.yml")
        jobs = job_blocks(coordination)
        self.assertIn("checks: write", jobs["comentario"])
        self.assertIn("checks: write", jobs["issue"])
        for name in ("etiqueta", "pr", "sweep"):
            with self.subTest(job=name):
                self.assertNotIn("checks: write", jobs[name])

    def test_coordination_issue_edit_invalidates_contract_evidence(self) -> None:
        coordination = self.read(".github/workflows/coordinacion-trabajo.yml")
        jobs = job_blocks(coordination)
        self.assertIn(
            "types: [labeled, closed, reopened, edited]",
            coordination,
        )
        self.assertIn("github.event.action == 'edited'", jobs["issue"])
        self.assertIn("checks: write", jobs["issue"])
        self.assertIn("issue_comment:\n    types: [created]", coordination)

    def test_coordination_wrapper_stays_on_factory_v1(self) -> None:
        coordination = self.read(".github/workflows/coordinacion-trabajo.yml")
        self.assertIn("repository: pl0n3r/factory", coordination)
        self.assertIn("ref: v1", coordination)
        self.assertNotIn("@main", coordination)
        self.assertNotIn("python3 scripts/coordinar_trabajo.py", coordination)
        self.assertIn(".factory/scripts/coordinar_trabajo.py", coordination)

    def test_coordination_wrapper_candidate_version(self) -> None:
        version = self.read("config/version.php")
        readme = self.read("README.md")
        self.assertIn("'version' => '0.1.50'", version)
        self.assertIn("V 0.1.50", readme)
        self.assertIn("Issue #183", readme)

    def test_condor_ci_requires_factory_without_dropping_specific_gates(self) -> None:
        ci = self.read(".github/workflows/ci.yml")
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/ci.yml@v1",
            ci,
        )
        validar = job_blocks(ci)["validar"]
        needs = job_value(validar, "needs") or ""
        for gate in ("factory-ci", "backend-php", "backup-restore", "e2e"):
            with self.subTest(gate=gate):
                self.assertIn(gate, needs)

        for token in (
            "stack: php",
            "domain: https://www.condorapp.com.co",
            "version_source: config/version.php",
            "php_version: '8.5'",
            "node_enabled: false",
            "kit_ref: v1",
            "backend-php",
            "backup-restore",
            "e2e",
            '[[ "$FACTORY_CI" == "success" ]]',
            '$BASE_SHA:decisiones.yml',
        ):
            with self.subTest(token=token):
                self.assertIn(token, ci)

    def test_release_uses_condor_version_source(self) -> None:
        release = self.read(".github/workflows/tag-release.yml")
        self.assertIn("version_source: config/version.php", release)
        self.assertIn("version_format: auto", release)
        self.assertNotIn("gh release create", release)


if __name__ == "__main__":
    unittest.main()
