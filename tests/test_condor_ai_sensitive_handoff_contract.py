#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/AI/AiSensitiveHandoffRequest.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiSensitiveHandoffContractTests(unittest.TestCase):
    def test_contract_accepts_only_existing_sensitive_tool_with_canonical_opaque_refs(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);

$request = AiSensitiveHandoffRequest::fromArray(
    $context,
    $policy,
    [
        'tenant_ref' => 'tenant:tenant-a',
        'tool_ref' => 'identity.permission.change',
        'request_ref' => 'request:permission-review-001',
        'evidence_ref' => 'evidence:policy-check-001',
    ],
);

print json_encode([
    'snapshot' => $request->snapshot(),
    'risk' => $policy->risk($request->toolRef()),
    'autonomous' => $policy->allowsAutonomousExecution($request->toolRef()),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("sensitive", observed["risk"])
        self.assertFalse(observed["autonomous"])
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "tool_ref": "identity.permission.change",
                "request_ref": "request:permission-review-001",
                "evidence_ref": "evidence:policy-check-001",
            },
            observed["snapshot"],
        )

    def test_contract_rejects_cross_tenant_non_sensitive_unknown_extra_or_freeform_material(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffRequest;
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

$base = [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
    'evidence_ref' => 'evidence:policy-check-001',
];

$cases = [
    'cross_tenant' => [$sensitiveContext, array_replace($base, ['tenant_ref' => 'tenant:tenant-b'])],
    'non_sensitive' => [$readContext, array_replace($base, ['tool_ref' => 'catalog.read'])],
    'unknown' => [$sensitiveContext, array_replace($base, ['tool_ref' => 'unknown.permission.change'])],
    'extra' => [$sensitiveContext, $base + ['permission_id' => 'admin']],
    'freeform' => [$sensitiveContext, $base + ['note' => 'change permissions for user@example.test']],
    'raw_evidence' => [$sensitiveContext, array_replace($base, ['evidence_ref' => 'user@example.test'])],
];

$out = [];
foreach ($cases as $name => [$context, $case]) {
    try {
        AiSensitiveHandoffRequest::fromArray($context, $policy, $case);
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
                "cross_tenant": True,
                "non_sensitive": True,
                "unknown": True,
                "extra": True,
                "freeform": True,
                "raw_evidence": True,
            },
            observed,
        )

    def test_contract_contains_no_handler_approval_execution_database_network_provider_or_real_data_authority(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()

        for forbidden in (
            "aitoolregistry",
            "aitoolinvocation",
            "handler",
            "approval",
            "approve",
            "replay",
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

    def test_snapshot_is_minimized_deterministic_and_secret_free(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiSensitiveHandoffRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$input = [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'request_ref' => 'request:permission-review-001',
    'evidence_ref' => 'evidence:policy-check-001',
];

$first = AiSensitiveHandoffRequest::fromArray($context, $policy, $input)->snapshot();
$second = AiSensitiveHandoffRequest::fromArray($context, $policy, $input)->snapshot();

print json_encode([
    'first' => $first,
    'second' => $second,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(observed["first"], observed["second"])
        snapshot = observed["first"]
        self.assertEqual(
            {"tenant_ref", "tool_ref", "request_ref", "evidence_ref"},
            set(snapshot),
        )
        serialized = json.dumps(snapshot).lower()
        for forbidden in (
            "password",
            "secret",
            "token",
            "credential",
            "customer_email",
            "permission_id",
            "actor_id",
            "user_id",
            "payload",
            "free_form",
        ):
            self.assertNotIn(forbidden, serialized)


if __name__ == "__main__":
    unittest.main()
