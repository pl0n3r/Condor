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


class CondorAiToolRequestTests(unittest.TestCase):
    def test_request_binds_tenant_context_tool_and_opaque_request_reference_without_free_form_payload(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);

$request = AiToolRequest::fromArray(
    $context,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'request_ref' => 'request:catalog-lookup-001',
    ],
);

print json_encode([
    'tenant_id' => $request->tenantId(),
    'tool' => $request->tool(),
    'request_ref' => $request->requestRef(),
    'snapshot' => $request->snapshot(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("tenant-a", observed["tenant_id"])
        self.assertEqual("catalog.read", observed["tool"])
        self.assertEqual("request:catalog-lookup-001", observed["request_ref"])
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "tool_ref": "catalog.read",
                "request_ref": "request:catalog-lookup-001",
            },
            observed["snapshot"],
        )
        self.assertEqual(
            {"tenant_ref", "tool_ref", "request_ref"},
            set(observed["snapshot"]),
        )

    def test_unknown_tool_cross_tenant_or_personal_payload_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);

$cases = [
    'unknown' => [
        'tenant_id' => 'tenant-a',
        'tool' => 'unknown.read',
        'request_ref' => 'request:unknown',
    ],
    'cross_tenant' => [
        'tenant_id' => 'tenant-b',
        'tool' => 'catalog.read',
        'request_ref' => 'request:cross-tenant',
    ],
    'personal_payload' => [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'request_ref' => 'request:personal',
        'customer_email' => 'person@example.test',
    ],
    'free_form_payload' => [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'request_ref' => 'request:payload',
        'payload' => ['free_form' => 'secret'],
    ],
];

$out = [];
foreach ($cases as $name => $case) {
    try {
        AiToolRequest::fromArray($context, $policy, $case);
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
                "unknown": True,
                "cross_tenant": True,
                "personal_payload": True,
                "free_form_payload": True,
            },
            observed,
        )


if __name__ == "__main__":
    unittest.main()
