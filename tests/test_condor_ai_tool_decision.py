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


class CondorAiToolDecisionTests(unittest.TestCase):
    def test_authorized_read_only_and_reversible_requests_require_matching_policy_and_tenant_context(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$out = [];
foreach ([
    ['catalog.read', AiToolPolicy::READ_ONLY],
    ['content.draft.update', AiToolPolicy::REVERSIBLE_WRITE],
] as [$tool, $expectedRisk]) {
    $context = AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => $tool,
        'knowledge_refs' => [],
    ], $policy);
    $decision = AiToolDecision::decide(
        $context,
        $policy,
        [
            'tenant_id' => 'tenant-a',
            'tool' => $tool,
            'request_ref' => 'request:decision-' . str_replace('.', '-', $tool),
        ],
    );
    $out[$tool] = [
        'decision' => $decision,
        'expected_risk' => $expectedRisk,
    ];
}
print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        for tool, item in observed.items():
            decision = item["decision"]
            self.assertEqual("authorized", decision["status"], tool)
            self.assertEqual("policy_allows", decision["reason"], tool)
            self.assertFalse(decision["executed"], tool)
            self.assertEqual(item["expected_risk"], decision["risk"], tool)
            self.assertEqual("tenant:tenant-a", decision["request"]["tenant_ref"], tool)
            self.assertEqual(tool, decision["request"]["tool_ref"], tool)

    def test_sensitive_unknown_or_context_mismatch_is_denied_without_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$sensitiveContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$readContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);

$out = [];
$out['sensitive'] = AiToolDecision::decide(
    $sensitiveContext,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'identity.permission.change',
        'request_ref' => 'request:sensitive',
    ],
);
$out['unknown'] = AiToolDecision::decide(
    $readContext,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'unknown.read',
        'request_ref' => 'request:unknown',
    ],
);
$out['cross_tenant'] = AiToolDecision::decide(
    $readContext,
    $policy,
    [
        'tenant_id' => 'tenant-b',
        'tool' => 'catalog.read',
        'request_ref' => 'request:cross-tenant',
    ],
);
$out['tool_mismatch'] = AiToolDecision::decide(
    $readContext,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'inventory.read',
        'request_ref' => 'request:tool-mismatch',
    ],
);

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("sensitive_requires_human", observed["sensitive"]["reason"])
        self.assertEqual("sensitive", observed["sensitive"]["risk"])
        for name in ("sensitive", "unknown", "cross_tenant", "tool_mismatch"):
            self.assertEqual("denied", observed[name]["status"], name)
            self.assertFalse(observed[name]["executed"], name)
        for name in ("unknown", "cross_tenant", "tool_mismatch"):
            self.assertEqual("request_invalid", observed[name]["reason"], name)
            self.assertIsNone(observed[name]["request"], name)


if __name__ == "__main__":
    unittest.main()
