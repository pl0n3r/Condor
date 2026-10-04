#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiConversationReceiptBridgeTests(unittest.TestCase):
    def test_authorized_tool_turn_returns_minimized_receipt_bound_to_registry_contracts(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Application\AI\AiToolReplayGuard;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'content.draft.update',
        'risk' => AiToolPolicy::REVERSIBLE_WRITE,
        'input_names' => [],
        'required_inputs' => [],
    ],
);
$executions = 0;
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'content.draft.update',
        'descriptor' => $descriptor,
        'handler' => static function (array $inputs) use (&$executions): array {
            ++$executions;
            return ['opaque' => 'not-exposed'];
        },
    ]],
);

$result = AiConversationCore::turn(
    AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => 'content.draft.update',
        'knowledge_refs' => [],
    ], $policy),
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'content.draft.update',
        'inputs' => [],
        'request_ref' => 'request:receipt-001',
        'evidence_ref' => 'evidence:receipt-001',
        'timestamp' => '2026-10-04T07:00:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T07:00:00Z'),
    $registry,
    new AiToolReplayGuard(),
);

print json_encode([
    'result' => $result,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(1, observed["executions"])
        result = observed["result"]
        self.assertEqual("completed", result["status"])
        self.assertTrue(result["executed"])
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "tool_ref": "content.draft.update",
                "request_ref": "request:receipt-001",
                "decision": "authorized",
                "risk": "reversible_write",
                "outcome": "success",
                "evidence_ref": "evidence:receipt-001",
                "timestamp": "2026-10-04T07:00:00+00:00",
            },
            result["receipt"],
        )
        self.assertEqual(
            result["audit"]["evidence_ref"],
            result["receipt"]["evidence_ref"],
        )

    def test_receipt_never_exposes_registry_handler_output(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Application\AI\AiToolReplayGuard;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
    ],
);
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static fn (array $inputs): array => [
            'opaque_input' => ['value' => 'internal'],
            'opaque_output' => ['result' => 'internal'],
            'metadata' => ['marker' => 'internal'],
        ],
    ]],
);

$result = AiConversationCore::turn(
    AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'knowledge_refs' => [],
    ], $policy),
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'inputs' => [],
        'request_ref' => 'request:receipt-002',
        'evidence_ref' => 'evidence:receipt-002',
        'timestamp' => '2026-10-04T07:00:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T07:00:00Z'),
    $registry,
    new AiToolReplayGuard(),
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        encoded = json.dumps(observed, sort_keys=True)
        self.assertNotIn("opaque_input", encoded)
        self.assertNotIn("opaque_output", encoded)
        self.assertNotIn("metadata", encoded)
        self.assertEqual(
            {
                "tenant_ref",
                "tool_ref",
                "request_ref",
                "decision",
                "risk",
                "outcome",
                "evidence_ref",
                "timestamp",
            },
            set(observed["receipt"]),
        )


if __name__ == "__main__":
    unittest.main()
