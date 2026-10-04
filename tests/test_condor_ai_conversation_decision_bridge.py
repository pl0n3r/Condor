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


class CondorAiConversationDecisionBridgeTests(unittest.TestCase):
    def test_tool_turn_builds_canonical_request_and_decision_before_executor(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$executions = 0;
$executor = static function () use (&$executions): array {
    ++$executions;
    return ['raw_output' => 'must-not-leak'];
};

$valid = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'request_ref' => 'request:conversation-001',
        'evidence_ref' => 'evidence:conversation-001',
        'timestamp' => '2026-10-04T02:00:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T02:00:00Z'),
    $executor,
);

$missingRequestRef = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'evidence_ref' => 'evidence:missing-request',
        'timestamp' => '2026-10-04T02:00:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T02:00:00Z'),
    $executor,
);

print json_encode([
    'valid' => $valid,
    'missing_request_ref' => $missingRequestRef,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(1, observed["executions"])
        self.assertEqual("completed", observed["valid"]["status"])
        self.assertTrue(observed["valid"]["executed"])
        self.assertEqual(
            ["evidence:conversation-001"],
            observed["valid"]["evidence_refs"],
        )
        self.assertNotIn("raw_output", observed["valid"])
        self.assertEqual("handoff", observed["missing_request_ref"]["status"])
        self.assertEqual(
            "turn_not_canonical",
            observed["missing_request_ref"]["reason"],
        )

    def test_denied_sensitive_or_mismatched_turn_never_calls_executor(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$readContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$sensitiveContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$executions = 0;
$executor = static function () use (&$executions): void {
    ++$executions;
};

$base = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'request_ref' => 'request:base',
    'evidence_ref' => 'evidence:base',
    'timestamp' => '2026-10-04T02:00:00+00:00',
];

$invalidRef = $base;
$invalidRef['request_ref'] = 'person@example.test';

$toolMismatch = $base;
$toolMismatch['tool'] = 'inventory.read';

$crossTenant = $base;
$crossTenant['tenant_id'] = 'tenant-b';

$sensitive = $base;
$sensitive['tool'] = 'identity.permission.change';
$sensitive['request_ref'] = 'request:sensitive';

$at = new DateTimeImmutable('2026-10-04T02:00:00Z');
$out = [
    'invalid_ref' => AiConversationCore::turn(
        $readContext, $policy, $invalidRef, [], $at, $executor
    ),
    'tool_mismatch' => AiConversationCore::turn(
        $readContext, $policy, $toolMismatch, [], $at, $executor
    ),
    'cross_tenant' => AiConversationCore::turn(
        $readContext, $policy, $crossTenant, [], $at, $executor
    ),
    'sensitive' => AiConversationCore::turn(
        $sensitiveContext, $policy, $sensitive, [], $at, $executor
    ),
    'executions' => $executions,
];

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(0, observed["executions"])
        self.assertEqual("denied", observed["invalid_ref"]["status"])
        self.assertEqual("tool_request_denied", observed["invalid_ref"]["reason"])
        self.assertEqual("denied", observed["tool_mismatch"]["status"])
        self.assertEqual(
            "tool_request_denied",
            observed["tool_mismatch"]["reason"],
        )
        self.assertEqual("handoff", observed["cross_tenant"]["status"])
        self.assertEqual(
            "tenant_context_mismatch",
            observed["cross_tenant"]["reason"],
        )
        self.assertEqual("denied", observed["sensitive"]["status"])
        self.assertEqual(
            "tool_sensitive_requires_human",
            observed["sensitive"]["reason"],
        )
        for key in ("invalid_ref", "tool_mismatch", "cross_tenant", "sensitive"):
            self.assertFalse(observed[key]["executed"], key)


if __name__ == "__main__":
    unittest.main()
