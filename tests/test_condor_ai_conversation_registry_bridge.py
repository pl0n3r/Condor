#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "src/Application/AI/AiConversationCore.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiConversationRegistryBridgeTests(unittest.TestCase):
    def test_tool_turn_resolves_authorized_handler_from_registry_before_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
    ],
);
$executions = 0;
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static function (array $inputs) use (&$executions): array {
            ++$executions;

            return ['opaque' => 'not-exposed'];
        },
    ]],
);

$result = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'inputs' => [],
        'request_ref' => 'request:registry-bridge',
        'evidence_ref' => 'evidence:registry-bridge',
        'timestamp' => '2026-10-04T07:00:00+00:00',
    ],
    [],
    new \DateTimeImmutable('2026-10-04T07:00:00Z'),
    $registry,
);

print json_encode([
    'status' => $result['status'],
    'reason' => $result['reason'],
    'executed' => $result['executed'],
    'executions' => $executions,
    'tool_ref' => $result['audit']['tool_ref'] ?? null,
    'receipt_request_ref' => $result['receipt']['request_ref'] ?? null,
    'receipt_outcome' => $result['receipt']['outcome'] ?? null,
    'opaque_exposed' => array_key_exists('opaque', $result),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("completed", observed["status"])
        self.assertEqual("tool_succeeded", observed["reason"])
        self.assertTrue(observed["executed"])
        self.assertEqual(1, observed["executions"])
        self.assertEqual("catalog.read", observed["tool_ref"])
        self.assertEqual("request:registry-bridge", observed["receipt_request_ref"])
        self.assertEqual("success", observed["receipt_outcome"])
        self.assertFalse(observed["opaque_exposed"])

        source = CORE.read_text(encoding="utf-8")
        self.assertIn("AiToolRegistry $toolRegistry", source)
        self.assertIn("$toolRegistry->resolveWithInputs(", source)
        self.assertNotIn("$toolExecutor", source)
        self.assertIn("'version' => '0.1.158'", VERSION.read_text(encoding="utf-8"))

    def test_unregistered_or_mismatched_tool_handoffs_without_arbitrary_executor(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$turn = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'inputs' => [],
    'request_ref' => 'request:unregistered',
    'evidence_ref' => 'evidence:unregistered',
    'timestamp' => '2026-10-04T07:00:00+00:00',
];
$at = new \DateTimeImmutable('2026-10-04T07:00:00Z');

$unregistered = AiConversationCore::turn(
    $context,
    $policy,
    $turn,
    [],
    $at,
    AiToolRegistry::fromArray($policy, []),
);

$executions = 0;
$inventoryDescriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'inventory.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
    ],
);
$mismatchedRegistry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'inventory.read',
        'descriptor' => $inventoryDescriptor,
        'handler' => static function (array $inputs) use (&$executions): void {
            ++$executions;
        },
    ]],
);
$mismatched = AiConversationCore::turn(
    $context,
    $policy,
    array_replace($turn, ['request_ref' => 'request:mismatched']),
    [],
    $at,
    $mismatchedRegistry,
);

print json_encode([
    'unregistered_status' => $unregistered['status'],
    'unregistered_reason' => $unregistered['reason'],
    'unregistered_executed' => $unregistered['executed'],
    'mismatched_status' => $mismatched['status'],
    'mismatched_reason' => $mismatched['reason'],
    'mismatched_executed' => $mismatched['executed'],
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("handoff", observed["unregistered_status"])
        self.assertEqual("tool_handler_unavailable", observed["unregistered_reason"])
        self.assertFalse(observed["unregistered_executed"])
        self.assertEqual("handoff", observed["mismatched_status"])
        self.assertEqual("tool_handler_unavailable", observed["mismatched_reason"])
        self.assertFalse(observed["mismatched_executed"])
        self.assertEqual(0, observed["executions"])

        source = CORE.read_text(encoding="utf-8")
        self.assertNotIn("callable $toolExecutor", source)
        self.assertNotIn("$toolExecutor", source)


if __name__ == "__main__":
    unittest.main()
