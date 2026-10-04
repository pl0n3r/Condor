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


class AiToolInvocationTests(unittest.TestCase):
    def test_authorized_tool_executes_with_same_tenant_and_emits_minimal_audit(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
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
$result = AiToolInvocation::invoke(
    $context,
    $policy,
    'tenant-a',
    'catalog.read',
    'evidence:catalog-check',
    '2026-10-03T10:07:00+00:00',
    static function (array $inputs) use (&$executions): array {
        ++$executions;
        return ['raw' => 'must-not-leak'];
    },
    $descriptor,
);

print json_encode([
    'executions' => $executions,
    'result' => $result,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(1, observed["executions"])
        result = observed["result"]
        self.assertEqual("success", result["outcome"])
        self.assertTrue(result["executed"])
        self.assertEqual("evidence:catalog-check", result["evidence_ref"])
        self.assertEqual("tenant:tenant-a", result["audit"]["tenant_ref"])
        self.assertEqual("catalog.read", result["audit"]["tool_ref"])
        self.assertEqual("success", result["audit"]["outcome"])
        self.assertNotIn("raw", result)

    def test_sensitive_unknown_or_cross_tenant_invocation_fails_closed_without_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = static function (string $tool) use ($policy): AiTenantContext {
    return AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => $tool,
        'knowledge_refs' => [],
    ], $policy);
};
$descriptor = static function (string $tool) use ($policy): AiToolDescriptor {
    return AiToolDescriptor::fromArray(
        $policy,
        [
            'tool' => $tool,
            'risk' => $policy->risk($tool),
            'input_names' => [],
            'required_inputs' => [],
        ],
    );
};

$executions = 0;
$executor = static function (array $inputs = []) use (&$executions): void {
    ++$executions;
};

$cases = [
    'sensitive' => [
        $context('identity.permission.change'),
        'tenant-a',
        'identity.permission.change',
        $descriptor('identity.permission.change'),
    ],
    'unknown' => [
        $context('catalog.read'),
        'tenant-a',
        'unknown.read',
        $descriptor('catalog.read'),
    ],
    'cross_tenant' => [
        $context('catalog.read'),
        'tenant-b',
        'catalog.read',
        $descriptor('catalog.read'),
    ],
];

$out = [];
foreach ($cases as $name => [$tenantContext, $tenantId, $tool, $toolDescriptor]) {
    $out[$name] = AiToolInvocation::invoke(
        $tenantContext,
        $policy,
        $tenantId,
        $tool,
        'evidence:denied-check',
        '2026-10-03T10:07:00+00:00',
        $executor,
        $toolDescriptor,
    );
}

print json_encode([
    'executions' => $executions,
    'cases' => $out,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(0, observed["executions"])
        for name, result in observed["cases"].items():
            self.assertEqual("denied", result["outcome"], name)
            self.assertFalse(result["executed"], name)
            self.assertEqual("denied", result["audit"]["outcome"], name)
            self.assertEqual("evidence:denied-check", result["evidence_ref"], name)

    def test_invalid_audit_metadata_fails_before_authorized_tool_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;

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

$cases = [
    ['invalid-evidence-ref', '2026-10-03T10:07:00+00:00'],
    ['evidence:catalog-check', 'not-a-timestamp'],
];

$out = [];
foreach ($cases as [$evidenceRef, $timestamp]) {
    $executions = 0;
    $failedClosed = false;

    try {
        AiToolInvocation::invoke(
            $context,
            $policy,
            'tenant-a',
            'catalog.read',
            $evidenceRef,
            $timestamp,
            static function (array $inputs = []) use (&$executions): void {
                ++$executions;
            },
            $descriptor,
        );
    } catch (DomainException) {
        $failedClosed = true;
    }

    $out[] = [
        'failed_closed' => $failedClosed,
        'executions' => $executions,
    ];
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            [
                {"failed_closed": True, "executions": 0},
                {"failed_closed": True, "executions": 0},
            ],
            observed,
        )


if __name__ == "__main__":
    unittest.main()
