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
    def test_authorized_tool_turn_returns_minimized_receipt_bound_to_f1_contracts(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$executions = 0;
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
        'request_ref' => 'request:conversation-receipt-001',
        'evidence_ref' => 'evidence:conversation-receipt-001',
        'timestamp' => '2026-10-04T02:45:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T02:45:00Z'),
    static function () use (&$executions): array {
        ++$executions;
        return [
            'raw_output' => 'must-not-leak',
            'customer_email' => 'person@example.test',
        ];
    },
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
                "request_ref": "request:conversation-receipt-001",
                "decision": "authorized",
                "risk": "reversible_write",
                "outcome": "success",
                "evidence_ref": "evidence:conversation-receipt-001",
                "timestamp": "2026-10-04T02:45:00+00:00",
            },
            result["receipt"],
        )
        self.assertEqual(result["audit"]["evidence_ref"], result["receipt"]["evidence_ref"])

    def test_receipt_never_exposes_executor_output_or_raw_payload(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;

$policy = new AiToolPolicy();
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
        'request_ref' => 'request:conversation-receipt-002',
        'evidence_ref' => 'evidence:conversation-receipt-002',
        'timestamp' => '2026-10-04T02:45:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T02:45:00Z'),
    static fn (): array => [
        'raw_input' => ['prompt' => 'secret'],
        'raw_output' => ['result' => 'secret'],
        'headers' => ['authorization' => 'secret'],
    ],
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        encoded = json.dumps(observed, sort_keys=True)
        self.assertNotIn("raw_input", encoded)
        self.assertNotIn("raw_output", encoded)
        self.assertNotIn("authorization", encoded)
        self.assertNotIn("secret", encoded)
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
