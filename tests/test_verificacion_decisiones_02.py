#!/usr/bin/env python3
"""Condor #1545: verificación parcial real de 20 decisiones; integral UNKNOWN."""
from __future__ import annotations

import re
import runpy
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "ESPECIFICACIONES.md"
REPORT = ROOT / "docs/verificacion/decisiones-02.md"
BT = chr(96)
UNKNOWN = "UNKNOWN"
# id, title, heading, real source, invariant markers, witness, merged PR, grade, gap
ROWS = [["D-030","Criterios expertos cerrados para producto, dominio y evolución","### D-030 — Criterios expertos cerrados para producto, dominio y evolución","ESPECIFICACIONES.md",["Condor adopta los siguientes criterios como defaults de diseño"],"tests/test_cobertura_condor_04.py",1383,"C normativa","Decisión de producto; sin aceptación comercial integral."],["D-030-S01","Propuesta de valor y perfil inicial","#### Propuesta de valor y perfil inicial","ESPECIFICACIONES.md",["Condor App centraliza y digitaliza la operación comercial"],"tests/test_cobertura_condor_04.py",1383,"C normativa","Validación de mercado no demostrable con fixtures."],["D-030-S02","Mapa de dominios y orden de evolución","#### Mapa de dominios y orden de evolución","ESPECIFICACIONES.md",["1. Identidad y acceso.","17. Integraciones."],"tests/test_cobertura_condor_05.py",1357,"C normativa","Los 17 dominios definidos no equivalen a implementación completa."],["D-030-S03","Terminología canónica mínima","#### Terminología canónica mínima","ESPECIFICACIONES.md",["**Tenant / cuenta Condor**","**Lista de precios**"],"tests/test_cobertura_condor_05.py",1357,"C normativa","Sin comprobación de coherencia en UI y persistencia."],["D-030-S04","Configurable vs invariante","#### Configurable vs invariante","src/Domain/Orders/OrderStateMachine.php",["ORDER_STATE_INVALID_TOTAL","ORDER_STATE_TRANSITION_DENIED"],"tests/test_cobertura_condor_05.py",1357,"A parcial","Estados reales sin cobertura integral de stock y concurrencia."],["D-031","Modelo de datos y multi-tenancy inicial","### D-031 — Modelo de datos y multi-tenancy inicial","src/Http/Controller/TenantPublicController.php",["tenantContext->current()","NotFoundHttpException()"],"tests/test_cobertura_condor_05.py",1357,"A parcial","Faltan aislamientos tenant y membresías en todas las entidades/DB."],["D-032","Flujo comercial, stock y consistencia","### D-032 — Flujo comercial, stock y consistencia","src/Domain/Orders/OrderStateMachine.php",["function assertSnapshot(","ORDER_STATE_CONSUMED_CANNOT_CANCEL"],"tests/test_cobertura_condor_05.py",1357,"A parcial","Falta locking, ledger y reservas/consumos concurrentes."],["D-033","Presencia web y CMS configurable","### D-033 — Presencia web y CMS configurable","src/Http/Controller/TenantPublicController.php",["render('tenant/index.html.twig'","'storefront' =>"],"tests/test_cobertura_condor_05.py",1357,"A parcial","Faltan editor CMS, temas, navegador y dominios verificados."],["D-034","Seguridad, tiempo, errores y contratos de aplicación","### D-034 — Seguridad, tiempo, errores y contratos de aplicación","config/packages/security.yaml",["password_hashers:","enable_csrf: true","login_throttling:"],"tests/test_cobertura_condor_05.py",1357,"B integral","Sin prueba integral de rutas, errores, UTC, permisos y auditoría."],["D-035","Workflows, integraciones y portabilidad","### D-035 — Workflows, integraciones y portabilidad","src/Domain/Workflow/WorkflowEngine.php",["El workflow no pertenece al tenant activo.","Acción interna no registrada:"],"tests/test_cobertura_condor_05.py",1357,"A parcial","Faltan post-commit, adaptadores y portabilidad real."],["D-036","Operación, calidad y evolución","### D-036 — Operación, calidad y evolución",".github/workflows/ci.yml",["Pruebas de contrato e integración","Validar documentación"],"tests/test_cobertura_condor_05.py",1357,"B externa","Faltan backups, accesibilidad y observabilidad real."],["D-037","API-first y preparación para aplicaciones móviles","### D-037 — API-first y preparación para aplicaciones móviles","config/packages/security.yaml",["'^/api/v1(?:/|$)'","roles: ROLE_USER"],"tests/test_cobertura_condor_05.py",1357,"A parcial","Rutas protegidas no certifican DTO e idempotencia para móvil."],["D-039","Identidad visible de versión","### D-039 — Identidad visible de versión","src/Http/Controller/SecurityController.php",["'app_version' => $version->human()"],"tests/test_brechas_condor_06.py",1269,"A parcial","Falta observar login y footer público con misma versión."],["D-040","Baseline integral de seguridad y confiabilidad (Fase 4 cerrada)","### D-040 — Baseline integral de seguridad y confiabilidad (Fase 4 cerrada)","config/packages/security.yaml",["password_hashers:","enable_csrf: true"],"tests/test_cobertura_condor_05.py",1357,"B integral","Faltan 14 subcontratos y negativos transversales."],["D-040-S01","1. Autenticación segura","#### 1. Autenticación segura","config/packages/security.yaml",["PasswordAuthenticatedUserInterface: 'auto'","login_throttling:"],"tests/test_cobertura_condor_06.py",1355,"A parcial","Faltan rehash, anti-enumeración y sesiones completas."],["D-040-S02","2. Política de sesión","#### 2. Política de sesión","config/packages/framework.yaml",["cookie_secure: auto","cookie_httponly: true","cookie_samesite: lax","gc_maxlifetime: 7200"],"tests/test_cobertura_condor_06.py",1355,"B integral","Faltan revocación, límite absoluto 12h y cookies de navegador."],["D-040-S03","3. Protección CSRF","#### 3. Protección CSRF","templates/security/login.html.twig",["csrf_token('authenticate')","name=\"_csrf_token\""],"tests/test_cobertura_condor_06.py",1355,"B integral","Faltan todas las mutaciones React/AJAX con negativos."],["D-040-S04","4. Consultas parametrizadas e inyección","#### 4. Consultas parametrizadas e inyección","src/Application/Storefront/PublicCatalogSearch.php",["function fromQuery(array $parameters)","InvalidArgumentException"],"tests/test_cobertura_condor_06.py",1355,"B integral","Falta comprobar SQL/DQL/allowlists en todos los endpoints."],["D-040-S05","5. Validación server-side","#### 5. Validación server-side","src/Application/Storefront/PublicCatalogSearch.php",["MAX_PAGE = 1000","La búsqueda no es válida."],"tests/test_cobertura_condor_06.py",1355,"A parcial","Filtros de catálogo no prueban permisos/mass assignment de todas las APIs."],["D-040-S06","6. Rate limiting y defensa contra abuso","#### 6. Rate limiting y defensa contra abuso","config/packages/rate_limiter.yaml",["password_reset_request_ip:","password_reset_request_account:","limit: 5","interval: '1 hour'"],"tests/test_cobertura_condor_06.py",1355,"B integral","Sin evidencia bajo carga distribuida por identidad/IP."]]
IDS = frozenset(row[0] for row in ROWS)
SOURCES = frozenset(row[3] for row in ROWS)
SUITES = (
    ("tests/test_cobertura_condor_04.py", "CoberturaCondor04Tests"),
    ("tests/test_cobertura_condor_05.py", "CoberturaCondor05Tests"),
    ("tests/test_cobertura_condor_06.py", "CoberturaCondor06Tests"),
    ("tests/test_brechas_condor_07.py", "BrechasCondor07Tests"),
)


def section(content: object, heading: str) -> str:
    if not isinstance(content, str):
        raise ValueError("UNKNOWN: no se puede leer la norma")
    lines = content.splitlines()
    indices = [index for index, line in enumerate(lines) if line == heading]
    if len(indices) != 1:
        raise ValueError("UNKNOWN: encabezado ausente o duplicado")
    start = indices[0]
    depth = len(heading) - len(heading.lstrip("#"))
    for i in range(start + 1, len(lines)):
        match = re.match(r"^(#{1,6})\s+", lines[i])
        if match and len(match.group(1)) <= depth:
            return "\n".join(lines[start:i])
    return "\n".join(lines[start:])


def matrix_rows(content: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(content, str):
        raise ValueError("UNKNOWN: matriz ilegible")
    out: dict[str, tuple[str, ...]] = {}
    pattern = r"^\|\s*" + BT + r"(D-\d{3}(?:-S\d{2})?)" + BT + r"(?=\s|·)"
    for line in content.splitlines():
        match = re.match(pattern, line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        cells = tuple(item.strip() for item in line.strip().strip("|").split("|"))
        if (key in out or len(cells) != 5
                or cells[0] != BT + key + BT + " · " + BT + "ESPECIFICACIONES.md" + BT
                or cells[2] != BT + "NO_VERIFICABLE" + BT
                or key not in cells[3] or cells[4] != "#1"):
            raise ValueError("UNKNOWN: fila no confiable " + key)
        out[key] = cells
    if set(out) != IDS:
        raise ValueError("UNKNOWN: lote incompleto")
    return out


def require_source(path: str, markers: list[str], override: str | None = None) -> str:
    if path not in SOURCES or not markers:
        raise ValueError("UNKNOWN: fuente no autorizada")
    source = override if override is not None else (ROOT / path).read_text(encoding="utf-8")
    if not isinstance(source, str) or any(marker not in source for marker in markers):
        raise ValueError("UNKNOWN: invariante retirada de " + path)
    return source


def run_previous(path: str, classname: str, method: str) -> None:
    if (path, classname) not in SUITES or method not in (
        "test_contrato_principal", "test_falla_cerrado"
    ):
        raise AssertionError("UNKNOWN: suite no autorizada")
    namespace = runpy.run_path(str(ROOT / path))
    klass = namespace.get(classname)
    if not isinstance(klass, type) or not issubclass(klass, unittest.TestCase):
        raise AssertionError("UNKNOWN: TestCase inválido")
    klass.setUpClass()
    try:
        result = unittest.TestResult()
        klass(method).run(result)
    finally:
        klass.tearDownClass()
    if result.testsRun != 1 or result.failures or result.errors or result.skipped:
        raise AssertionError("UNKNOWN: regresión previa falla " + path
                             + " " + str((result.failures + result.errors)[:1])
                             + " " + str(result.skipped[:1]))


class VerificacionDecisiones02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.report = REPORT.read_text(encoding="utf-8")

    def test_trazabilidad_de_20_decisiones(self) -> None:
        self.assertEqual(20, len(ROWS))
        self.assertEqual(20, len(IDS))
        inventory = matrix_rows(self.matrix)
        for key, title, heading, source, markers, witness, prior, grade, gap in ROWS:
            with self.subTest(key=key):
                self.assertEqual(inventory[key][1], title)
                self.assertIn("PR #" + str(prior) + " MERGED", inventory[key][3])
                exact_section = section(self.spec, heading)
                self.assertTrue(exact_section)
                if source == "ESPECIFICACIONES.md":
                    self.assertTrue(all(marker in exact_section for marker in markers))
                self.assertTrue(require_source(source, markers))
                self.assertTrue((ROOT / witness).is_file(), witness)
                self.assertTrue(grade and gap)
                self.assertIn("| " + key + " |", self.report)
                self.assertIn(source, self.report)
                self.assertIn(witness, self.report)
        self.assertIn("#1613", self.report)
        self.assertIn("NO_VERIFICABLE", self.report)

    def test_regresiones_de_dominio_y_seguridad_reales(self) -> None:
        for path, classname in SUITES:
            for method in ("test_contrato_principal", "test_falla_cerrado"):
                with self.subTest(path=path, method=method):
                    run_previous(path, classname, method)

    def test_version_y_csrf_de_login_y_publico(self) -> None:
        login = (ROOT / "templates/security/login.html.twig").read_text(encoding="utf-8")
        base = (ROOT / "templates/base.html.twig").read_text(encoding="utf-8")
        admin = require_source("src/Http/Controller/SecurityController.php",
                               ["'app_version' => $version->human()"])
        tenant = require_source("src/Http/Controller/TenantPublicController.php",
                                ["'app_version' => $version->human()"])
        self.assertIn("V {{ app_version }}", login)
        self.assertIn("V {{ app_version }}", base)
        self.assertIn("csrf_token('authenticate')", login)
        self.assertIn("$version->human()", admin)
        self.assertIn("$version->human()", tenant)

    def test_fail_closed_si_revierten_reglas_o_maquillan_matriz(self) -> None:
        target = next(line for line in self.matrix.splitlines()
                      if line.startswith("| " + BT + "D-040-S06" + BT + " ·"))
        variants = (
            self.matrix + "\n" + target,
            self.matrix.replace(target, "", 1),
            self.matrix.replace(target, target.replace(
                BT + "NO_VERIFICABLE" + BT, BT + "CUBIERTO" + BT), 1),
        )
        for mutation in variants:
            with self.assertRaises(ValueError):
                matrix_rows(mutation)
        with self.assertRaises(ValueError):
            section(self.spec.replace("### D-031 —", "### DELETED —"),
                    "### D-031 — Modelo de datos y multi-tenancy inicial")
        with self.assertRaises(ValueError):
            require_source("config/packages/security.yaml",
                           ["enable_csrf: true"], "security:\n  enable_csrf: false\n")
        with self.assertRaises(ValueError):
            require_source("config/packages/rate_limiter.yaml",
                           ["password_reset_request_ip:"], "framework:\n")
        with self.assertRaises(AssertionError):
            run_previous("tests/fake.py", "Fake", "test_fake")

    def test_clasificador_escoge_pruebas_base(self) -> None:
        selection = classify(["tests/test_verificacion_decisiones_02.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)


if __name__ == "__main__":
    unittest.main()
