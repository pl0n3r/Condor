#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/AI/AiSensitiveHandoffDecision.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiSensitiveHandoffDecisionTests(unittest.TestCase):
    def test_sensitive_denial_builds_minimized_human_review_request_without_execution_authority(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffDecision;
use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$decision = AiToolDecision::decide($context, $policy, [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
]);
$handoff = AiSensitiveHandoffDecision::fromDecision(
    $context,
    $policy,
    $decision,
    [
        'tenant_ref' => 'tenant:tenant-a',
        'tool_ref' => 'identity.permission.change',
        'request_ref' => 'request:permission-review-001',
        'evidence_ref' => 'evidence:policy-check-001',
    ],
);

print json_encode([
    'snapshot' => $handoff->snapshot(),
    'autonomous' => $policy->allowsAutonomousExecution('identity.permission.change'),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertFalse(observed["autonomous"])
        self.assertEqual(
            {
                "status": "denied",
                "reason": "sensitive_requires_human",
                "executed": False,
                "risk": "sensitive",
                "request": {
                    "tenant_ref": "tenant:tenant-a",
                    "tool_ref": "identity.permission.change",
                    "request_ref": "request:permission-review-001",
                    "evidence_ref": "evidence:policy-check-001",
                },
            },
            observed["snapshot"],
        )

    def test_authorized_non_sensitive_invalid_or_cross_tenant_decisions_fail_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffDecision;
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

$sensitiveDecision = AiToolDecision::decide($sensitiveContext, $policy, [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
]);
$readDecision = AiToolDecision::decide($readContext, $policy, [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'catalog.read',
    'request_ref' => 'request:catalog-read-001',
]);
$invalidDecision = AiToolDecision::decide($sensitiveContext, $policy, [
    'tenant_ref' => 'tenant:tenant-b',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
]);
$request = [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
    'evidence_ref' => 'evidence:policy-check-001',
];

$cases = [
    'authorized_non_sensitive' => [$readDecision, $request],
    'request_invalid_decision' => [$invalidDecision, $request],
    'wrong_risk' => [array_replace($sensitiveDecision, ['risk' => 'read_only']), $request],
    'request_null' => [array_replace($sensitiveDecision, ['request' => null]), $request],
    'cross_tenant' => [$sensitiveDecision, array_replace($request, ['tenant_ref' => 'tenant:tenant-b'])],
    'request_mismatch' => [$sensitiveDecision, array_replace($request, ['request_ref' => 'request:other-review'])],
    'invalid_evidence' => [$sensitiveDecision, array_replace($request, ['evidence_ref' => 'user@example.test'])],
];

$out = [];
foreach ($cases as $name => [$decision, $handoffRequest]) {
    try {
        AiSensitiveHandoffDecision::fromDecision(
            $sensitiveContext,
            $policy,
            $decision,
            $handoffRequest,
        );
        $out[$name] = false;
    } catch (\Throwable) {
        $out[$name] = true;
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "authorized_non_sensitive": True,
                "request_invalid_decision": True,
                "wrong_risk": True,
                "request_null": True,
                "cross_tenant": True,
                "request_mismatch": True,
                "invalid_evidence": True,
            },
            observed,
        )

    def test_decision_composition_does_not_register_handler_or_emit_approval_replay_or_permission_payload(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()

        for forbidden in (
            "aitoolregistry",
            "aitoolinvocation",
            "handler",
            "approval_token",
            "replay_token",
            "pdo",
            "doctrine",
            "httpclient",
            "curl",
            "http://",
            "https://",
            "file_put_contents",
            "fopen(",
            "permission_id",
            "actor_id",
            "user_id",
            "email",
            "payload",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)

        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffDecision;
use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$decision = AiToolDecision::decide($context, $policy, [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
]);
$snapshot = AiSensitiveHandoffDecision::fromDecision(
    $context,
    $policy,
    $decision,
    [
        'tenant_ref' => 'tenant:tenant-a',
        'tool_ref' => 'identity.permission.change',
        'request_ref' => 'request:permission-review-001',
        'evidence_ref' => 'evidence:policy-check-001',
    ],
)->snapshot();

print json_encode($snapshot, JSON_THROW_ON_ERROR);
"""
        )

        serialized = json.dumps(observed).lower()
        for forbidden in (
            "approval_token",
            "replay_token",
            "permission_id",
            "actor_id",
            "user_id",
            "password",
            "secret",
            "credential",
            "payload",
        ):
            self.assertNotIn(forbidden, serialized)

    def test_existing_tool_policy_and_autonomous_execution_semantics_remain_unchanged(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffDecision;
use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$before = $policy->allowlist();

$sensitiveContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$sensitiveDecision = AiToolDecision::decide($sensitiveContext, $policy, [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
]);
AiSensitiveHandoffDecision::fromDecision(
    $sensitiveContext,
    $policy,
    $sensitiveDecision,
    [
        'tenant_ref' => 'tenant:tenant-a',
        'tool_ref' => 'identity.permission.change',
        'request_ref' => 'request:permission-review-001',
        'evidence_ref' => 'evidence:policy-check-001',
    ],
);

$readContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$readDecision = AiToolDecision::decide($readContext, $policy, [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'catalog.read',
    'request_ref' => 'request:catalog-read-001',
]);

print json_encode([
    'allowlist_same' => $before === $policy->allowlist(),
    'read_autonomous' => $policy->allowsAutonomousExecution('catalog.read'),
    'write_autonomous' => $policy->allowsAutonomousExecution('content.draft.update'),
    'sensitive_autonomous' => $policy->allowsAutonomousExecution('identity.permission.change'),
    'read_decision' => $readDecision,
    'sensitive_decision' => $sensitiveDecision,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["allowlist_same"])
        self.assertTrue(observed["read_autonomous"])
        self.assertTrue(observed["write_autonomous"])
        self.assertFalse(observed["sensitive_autonomous"])
        self.assertEqual("authorized", observed["read_decision"]["status"])
        self.assertEqual("policy_allows", observed["read_decision"]["reason"])
        self.assertEqual("denied", observed["sensitive_decision"]["status"])
        self.assertEqual(
            "sensitive_requires_human",
            observed["sensitive_decision"]["reason"],
        )
        self.assertFalse(observed["sensitive_decision"]["executed"])


if __name__ == "__main__":
    unittest.main()
