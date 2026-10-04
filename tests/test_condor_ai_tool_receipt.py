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


class CondorAiToolReceiptTests(unittest.TestCase):
    def test_receipt_binds_authorized_request_outcome_and_audit_evidence(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolDecision;
use App\Application\AI\AiToolReceipt;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'content.draft.update',
    'knowledge_refs' => [],
], $policy);
$decision = AiToolDecision::decide(
    $context,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'content.draft.update',
        'request_ref' => 'request:receipt-002',
    ],
);
$receipt = AiToolReceipt::fromArray(
    $context,
    $policy,
    [
        'request' => $decision['request'],
        'decision' => $decision,
        'audit' => [
            'tenant_ref' => 'tenant:tenant-a',
            'tool_ref' => 'content.draft.update',
            'outcome' => 'failure',
            'evidence_ref' => 'evidence:receipt-002',
            'timestamp' => '2026-10-03T21:00:00+00:00',
        ],
    ],
);
print json_encode($receipt->snapshot(), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "tool_ref": "content.draft.update",
                "request_ref": "request:receipt-002",
                "decision": "authorized",
                "risk": "reversible_write",
                "outcome": "failure",
                "evidence_ref": "evidence:receipt-002",
                "timestamp": "2026-10-03T21:00:00+00:00",
            },
            observed,
        )
        self.assertNotIn("raw_input", observed)
        self.assertNotIn("raw_output", observed)

    def test_mismatched_tenant_tool_request_or_raw_payload_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolDecision;
use App\Application\AI\AiToolReceipt;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$decision = AiToolDecision::decide(
    $context,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'request_ref' => 'request:receipt-003',
    ],
);
$valid = [
    'request' => $decision['request'],
    'decision' => $decision,
    'audit' => [
        'tenant_ref' => 'tenant:tenant-a',
        'tool_ref' => 'catalog.read',
        'outcome' => 'success',
        'evidence_ref' => 'evidence:receipt-003',
        'timestamp' => '2026-10-03T21:00:00+00:00',
    ],
];

$cases = [];

$crossTenant = $valid;
$crossTenant['request']['tenant_ref'] = 'tenant:tenant-b';
$cases['tenant'] = $crossTenant;

$toolMismatch = $valid;
$toolMismatch['audit']['tool_ref'] = 'inventory.read';
$cases['tool'] = $toolMismatch;

$requestMismatch = $valid;
$requestMismatch['request']['request_ref'] = 'request:different';
$cases['request'] = $requestMismatch;

$rawInput = $valid;
$rawInput['raw_input'] = ['prompt' => 'must-not-exist'];
$cases['raw_input'] = $rawInput;

$rawOutput = $valid;
$rawOutput['audit']['raw_output'] = ['result' => 'must-not-exist'];
$cases['raw_output'] = $rawOutput;

$denied = $valid;
$denied['decision']['status'] = 'denied';
$cases['denied'] = $denied;

$out = [];
foreach ($cases as $name => $case) {
    try {
        AiToolReceipt::fromArray($context, $policy, $case);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}
print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "tenant": True,
                "tool": True,
                "request": True,
                "raw_input": True,
                "raw_output": True,
                "denied": True,
            },
            observed,
        )


if __name__ == "__main__":
    unittest.main()
