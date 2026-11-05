"""Condor #1551: mapa README con límites explícitos, sin inferir salud externa."""
from __future__ import annotations

import importlib.util
import io
import json
import re
import sys
import unittest
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[1]
ROWS = (
    ("README-01-01", "Operational Cockpit", "Las insignias se actualizan", "README.md", "tests/test_readme_contract_adoption.py", "EXTERNO_PENDIENTE"),
    ("README-02-01", "Work Queue", "- **NOW:**", "README.md", "tests/test_readme_contract_adoption.py", "EXTERNO_PENDIENTE"),
    ("README-02-02", "Work Queue", "- **NEXT:**", "README.md", "tests/test_readme_contract_adoption.py", "EXTERNO_PENDIENTE"),
    ("README-02-03", "Work Queue", "- **LATER:**", "docs/modulos-operativos.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-02-04", "Work Queue", "- **BLOCKED:**", "README.md", "tests/test_readme_contract_adoption.py", "EXTERNO_PENDIENTE"),
    ("README-03-03", "Qué hace el producto", "- **Pedidos y ventas:**", "src/Http/Controller/OrderController.php", "tests/test_cobertura_condor_28.py", "HTTP_INTEGRAL_PENDIENTE"),
    ("README-03-04", "Qué hace el producto", "- **Clientes y categorías:**", "src/Http/Controller/CustomerController.php", "tests/test_cobertura_condor_28.py", "HTTP_INTEGRAL_PENDIENTE"),
    ("README-03-05", "Qué hace el producto", "- **Inventario por sede:**", "src/Http/Controller/InventoryController.php", "tests/test_cobertura_condor_28.py", "HTTP_INTEGRAL_PENDIENTE"),
    ("README-03-06", "Qué hace el producto", "- **Compras y proveedores:**", "src/Http/Controller/PurchasingController.php", "tests/test_cobertura_condor_28.py", "HTTP_INTEGRAL_PENDIENTE"),
    ("README-05-01", "Stack e infraestructura", "- **Backend y datos:**", "composer.json", "tests/test_symfony_lts_dependency_guard.py", "INTEGRACION_PENDIENTE"),
    ("README-05-02", "Stack e infraestructura", "- **Admin:**", "package.json", "tests/test_ci_frontend_claims.py", "NAVEGADOR_PENDIENTE"),
    ("README-05-03", "Stack e infraestructura", "- **Público:**", "templates/tenant/index.html.twig", "tests/test_tenant_public_http_contract.py", "NAVEGADOR_PENDIENTE"),
    ("README-05-04", "Stack e infraestructura", "- **Hosting:**", "AGENTES.md", "tests/test_operational_release_gate.py", "EXTERNO_PENDIENTE"),
    ("README-05-05", "Stack e infraestructura", "- **CI / pruebas:**", ".github/workflows/ci.yml", "tests/test_rector_gate.py", "EXTERNO_PENDIENTE"),
    ("README-07-01", "Calidad y seguridad", "- [Factory `PLAN-AGENTES.md`]", "AGENTES.md", "tests/test_ci_self_audit.py", "DOCUMENTAL"),
    ("README-07-02", "Calidad y seguridad", "- Las APIs hacen cumplir", "src/Application/Identity/BranchAuthorization.php", "tests/test_new_modules_tenant_isolation.py", "HTTP_INTEGRAL_PENDIENTE"),
    ("README-07-03", "Calidad y seguridad", "- Migraciones de esquema", "scripts/live-schema-policy.php", "tests/test_live_migration_policy.py", "OPERACION_PENDIENTE"),
    ("README-07-04", "Calidad y seguridad", "- `Validar`, seguridad", ".github/workflows/ci.yml", "tests/test_operational_release_gate.py", "EXTERNO_PENDIENTE"),
    ("README-08-01", "Roadmap y fuentes de verdad", "- **Plan y prioridad:**", "README.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-08-02", "Roadmap y fuentes de verdad", "- **Producto:**", "ESPECIFICACIONES.md", "tests/test_docs_modulos_operativos.py", "DOCUMENTAL"),
    ("README-08-03", "Roadmap y fuentes de verdad", "- **Operación:**", "decisiones.yml", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-08-04", "Roadmap y fuentes de verdad", "- **Evidencia:**", ".github/workflows/ci.yml", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-09-01", "Desarrollo local", "Instala dependencias PHP/Node", "composer.lock", "tests/test_readme_contract_adoption.py", "CONTRATO_LOCAL"),
    ("README-09-02", "Desarrollo local", "Antes de entregar cambios PHP/tooling", "phpstan.neon.dist", "tests/test_rector_gate.py", "CONTRATO_LOCAL"),
    ("README-10-01", "Mapa de la fábrica", "- **Factory:**", "README.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-10-02", "Mapa de la fábrica", "- **ControlBot:**", "README.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-10-03", "Mapa de la fábrica", "- **FactoryRunner:**", "README.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-10-04", "Mapa de la fábrica", "- **AutoFactory:**", "README.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
    ("README-10-05", "Mapa de la fábrica", "- **Condor / GrindFlow / BRVTAL:**", "README.md", "tests/test_readme_contract_adoption.py", "DOCUMENTAL"),
)
LIMITS = {
    "EXTERNO_PENDIENTE", "HTTP_INTEGRAL_PENDIENTE",
    "INTEGRACION_PENDIENTE", "NAVEGADOR_PENDIENTE",
    "OPERACION_PENDIENTE", "DOCUMENTAL", "CONTRATO_LOCAL",
}


def section(document: str, title: str) -> str:
    heading = "## " + title + "\n"
    if document.count(heading) != 1:
        raise AssertionError("README: sección ausente/duplicada: " + title)
    return document.split(heading, 1)[1].split("\n## ", 1)[0]


def query_labels(readme: str, lane: str) -> list[str]:
    body = section(readme, "Work Queue")
    rows = [line for line in body.splitlines() if line.startswith("- **" + lane + ":**")]
    if len(rows) != 1:
        raise AssertionError("Work Queue: carril duplicado/ausente: " + lane)
    urls = re.findall(r"\]\((https://github\.com/pl0n3r/Condor/issues\?q=[^)]+)\)", rows[0])
    if not urls:
        raise AssertionError("Work Queue: no hay enlaces dinámicos: " + lane)
    return [parse_qs(urlsplit(url).query).get("q", [""])[0] for url in urls]


class VerificacionReadmeClaimsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.readme = (ROOT / "README.md").read_text(encoding="utf-8")

    def test_29_filas_documentadas_y_vinculadas_a_fuentes(self):
        self.assertEqual(len(ROWS), 29)
        self.assertEqual(len({row[0] for row in ROWS}), 29)
        report = (ROOT / "docs/verificacion/verify-readme-claims.md").read_text(encoding="utf-8")
        table = []
        for line in report.splitlines():
            if not line.startswith("| `README-"):
                continue
            parts = [item.strip() for item in line.strip("|").split("|")]
            self.assertEqual(len(parts), 4)
            self.assertTrue(all(item.startswith("`") and item.endswith("`")
                                for item in parts[:3]))
            table.append((parts[0][1:-1], parts[1][1:-1], parts[2][1:-1], parts[3]))
        self.assertEqual(
            table, [(key, path, test, state)
                    for key, _heading, _anchor, path, test, state in ROWS],
        )
        for key, heading, anchor, source, witness, state in ROWS:
            with self.subTest(claim=key):
                self.assertIn(anchor, section(self.readme, heading))
                self.assertTrue((ROOT / source).is_file(), source)
                self.assertTrue((ROOT / witness).is_file(), witness)
                self.assertIn(state, LIMITS)
        self.assertIn("NO_VERIFICABLE", report)
        self.assertIn("sin tocar `docs/matriz-cobertura.md`", report)
        self.assertIn("CI exact-SHA", report)

    def test_carriles_dinamicos_no_suplantan_reserva(self):
        expected = {
            "NOW": [
                'is:issue is:open label:"estado: reservado"',
                'is:issue is:open label:"estado: en revisión"',
            ],
            "NEXT": [
                'is:issue is:open label:"prioridad: crítica" label:"estado: disponible"',
            ],
            "BLOCKED": [
                'is:issue is:open label:"estado: bloqueado"',
            ],
        }
        for lane, queries in expected.items():
            with self.subTest(lane=lane):
                self.assertEqual(query_labels(self.readme, lane), queries)
        altered = self.readme.replace(
            "is%3Aopen+label%3A%22estado%3A+reservado%22",
            "label%3A%22estado%3A+reservado%22", 1,
        )
        self.assertNotEqual(self.readme, altered)
        with self.assertRaises(AssertionError):
            self.assertEqual(query_labels(altered, "NOW"), expected["NOW"])

    def test_controladores_de_negocio_usan_scope_servidor(self):
        controllers = {
            "OrderController": ("/orders", "orders.view"),
            "CustomerController": ("/customers", "customers.view"),
            "InventoryController": ("/inventory", "inventory.view"),
            "PurchasingController": ("/purchasing/suppliers", "inventory.view"),
        }
        for name, (route, permission) in controllers.items():
            with self.subTest(controller=name):
                source = (ROOT / "src/Http/Controller" / (name + ".php")).read_text(encoding="utf-8")
                self.assertIn("final class " + name, source)
                self.assertIn("authorizedBranchScope(", source)
                self.assertIn(permission, source)
                self.assertIn(route, source)
                self.assertIn("CurrentTenantForUser", source)
        # No se afirma test HTTP end-to-end de cada controller por estas aserciones.
        self.assertTrue((ROOT / "tests/test_cobertura_condor_28.py").is_file())

    def test_stack_y_gates_de_backend_y_frontend(self):
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        requires = composer["require"]
        self.assertEqual(requires["php"], "^8.5")
        self.assertEqual(requires["symfony/framework-bundle"], "7.4.*")
        self.assertIn("doctrine/orm", requires)
        self.assertIn("doctrine/dbal", requires)
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("react", package["dependencies"])
        self.assertIn("typescript", package["devDependencies"])
        self.assertIn("vite", package["devDependencies"])
        self.assertTrue((ROOT / "frontend/admin/AdminApp.tsx").is_file())
        self.assertTrue((ROOT / "templates/tenant/index.html.twig").is_file())
        for path in ("composer.lock", "package-lock.json", "phpstan.neon.dist",
                     "rector.php", "config/version.php", "scripts/live-schema-policy.php"):
            self.assertTrue((ROOT / path).is_file(), path)
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        for required in ("name: CI Factory v1", "name: Validar", "PHPStan",
                         "Rector", "TypeScript", "Playwright"):
            self.assertIn(required, ci)

    def test_fuentes_de_verdad_y_limite_no_live(self):
        for path in ("AGENTES.md", "ESPECIFICACIONES.md", "decisiones.yml",
                     "datos.yml", "docs/modulos-operativos.md"):
            self.assertTrue((ROOT / path).is_file(), path)
        agents = (ROOT / "AGENTES.md").read_text(encoding="utf-8")
        self.assertIn("PLAN-AGENTES.md", agents)
        self.assertIn("PHPStan", agents)
        self.assertIn("Rector", agents)
        self.assertIn("no", section(self.readme, "Qué hace el producto").lower())
        self.assertIn("Ningún merge autoriza datos reales, gasto, deploy o go-live", self.readme)
        self.assertIn("no convierte implementación en producción GREEN", self.readme)
        self.assertIn("Condor / GrindFlow / BRVTAL", section(self.readme, "Mapa de la fábrica"))

    def test_reutiliza_regresiones_de_portada_sin_red(self):
        path = ROOT / "tests/test_readme_contract_adoption.py"
        spec = importlib.util.spec_from_file_location("_readme_contract_1551", path)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        module = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = module
        spec.loader.exec_module(module)
        methods = (
            "test_readme_has_contract_v2_anatomy",
            "test_readme_is_contract_v2_without_unknown_blocks",
            "test_work_queue_now_excludes_closed_824",
            "test_work_queue_links_canonical_roadmap",
            "test_factory_map_preserves_roles",
        )
        suite = unittest.TestLoader().loadTestsFromNames(
            ["ReadmeContractAdoptionTests." + name for name in methods], module
        )
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output).run(suite)
        self.assertEqual(result.testsRun, len(methods), output.getvalue())
        self.assertTrue(result.wasSuccessful(), output.getvalue())


if __name__ == "__main__":
    unittest.main()
