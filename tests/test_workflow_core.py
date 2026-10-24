#!/usr/bin/env python3
"""Condor #855: offline domain workflow engine, no network or customer data."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / "src/Domain/Workflow"
FILES = [BASE / name for name in ("WorkflowDefinition.php", "WorkflowEngine.php", "ExecutionTrace.php")]

PHP_SCENARIOS = r'''<?php
declare(strict_types=1);
require_once getcwd().'/src/Domain/Workflow/WorkflowDefinition.php';
require_once getcwd().'/src/Domain/Workflow/ExecutionTrace.php';
require_once getcwd().'/src/Domain/Workflow/WorkflowEngine.php';
use App\Domain\Workflow\WorkflowDefinition;
use App\Domain\Workflow\WorkflowEngine;

function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
}
function rejected(callable $operation, string $label): void {
    try { $operation(); } catch (DomainException) { return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
$registry = ['inventory.restock' => 'inventory.manage', 'notifications.queue' => 'notifications.manage'];
$engine = new WorkflowEngine($registry);
$workflow = new WorkflowDefinition(
    'tenant-A', 'resurtido', 'inventory.low_stock',
    [
        ['field' => 'inventory.quantity', 'operator' => 'in', 'value' => [0, 1, 2, 3]],
        ['field' => 'inventory.active', 'operator' => 'eq', 'value' => true],
        ['field' => 'inventory.sku', 'operator' => 'exists'],
    ],
    [['type' => 'inventory.restock', 'arguments' => ['sku' => 'PRIVATE-123']],
     ['type' => 'notifications.queue', 'arguments' => ['message' => 'PRIVATE MESSAGE']]],
);
$payload = ['inventory' => ['quantity' => 2, 'active' => true, 'sku' => 'PRIVATE-123']];
$permissions = ['inventory.manage', 'notifications.manage'];
$trace = $engine->evaluate($workflow, 'tenant-A', 'inventory.low_stock', $payload, $permissions)->toArray();
check($trace['status'] === 'planned' && count($trace['conditions']) === 3, 'deterministic plan');
check($trace['planned_actions'] === ['inventory.restock', 'notifications.queue'], 'action order');
check($trace === $engine->evaluate($workflow, 'tenant-A', 'inventory.low_stock', $payload, $permissions)->toArray(), 'deterministic replay');
check(!str_contains(json_encode($trace, JSON_THROW_ON_ERROR), 'PRIVATE'), 'PII or arguments in trace');
check($engine->evaluate($workflow, 'tenant-A', 'inventory.low_stock', ['inventory' => ['quantity' => 12]], $permissions)->status === 'skipped', 'mismatch');
check($engine->evaluate($workflow, 'tenant-A', 'inventory.other', $payload, $permissions)->reason === 'trigger_mismatch', 'trigger');
$disabled = new WorkflowDefinition('tenant-A', 'disabled', 'inventory.low_stock', [], [['type' => 'inventory.restock']], false);
check($engine->evaluate($disabled, 'tenant-A', 'inventory.low_stock', $payload, $permissions)->reason === 'inactive', 'inactive');
rejected(fn() => $engine->evaluate($workflow, 'tenant-B', 'inventory.low_stock', $payload, $permissions), 'cross-tenant');
rejected(fn() => $engine->evaluate($workflow, 'tenant-A', 'inventory.low_stock', $payload, []), 'unauthorized actor');
rejected(fn() => $engine->evaluate(new WorkflowDefinition('tenant-A', 'bad', 'inventory.low_stock', [], [['type' => 'webhooks.send']]), 'tenant-A', 'inventory.low_stock', $payload, $permissions), 'unknown action');
rejected(fn() => new WorkflowDefinition('tenant-A', 'bad', 'event', [['field' => 'x', 'operator' => 'php_eval', 'value' => '1']], [['type' => 'inventory.restock']]), 'untrusted operator');
rejected(fn() => new WorkflowDefinition('tenant-A', 'bad', 'event', [], []), 'missing actions');
echo 'WORKFLOW_CORE_OK';
'''


class WorkflowNucleoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "Se requiere PHP CLI auténtico")
        for path in FILES:
            self.assertTrue(path.is_file(), str(path))
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT, capture_output=True, text=True, timeout=15, check=False)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        run = subprocess.run([php], input=PHP_SCENARIOS, cwd=ROOT,
                             capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        self.assertEqual("WORKFLOW_CORE_OK", run.stdout.strip())


if __name__ == "__main__":
    unittest.main()
