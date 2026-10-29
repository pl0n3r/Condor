#!/usr/bin/env python3
"""Issue #1323 — regresiones reales D-018 a D-030-S01 (12 filas).

Los probes ejercitan PHP existente en el checkout, con entradas sintéticas.
No certifican DB/HTTP, hosting, producción o la reconciliación de matriz.
D-023 es B (portabilidad Hostinger/AWS requiere infraestructura real).
D-030 y D-030-S01 son C (decisiones de producto, no funciones ejecutables).
"""
from __future__ import annotations

import importlib.util
import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CODES = (
    "D-018", "D-020", "D-021", "D-022", "D-023", "D-024",
    "D-025", "D-026", "D-027", "D-028", "D-030", "D-030-S01",
)
NOT_OFFLINE = {
    "D-023": ("B", "Portabilidad entre hosting real Hostinger y AWS"),
    "D-030": ("C", "Criterios rectores de producto, dominio y evolución"),
    "D-030-S01": ("C", "Propuesta de valor y perfil comercial, no código"),
}
REUSED = {
    "l1": "tests/test_contraste_d_l1.py",
    "l2": "tests/test_contraste_d_l2.py",
    "l5": "tests/test_contraste_d_l5.py",
}
PROBE = r'''<?php
declare(strict_types=1);
require_once getcwd().'/src/Domain/Orders/OrderStateMachine.php';
require_once getcwd().'/src/Domain/Workflow/WorkflowDefinition.php';
require_once getcwd().'/src/Domain/Workflow/ExecutionTrace.php';
require_once getcwd().'/src/Domain/Workflow/WorkflowEngine.php';
require_once getcwd().'/src/Infrastructure/Tenancy/TenantResolver.php';
require_once getcwd().'/src/Application/Commercial/PlanConfigurationRules.php';
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Workflow\WorkflowDefinition;
use App\Domain\Workflow\WorkflowEngine;
use App\Infrastructure\Tenancy\TenantResolver;
use App\Application\Commercial\PlanConfigurationRules;
function denied(callable $fn): bool {
    try { $fn(); } catch (DomainException $e) { return true; }
    return false;
}
$states = new OrderStateMachine();
$s = $states->initial('tenant-fixture-a', 50000);
$statesOK = $s['order'] === 'open' && $s['payment'] === 'pending'
    && $s['fulfillment'] === 'reserved' && $s['total_minor'] === 50000;
$statesDenied = denied(fn() => $states->initial('', 100))
    && denied(fn() => $states->initial('tenant-fixture-a', -1))
    && denied(fn() => $states->assertTransition($s, 'payment', 'paid'));
$s['paid_minor'] = 50000;
$statesDenied = $statesDenied && denied(fn() => $states->assertTransition($s, 'payment', 'paid'));
$s['payment'] = 'paid';
$states->assertSnapshot($s);
$statesOK = $statesOK && $s['paid_minor'] === $s['total_minor'];
$s['paid_minor'] = 50001;
$statesDenied = $statesDenied && denied(fn() => $states->assertSnapshot($s));
$rule = new WorkflowDefinition('tenant-fixture-a', 'rule-a', 'inventory.low',
    [['field' => 'stock.available', 'operator' => 'eq', 'value' => 2]],
    [['type' => 'inventory.reserve']]);
$engine = new WorkflowEngine(['inventory.reserve' => 'inventory.write']);
$trace = $engine->evaluate($rule, 'tenant-fixture-a', 'inventory.low',
    ['stock' => ['available' => 2], 'secret' => 'FIXTURE_SENTINEL'],
    ['inventory.write'])->toArray();
$workflowOK = $trace['status'] === 'planned'
    && $trace['planned_actions'] === ['inventory.reserve']
    && !str_contains(json_encode($trace, JSON_THROW_ON_ERROR), 'FIXTURE_SENTINEL');
$workflowDenied = denied(fn() => $engine->evaluate($rule, 'tenant-fixture-b',
    'inventory.low', ['stock' => ['available' => 2]], ['inventory.write']))
    && denied(fn() => $engine->evaluate($rule, 'tenant-fixture-a',
    'inventory.low', ['stock' => ['available' => 2]], []))
    && denied(fn() => new WorkflowDefinition('tenant-fixture-a',
        'invalid', 'inventory.low',
        [['field' => 'stock.available', 'operator' => 'eval', 'value' => 2]],
        [['type' => 'inventory.reserve']]));
$platformOK = TenantResolver::isPlatformHost('CONDORAPP.COM.CO.')
    && TenantResolver::isPlatformHost('www.condorapp.com.co')
    && !TenantResolver::isPlatformHost('tenant.example.invalid');
$platformDenied = !TenantResolver::isPlatformHost('condorapp.com.co.attacker.invalid')
    && !TenantResolver::isPlatformHost('evil.example.invalid');
$planOK = PlanConfigurationRules::isSelectableAddOn('seo')
    && !PlanConfigurationRules::isSelectableAddOn('extra-user');
$planDenied = !PlanConfigurationRules::isSelectableAddOn('extra-location')
    && !PlanConfigurationRules::isSelectableAddOn('extra-company');
echo json_encode([
    'D-021' => $statesOK,
    'D-021-denied' => $statesDenied,
    'D-022' => $workflowOK,
    'D-022-denied' => $workflowDenied,
    'D-024' => $platformOK,
    'D-025' => $platformOK,
    'D-025-denied' => $platformDenied,
    'D-026' => $planOK,
    'D-026-denied' => $planDenied,
], JSON_THROW_ON_ERROR);
'''
CUSTOMER_PROBE = r'''
$a = new App\Domain\Organization\Entity\Tenant('Firma ficticia A', 'firma-a');
$b = new App\Domain\Organization\Entity\Tenant('Firma ficticia B', 'firma-b');
$c1 = new App\Domain\Commerce\Entity\CommercialCategory($a, 'Categoría ficticia', 'categoria-a');
$c2 = new App\Domain\Commerce\Entity\CommercialCategory($b, 'Categoría ficticia', 'categoria-b');
$customer = new App\Domain\Commerce\Entity\Customer($a, 'Cliente ficticio');
exact($customer->tenant()->id() === $a->id(), 'D-028 tenant owner');
$customer->assignCommercialCategory($c1);
exact($customer->commercialCategory()?->tenant()->id() === $a->id(), 'D-028 own category');
reject(fn() => $customer->assignCommercialCategory($c2), \DomainException::class);
exact($customer->commercialCategory()?->id() === $c1->id(), 'D-028 no mutation');
'''


def matrix_states(source: object) -> dict[str, str]:
    """Exige las 12 filas completas, sin duplicados ni falsos CUBIERTO."""
    if not isinstance(source, str):
        raise ValueError("UNKNOWN: matriz ausente")
    rows: dict[str, str] = {}
    tick = chr(96)
    for line in source.splitlines():
        for key in CODES:
            prefix = f"{tick}{key}{tick} · {tick}ESPECIFICACIONES.md{tick}"
            if not re.match(r"^\|\s*" + re.escape(prefix) + r"\s*\|", line):
                continue
            cells = [x.strip() for x in line.strip().strip("|").split("|")]
            if (key in rows or len(cells) != 5 or not all(cells)
                    or cells[0] != prefix or cells[2] != tick + "NO_VERIFICABLE" + tick
                    or cells[4] != "#1" or key not in cells[3]):
                raise ValueError("UNKNOWN: fila faltante, incompleta o falsa " + key)
            rows[key] = "NO_VERIFICABLE"
    if set(rows) != set(CODES):
        raise ValueError("UNKNOWN: faltan filas " + repr(set(CODES) - set(rows)))
    return rows


def load_existing(name: str):
    if name not in REUSED:
        raise ValueError("UNKNOWN: referencia fuera del contrato")
    path = ROOT / REUSED[name]
    if not path.is_file() or path.is_symlink():
        raise ValueError("UNKNOWN: prueba PHP existente ausente " + name)
    spec = importlib.util.spec_from_file_location("condor1323_real_" + name, path)
    if spec is None or spec.loader is None:
        raise ValueError("UNKNOWN: no se puede cargar " + name)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def php_evidence() -> dict[str, bool]:
    """Ejecuta PHP de src/, sin un mock de la lógica de negocio."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI ausente")
    for file in (
        "src/Domain/Orders/OrderStateMachine.php",
        "src/Domain/Workflow/WorkflowDefinition.php",
        "src/Domain/Workflow/ExecutionTrace.php",
        "src/Domain/Workflow/WorkflowEngine.php",
        "src/Infrastructure/Tenancy/TenantResolver.php",
        "src/Application/Commercial/PlanConfigurationRules.php",
    ):
        if not (ROOT / file).is_file():
            raise AssertionError("UNKNOWN: fuente real ausente " + file)
    result = subprocess.run(
        [php], cwd=ROOT, input=PROBE, text=True,
        capture_output=True, check=False, timeout=25,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: PHP falla: " + result.stderr[-700:])
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: recibo PHP ilegible") from error
    expected = {"D-021", "D-021-denied", "D-022", "D-022-denied",
                "D-024", "D-025", "D-025-denied", "D-026", "D-026-denied"}
    if (not isinstance(data, dict) or set(data) != expected
            or any(type(value) is not bool for value in data.values())):
        raise AssertionError("UNKNOWN: recibo parcial o ambiguo")
    return data


class CoberturaCondor04Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.states = matrix_states(MATRIX.read_text(encoding="utf-8"))

    def test_contrato_principal(self) -> None:
        """AC-01: nueve filas ejecutan PHP real; B/C quedan sin acreditar."""
        self.assertEqual(set(self.states), set(CODES))
        l1 = load_existing("l1")
        result1 = l1.php_regressions()
        for code in ("D-018", "D-020", "D-027"):
            with self.subTest(code=code):
                self.assertIs(result1[code], True)
                self.assertIs(result1[code + "-denied"], True)
        l2 = load_existing("l2")
        result2 = l2.ContrasteDL2Tests("test_contrato_principal").run_php()
        for key in ("states", "states_denied", "workflow", "workflow_denied", "redacted"):
            self.assertIs(result2[key], True, key)
        real = php_evidence()
        for key, state in real.items():
            with self.subTest(contract=key):
                self.assertIs(state, True)
        l5 = load_existing("l5")
        l5.ContrasteDL5Tests("test_contrato_principal").php(CUSTOMER_PROBE)
        self.assertEqual(len(CODES) - len(NOT_OFFLINE), 9)

    def test_falla_cerrado(self) -> None:
        """AC-02: rechaza montos, tenant, permiso, add-on y matriz corrupta."""
        source = MATRIX.read_text(encoding="utf-8")
        tick = chr(96)
        for code in CODES:
            line = next(x for x in source.splitlines()
                        if x.startswith("| " + tick + code + tick + " ·"))
            for invalid in (
                source.replace(line + "\n", "", 1),
                source + "\n" + line,
                source.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                source.replace(line, line.replace("ESPECIFICACIONES.md", "wrong.md"), 1),
            ):
                with self.subTest(code=code):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_states(invalid)
        for invalid in (None, "", "| incompleto |"):
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                matrix_states(invalid)
        for code in ("D-021-denied", "D-022-denied", "D-025-denied", "D-026-denied"):
            self.assertIs(php_evidence()[code], True)
        inherited = load_existing("l1").php_regressions()
        for code in ("D-018-denied", "D-020-denied", "D-027-denied"):
            self.assertIs(inherited[code], True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: exclusivamente escenarios sintéticos sin redes ni secretos."""
        self.assertEqual(set(self.states), set(CODES))
        self.assertEqual(set(NOT_OFFLINE), {"D-023", "D-030", "D-030-S01"})
        self.assertIn("tenant-fixture-a", PROBE)
        self.assertIn("Firma ficticia", CUSTOMER_PROBE)
        for unsafe in ("curl_", "file_get_contents(", "PDO(", "mysqli_",
                       "getenv(", "http://", "https://", "Bearer "):
            self.assertNotIn(unsafe, PROBE + CUSTOMER_PROBE)
        for code, (category, why) in NOT_OFFLINE.items():
            self.assertIn(category, {"B", "C", "D"})
            self.assertTrue(why)
            self.assertEqual(self.states[code], "NO_VERIFICABLE")
        self.assertNotIn("CUBIERTO", self.states.values())

    def test_ci_gate_contract(self) -> None:
        """AC-04: contrato local; Validar exact-HEAD es gate GitHub externo."""
        self.assertEqual(len(self.states), 12)
        self.assertEqual(len(NOT_OFFLINE), 3)
        self.assertTrue(all(php_evidence().values()))


if __name__ == "__main__":
    unittest.main()
