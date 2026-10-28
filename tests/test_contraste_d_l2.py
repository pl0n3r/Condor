#!/usr/bin/env python3
"""Condor #1181: contrato D-021/022/028/032 con PHP real y UNKNOWN honesto.

La comprobación de los enlaces controlador/persistencia/tenant es conservadora:
no equivale a prueba integrada Doctrine/HTTP ni a producción validada.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXPECTED = ("D-021", "D-022", "D-028", "D-032")
SOURCES = {
    "states": "src/Domain/Orders/OrderStateMachine.php",
    "order": "src/Domain/Orders/Entity/Order.php",
    "payments": "src/Application/Orders/OrderPaymentService.php",
    "definition": "src/Domain/Workflow/WorkflowDefinition.php",
    "trace": "src/Domain/Workflow/ExecutionTrace.php",
    "engine": "src/Domain/Workflow/WorkflowEngine.php",
    "work_repo": "src/Infrastructure/Persistence/WorkflowRepository.php",
    "work_controller": "src/Http/Controller/WorkflowController.php",
    "customer": "src/Domain/Commerce/Entity/Customer.php",
    "customers_http": "src/Http/Controller/CustomerController.php",
    "orders": "src/Application/Orders/OrderService.php",
    "stock": "src/Application/Inventory/InventoryService.php",
}

PHP_PROBE = r"""<?php
declare(strict_types=1);
require_once getcwd().'/src/Domain/Orders/OrderStateMachine.php';
require_once getcwd().'/src/Domain/Workflow/WorkflowDefinition.php';
require_once getcwd().'/src/Domain/Workflow/ExecutionTrace.php';
require_once getcwd().'/src/Domain/Workflow/WorkflowEngine.php';
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Workflow\WorkflowDefinition;
use App\Domain\Workflow\WorkflowEngine;
function denied(callable $fn): bool {
    try { $fn(); } catch (DomainException) { return true; }
    return false;
}
$states = new OrderStateMachine();
$s = $states->initial('tenant-fixture-A', 25000);
$independent = $s['order'] === 'open' && $s['payment'] === 'pending'
    && $s['fulfillment'] === 'reserved' && $s['total_minor'] === 25000;
$unpaid = denied(fn() => $states->assertTransition($s, 'payment', 'paid'));
$s['paid_minor'] = 25000;
$inconsistentPending = denied(fn() => $states->assertTransition($s, 'payment', 'paid'));
$s['payment'] = 'paid';
$states->assertTransition($s, 'fulfillment', 'consumed');
$label = $states->visibleName('order', 'open', ['order' => ['open' => 'Nuevo']]) === 'Nuevo';
$invalidTotal = denied(fn() => $states->initial('tenant-fixture-A', -1));
$overpaid = denied(function () use ($states, $s): void {
    $s['paid_minor'] = 25001;
    $states->assertSnapshot($s);
});
$badTransition = denied(fn() => $states->assertTransition($s, 'order', 'paid'));
$rule = new WorkflowDefinition('tenant-fixture-A', 'rule-fixture', 'inventory.low',
    [['field'=>'stock.available', 'operator'=>'eq', 'value'=>2]],
    [['type'=>'inventory.reserve']]);
$engine = new WorkflowEngine(['inventory.reserve'=>'inventory.write']);
$trace = $engine->evaluate($rule, 'tenant-fixture-A', 'inventory.low',
    ['stock'=>['available'=>2], 'private'=>'SYNTHETIC_SENTINEL'],
    ['inventory.write'])->toArray();
$planned = $trace['status'] === 'planned'
    && $trace['planned_actions'] === ['inventory.reserve'];
$missing = $engine->evaluate($rule, 'tenant-fixture-A', 'inventory.low',
    ['stock'=>[]], ['inventory.write'])->status === 'skipped';
$wrongTenant = denied(fn() => $engine->evaluate($rule, 'tenant-fixture-B',
    'inventory.low', ['stock'=>['available'=>2]], ['inventory.write']));
$noPermission = denied(fn() => $engine->evaluate($rule, 'tenant-fixture-A',
    'inventory.low', ['stock'=>['available'=>2]], []));
$badOperator = denied(fn() => new WorkflowDefinition('tenant-fixture-A',
    'bad', 'inventory.low', [['field'=>'stock.available', 'operator'=>'eval',
    'value'=>'1']], [['type'=>'inventory.reserve']]));
$external = denied(fn() => $engine->evaluate(new WorkflowDefinition(
    'tenant-fixture-A', 'bad-action', 'inventory.low', [],
    [['type'=>'external.post']]), 'tenant-fixture-A', 'inventory.low',
    [], ['inventory.write']));
echo json_encode([
    'states'=>$independent && $label,
    'states_denied'=>$unpaid && $inconsistentPending && $invalidTotal
        && $overpaid && $badTransition,
    'workflow'=>$planned && $missing,
    'workflow_denied'=>$wrongTenant && $noPermission && $badOperator && $external,
    'redacted'=>!str_contains(json_encode($trace, JSON_THROW_ON_ERROR),
                               'SYNTHETIC_SENTINEL'),
], JSON_THROW_ON_ERROR);
"""


def source(name: str) -> str:
    path = ROOT / SOURCES[name]
    if not path.is_file():
        raise ValueError("UNKNOWN: fuente ausente: " + name)
    return path.read_text(encoding="utf-8")


def evidence(text: str, needles: tuple[str, ...], label: str) -> None:
    if any(needle not in text for needle in needles):
        raise ValueError("UNKNOWN: falta evidencia de " + label)


def boundary_states() -> dict[str, str]:
    """Valida enlaces concretos; no presume integración completa."""
    evidence(source("order"), ("$currency", "strtoupper(trim($currency))",
                               "assertMoney"), "D-021/moneda")
    evidence(source("payments"), ("$order->currency()",
        "PAYMENT_IDEMPOTENCY_CONFLICT", "PAYMENT_METHOD_NOT_CONFIGURED",
        "wrapInTransaction"), "D-021/pagos")
    evidence(source("customers_http"), ("authorizedBranchScope",
        "customers.view", "customers.create", "['tenant' => $tenant",
        "requireCsrf"), "D-028/controlador")
    evidence(source("customer"), ("assignCommercialCategory",
        "$category->tenant()->id()", "$this->tenant()->id()"), "D-028/entidad")
    evidence(source("orders"), ("wrapInTransaction", "reserveOrderLine",
        "releaseReservation", "consumeReservation", "idempotencyKey"), "D-032/pedidos")
    evidence(source("stock"), ("assertOwnedScope", "reserveOrderLine",
        "releaseReservation", "consumeReservation", "wrapInTransaction"), "D-032/stock")
    evidence(source("work_repo"), ("expectedVersion", "Conflicto de versión",
        "WHERE tenant_id = ?", "transactional"), "D-022/version")
    evidence(source("work_controller"), ("authorizedBranchScope",
        "settings.update", "withWorkflowLock"), "D-022/permisos")
    return {key: "NO_VERIFICABLE" for key in EXPECTED}


def matrix_states(text: str) -> dict[str, str]:
    rows: dict[str, str] = {}
    tick = chr(96)
    for line in text.splitlines():
        for key in EXPECTED:
            prefix = f"| {tick}{key}{tick} · {tick}ESPECIFICACIONES.md{tick} |"
            if line.startswith(prefix):
                columns = [cell.strip() for cell in line.strip("|").split("|")]
                if key in rows or len(columns) != 5:
                    raise ValueError("UNKNOWN: fila duplicada o inválida: " + key)
                match = re.fullmatch(r"[A-Z_]+", columns[2].strip(tick))
                if not match:
                    raise ValueError("UNKNOWN: estado inválido: " + key)
                rows[key] = match.group()
    if set(rows) != set(EXPECTED):
        raise ValueError("UNKNOWN: falta alguna fila de decisión")
    return rows


class ContrasteDL2Tests(unittest.TestCase):
    def run_php(self) -> dict[str, bool]:
        php = shutil.which("php")
        self.assertIsNotNone(php, "UNKNOWN: PHP CLI requerido")
        for name in ("states", "definition", "trace", "engine"):
            source(name)
        proc = subprocess.run([php], input=PHP_PROBE, text=True,
            cwd=ROOT, capture_output=True, timeout=30, check=False)
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        return json.loads(proc.stdout)

    def test_contrato_principal(self) -> None:
        result = self.run_php()
        self.assertTrue(result["states"], "D-021: pedido/pago/entrega independientes")
        self.assertTrue(result["workflow"], "D-022: regla interna sin despacho")
        self.assertEqual(set(boundary_states()), set(EXPECTED))

    def test_falla_cerrado(self) -> None:
        result = self.run_php()
        self.assertTrue(result["states_denied"], "D-021: montos y transiciones")
        self.assertTrue(result["workflow_denied"],
                        "D-022: tenant, permisos, operadores y acciones")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            evidence("", ("assertOwnedScope",), "D-032/tenant")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_states("| evidencia insuficiente |")

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(self.run_php()["redacted"])
        self.assertIn("tenant-fixture-A", PHP_PROBE)
        for unsafe in ("https://", "curl_", "getenv(", "file_get_contents("):
            self.assertNotIn(unsafe, PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        states = matrix_states((ROOT / "docs/matriz-cobertura.md")
                               .read_text(encoding="utf-8"))
        self.assertEqual({key: "NO_VERIFICABLE" for key in EXPECTED}, states)
        self.assertEqual(states, boundary_states())


if __name__ == "__main__":
    unittest.main()
