"""Condor #1550: 26 AGENTES.md rules with real local negatives and explicit limits.

No network, production credentials, live writes, schema migrations, or deploy.
Execute locally: python3 tests/test_verificacion_verify_agent_rules.py -v
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from scripts.ci_change_classifier import classify
from scripts import release_identity_guard as identity

ROWS = [
  {
    "id": "AGENTES-02-06",
    "section": 2,
    "claim": "si producción deja de estar GREEN, priorizar su recuperación antes de trabajo dependiente.",
    "source": "AGENTES.md",
    "token": "confirmar el estado productivo separado del estado de CI",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "Se exige HEALTH degradado comprobado y despacho de recuperación real; no se infiere de la norma escrita."
  },
  {
    "id": "AGENTES-03-04",
    "section": 3,
    "claim": "Colombia / es-CO",
    "source": "AGENTES.md",
    "token": "Mercado / locale",
    "limit": "NAVEGADOR_PENDIENTE",
    "pending": "No hay E2E transversal de vistas, formatos y respuestas HTTP con locale es-CO."
  },
  {
    "id": "AGENTES-03-05",
    "section": 3,
    "claim": "Moneda por defecto | COP",
    "source": "AGENTES.md",
    "token": "Moneda por defecto | COP",
    "limit": "INTEGRACION_PENDIENTE",
    "pending": "La tabla contractual no verifica COP por defecto en todos los flujos monetarios."
  },
  {
    "id": "AGENTES-03-06",
    "section": 3,
    "claim": "PHP 8.5 + Symfony 7.4 LTS",
    "source": "composer.json",
    "token": "symfony/framework-bundle",
    "limit": "OPERACION_PENDIENTE",
    "pending": "composer fija dependencias; falta evidencia del runtime efectivamente desplegado."
  },
  {
    "id": "AGENTES-04-01",
    "section": 4,
    "claim": "servidor como autoridad final de validación y autorización",
    "source": "src/Application/Identity/BranchAuthorization.php",
    "token": "throw new AccessDeniedException",
    "limit": "HTTP_INTEGRAL_PENDIENTE",
    "pending": "El control servidor de sede es un slice, no cubre todos los endpoints y permisos E2E."
  },
  {
    "id": "AGENTES-04-03",
    "section": 4,
    "claim": "relaciones de negocio como datos estructurados",
    "source": "src/Domain/Organization/Entity/Branch.php",
    "token": "ManyToOne(targetEntity: Tenant::class)",
    "limit": "INTEGRACION_PENDIENTE",
    "pending": "Mapeo Doctrine de sede no demuestra todas las relaciones de negocio ni su integración DB."
  },
  {
    "id": "AGENTES-04-06",
    "section": 4,
    "claim": "migraciones explícitas, expand-compatible y seguras ante despliegues parciales",
    "source": "scripts/live-schema-policy.php",
    "token": "'additive'",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Contrato aditivo local; sin prueba de despliegue parcial ni migración productiva autorizada."
  },
  {
    "id": "AGENTES-05-01",
    "section": 5,
    "claim": "política de decisiones/revisiones",
    "source": ".github/workflows/politica.yml",
    "token": "pl0n3r/factory/.github/workflows/politica.yml@v1",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "El reusable remoto requiere su ejecución auténtica y una revisión definitiva."
  },
  {
    "id": "AGENTES-05-02",
    "section": 5,
    "claim": "clasificador selectivo de cambios",
    "source": "scripts/ci_change_classifier.py",
    "token": "def classify",
    "limit": "CONTRATO_LOCAL",
    "pending": "Se contrastan cambios sintéticos; no certifica todas las familias de archivos del CI."
  },
  {
    "id": "AGENTES-05-03",
    "section": 5,
    "claim": "validación documental y gobierno local",
    "source": ".github/workflows/ci.yml",
    "token": "name: Validar gobierno del repositorio",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "Declaración de job no es recibo de ejecución exact-HEAD."
  },
  {
    "id": "AGENTES-05-04",
    "section": 5,
    "claim": "contratos e integración",
    "source": ".github/workflows/ci.yml",
    "token": "name: Pruebas de contrato e integración",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "La presencia del job no acredita todos los contratos y DB de cada PR."
  },
  {
    "id": "AGENTES-05-05",
    "section": 5,
    "claim": "TypeScript/build",
    "source": ".github/workflows/ci.yml",
    "token": "name: Frontend TypeScript / build",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "La ruta declarada no equivale a un build terminal y navegador probado."
  },
  {
    "id": "AGENTES-05-06",
    "section": 5,
    "claim": "auditorías npm/Composer",
    "source": ".github/workflows/ci.yml",
    "token": "name: Auditoría de dependencias Composer",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "Los controles de auditoría requieren resultados terminales auténticos."
  },
  {
    "id": "AGENTES-06-01",
    "section": 6,
    "claim": "cada deploy productivo identificable usa versión humana",
    "source": "scripts/release_identity_guard.py",
    "token": "TITLE_RE=re.compile",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Validación de identidad de candidato no prueba deploy real con versión."
  },
  {
    "id": "AGENTES-06-02",
    "section": 6,
    "claim": "incremento normal pre-1.0: patch",
    "source": "config/version.php",
    "token": "'version' =>",
    "limit": "DOCUMENTAL",
    "pending": "Patch normal es política; no equivale a autorizar ni ejecutar nuevo release."
  },
  {
    "id": "AGENTES-06-03",
    "section": 6,
    "claim": "no reutilizar una versión para dos deploys distintos",
    "source": "scripts/release_identity_guard.py",
    "token": "release identities are single-use",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Guard local no observa dos despliegues reales ni historial externo completo."
  },
  {
    "id": "AGENTES-06-06",
    "section": 6,
    "claim": "una PR deploy-bound contiene su versión objetivo antes de gates finales",
    "source": "scripts/release_identity_guard.py",
    "token": "PR title must end with exact format",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Una PR real exige identidad exacta y gates; regex local no demuestra entrega."
  },
  {
    "id": "AGENTES-07-01",
    "section": 7,
    "claim": "solo migraciones versionadas, aditivas/expand-compatible",
    "source": "scripts/live-schema-policy.php",
    "token": "'additive'",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Clasificación fail-closed no ejecuta ni certifica migraciones reales."
  },
  {
    "id": "AGENTES-07-02",
    "section": 7,
    "claim": "dry-run/allowlist",
    "source": "scripts/live-schema-policy.php",
    "token": "'allowlist_complete'",
    "limit": "OPERACION_PENDIENTE",
    "pending": "El gate sintético no es un dry-run real en Hostinger."
  },
  {
    "id": "AGENTES-07-03",
    "section": 7,
    "claim": "backup exitoso previo",
    "source": "scripts/live-schema-policy.php",
    "token": "'backup_receipt_verified'",
    "limit": "OPERACION_PENDIENTE",
    "pending": "La bandera de fixture no sustituye un recibo verificado de backup."
  },
  {
    "id": "AGENTES-07-04",
    "section": 7,
    "claim": "CONDOR_AUTO_MIGRATE=0",
    "source": "scripts/post-deploy.sh",
    "token": "AUTO_MIGRATE=",
    "limit": "OPERACION_PENDIENTE",
    "pending": "La condición de shell existe; falta demostrar el opt-out en host con identidad."
  },
  {
    "id": "AGENTES-07-05",
    "section": 7,
    "claim": "migrate + recheck antes de tocar caché",
    "source": "scripts/post-deploy.sh",
    "token": "doctrine:migrations:up-to-date",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Orden y resultado de convergencia productiva no acreditados por texto."
  },
  {
    "id": "AGENTES-07-06",
    "section": 7,
    "claim": "si backup o validación fallan, se falla cerrado",
    "source": "scripts/live-schema-policy.php",
    "token": "'eligible_after_owner_gate'",
    "limit": "OPERACION_PENDIENTE",
    "pending": "Negativos locales no acreditan abort de pipeline productivo real."
  },
  {
    "id": "AGENTES-08-03",
    "section": 8,
    "claim": "La capacidad se decide por",
    "source": "AGENTES.md",
    "token": "claims de archivo disjuntos",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "Reserva V3 y colisiones reales dependen del coordinador GitHub autenticado."
  },
  {
    "id": "AGENTES-08-04",
    "section": 8,
    "claim": "1200 solicitudes",
    "source": "AGENTES.md",
    "token": "límite menor",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "El umbral escrito no incluye medición real del cupo de API ni despacho."
  },
  {
    "id": "AGENTES-08-05",
    "section": 8,
    "claim": "La telemetría CI se muestrea una vez por hora",
    "source": ".github/workflows/ci-throughput-telemetry.yml",
    "token": "cron: '37 * * * *'",
    "limit": "EXTERNO_PENDIENTE",
    "pending": "Cron declarado no prueba una sola muestra real, frecuencia ni artifacts."
  }
]


DOC_PATH = ROOT / "docs/verificacion/verify-agent-rules.md"
AGENTS_PATH = ROOT / "AGENTES.md"
SCOPED_CLASSES = {
    "CONTRATO_LOCAL", "DOCUMENTAL", "NAVEGADOR_PENDIENTE",
    "HTTP_INTEGRAL_PENDIENTE", "INTEGRACION_PENDIENTE",
    "OPERACION_PENDIENTE", "EXTERNO_PENDIENTE",
}


def section(document: str, number: int) -> str:
    header = re.compile(r"(?m)^## " + str(number) + r"\. [^\n]+$")
    matches = list(header.finditer(document))
    if len(matches) != 1:
        raise AssertionError(f"AGENTES.md: section {number} missing or ambiguous")
    return document[matches[0].end():].split("\n## ", 1)[0]


def witness_matches(source: str, token: str) -> bool:
    return bool(token) and token in source


def classify_witness(document: str, record: dict) -> bool:
    return document.count(record["claim"]) == 1


class AgentRulesVerificationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.agent_text = AGENTS_PATH.read_text(encoding="utf-8")

    def test_26_exact_rows_and_section_anchors(self) -> None:
        self.assertEqual(len(ROWS), 26)
        self.assertEqual(len({r["id"] for r in ROWS}), len(ROWS))
        for record in ROWS:
            with self.subTest(row=record["id"]):
                self.assertRegex(record["id"], r"^AGENTES-\d{2}-\d{2}$")
                self.assertIn(record["limit"], SCOPED_CLASSES)
                body = section(self.agent_text, record["section"])
                self.assertTrue(classify_witness(body, record),
                                f"Changed/duplicate rule: {record['id']}")
                self.assertNotEqual(record["source"], "docs/matriz-cobertura.md")
                source_file = ROOT / record["source"]
                self.assertTrue(source_file.is_file(), record["source"])
                self.assertTrue(witness_matches(
                    source_file.read_text(encoding="utf-8"), record["token"]),
                    f"Missing code/workflow witness: {record['id']}")

    def test_negative_mutations_reject_removed_rule_or_missing_witness(self) -> None:
        for record in ROWS:
            with self.subTest(row=record["id"]):
                body = section(self.agent_text, record["section"])
                changed = body.replace(record["claim"], "__RULE_REMOVED__", 1)
                self.assertFalse(classify_witness(changed, record))
                source = (ROOT / record["source"]).read_text(encoding="utf-8")
                self.assertTrue(witness_matches(source, record["token"]))
                self.assertFalse(witness_matches(
                    source.replace(record["token"], "__WITNESS_REMOVED__"), 
                    "__ABSENT__"))

    def test_actual_change_classifier_positive_and_negative(self) -> None:
        doc = classify(["README.md"], "pull_request")
        self.assertEqual(doc.modo, "rapido")
        self.assertTrue(doc.categoria_documentacion)
        self.assertFalse(doc.backend)
        self.assertFalse(doc.validacion_completa)
        php = classify(["src/Http/Controller/AdminController.php"], "pull_request")
        self.assertTrue(php.categoria_backend)
        self.assertTrue(php.validacion_completa)
        self.assertTrue(php.backend)
        migration = classify(["migrations/Version20260921010000.php"], "pull_request")
        self.assertTrue(migration.categoria_migraciones)
        self.assertTrue(migration.validacion_completa)
        js = classify(["frontend/admin/AdminApp.tsx"], "pull_request")
        self.assertTrue(js.categoria_frontend)
        self.assertTrue(js.frontend)

    def test_real_version_guard_negative_cases(self) -> None:
        php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        versions = list(identity.VERSION_RE.finditer(php))
        self.assertEqual(len(versions), 1)
        current = versions[0].group("version")
        self.assertEqual(len(current.split(".")), 3)
        self.assertEqual(identity.sv(current), tuple(map(int, current.split("."))))
        self.assertIsNotNone(identity.TITLE_RE.search("test: claim (V " + current + ")"))
        for title in ("test: claim (v " + current + ")",
                      "test: claim (V 9999.9999)", "test: claim"):
            self.assertIsNone(identity.TITLE_RE.search(title))
        for invalid in ("01.2.3", "1.02.3", "1.2.3.4", "next"):
            with self.subTest(invalid=invalid):
                with self.assertRaises(identity.GuardError):
                    identity.sv(invalid)

    def test_php_policy_fail_closed_with_synthetic_receipts(self) -> None:
        if shutil.which("php") is None:
            self.skipTest("PHP CLI unavailable; static witnesses still checked")
        cmd = ["php", str(ROOT / "scripts/live-schema-policy.php")]
        payload = {
            "classification": "additive",
            "dry_run_valid": True,
            "allowlist_complete": True,
            "backup_receipt_verified": True,
            "post_checks": {"service_readiness": True, "schema": True, "smoke": True},
        }

        def run(inputs: dict) -> dict:
            executed = subprocess.run(
                cmd, cwd=ROOT, input=json.dumps(inputs), text=True,
                capture_output=True, check=True, timeout=8,
            )
            return json.loads(executed.stdout)

        approved = run(payload)
        self.assertTrue(approved["eligible_after_owner_gate"])
        self.assertFalse(approved["auto_execute"])
        self.assertEqual(approved["authority"], "owner_gate_required")
        for change in (
            {"classification": "destructive"},
            {"classification": "unknown"},
            {"allowlist_complete": False},
            {"dry_run_valid": False},
            {"backup_receipt_verified": False},
            {"post_checks": {"service_readiness": True, "schema": False,
                             "smoke": True}},
            {"unrecognized_gate": True},
        ):
            with self.subTest(change=change):
                bad = dict(payload)
                bad.update(change)
                self.assertFalse(run(bad)["eligible_after_owner_gate"])
                self.assertFalse(run(bad)["auto_execute"])

    def test_ci_and_deploy_guards_not_runtime_proof(self) -> None:
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        self.assertEqual(composer["require"]["php"], "^8.5")
        self.assertEqual(composer["require"]["symfony/framework-bundle"], "7.4.*")
        auth = (ROOT / "src/Application/Identity/BranchAuthorization.php").read_text(
            encoding="utf-8")
        self.assertIn("$branch->tenant()->id() !== $tenant->id()", auth)
        self.assertIn("throw new AccessDeniedException", auth)
        branch = (ROOT / "src/Domain/Organization/Entity/Branch.php").read_text(
            encoding="utf-8")
        self.assertIn("ManyToOne(targetEntity: Tenant::class)", branch)
        self.assertIn("JoinColumn(name: 'tenant_id'", branch)
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        for task in ("name: Validar gobierno del repositorio",
                     "name: Pruebas de contrato e integración",
                     "name: Frontend TypeScript / build",
                     "name: Auditoría de dependencias npm",
                     "name: Auditoría de dependencias Composer",
                     "name: Validar"):
            self.assertIn(task, ci)
        deploy = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")
        for token in ("CONDOR_AUTO_MIGRATE", "--dry-run",
                      "doctrine:migrations:up-to-date", "backup"):
            self.assertIn(token, deploy)
        telemetry = (ROOT / ".github/workflows/ci-throughput-telemetry.yml").read_text(
            encoding="utf-8")
        self.assertIn("cron: '37 * * * *'", telemetry)
        self.assertEqual(telemetry.count("cron: '37 * * * *'"), 1)

    def test_report_26_rows_exact_and_conservative(self) -> None:
        document = DOC_PATH.read_text(encoding="utf-8")
        body = document.split("## Filas auditadas\n", 1)[1].split("\n## ", 1)[0]
        lines = [line for line in body.splitlines() if line.startswith("| AGENTES-")]
        self.assertEqual(len(lines), 26)
        found = {}
        for line in lines:
            cells = [part.strip() for part in line.strip().strip("|").split("|")]
            self.assertEqual(len(cells), 6)
            self.assertNotIn(cells[0], found)
            found[cells[0]] = cells
        self.assertEqual(set(found), {row["id"] for row in ROWS})
        for record in ROWS:
            with self.subTest(row=record["id"]):
                self.assertEqual(found[record["id"]], [
                    record["id"], "NO_VERIFICABLE", record["source"],
                    "tests/test_verificacion_verify_agent_rules.py",
                    record["limit"], record["pending"],
                ])
        self.assertNotIn("| CUBIERTO |", body)
        self.assertIn("No equivale a producción GREEN", document)
        self.assertIn("no está integrada por nombre en los pasos", document)


if __name__ == "__main__":
    unittest.main()
