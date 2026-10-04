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


class CondorAiHandoffEnvelopeTests(unittest.TestCase):
    def test_handoff_binds_tenant_route_reason_and_minimal_evidence_without_raw_conversation(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'knowledge.read',
    'knowledge_refs' => ['knowledge:guide'],
], $policy);
$article = KnowledgeArticle::fromArray([
    'id' => 'guide',
    'version' => 1,
    'state' => 'published',
    'visibility' => 'customer',
    'audience' => 'customer',
    'locale' => 'es-CO',
    'scope' => 'tenant:tenant-a',
    'title' => 'Guide',
    'body' => 'raw-conversation-secret',
    'owner_ref' => 'team:support',
    'source_ref' => 'spec:guide',
    'tags' => ['support'],
    'modules' => ['admin'],
    'product_version_refs' => ['plan-version:negocio@2'],
    'capability_refs' => ['capability:knowledge'],
    'reviewed_at' => '2026-09-01T00:00:00Z',
    'stale_after' => '2026-11-01T00:00:00Z',
]);

$result = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'knowledge',
        'tenant_id' => 'tenant-a',
        'knowledge_request' => [
            'visibility' => 'customer',
            'locale' => 'es-CO',
            'module' => 'admin',
            'evidence_confidence' => 'sufficient',
            'minimum_sources' => 2,
        ],
    ],
    [$article],
    new DateTimeImmutable('2026-10-04T03:00:00Z'),
    AiToolRegistry::fromArray($policy, []),
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("handoff", observed["status"])
        self.assertFalse(observed["executed"])
        self.assertEqual(
            {"tenant_ref", "route", "reason", "evidence_refs"},
            set(observed["handoff"]),
        )
        self.assertEqual("tenant:tenant-a", observed["handoff"]["tenant_ref"])
        self.assertEqual("knowledge", observed["handoff"]["route"])
        self.assertEqual("evidence_insufficient", observed["handoff"]["reason"])
        self.assertEqual(observed["evidence_refs"], observed["handoff"]["evidence_refs"])
        self.assertNotIn("raw-conversation-secret", json.dumps(observed["handoff"]))

    def test_cross_tenant_unknown_or_sensitive_handoff_never_executes(self) -> None:
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
$registry = AiToolRegistry::fromArray($policy, []);
$at = new DateTimeImmutable('2026-10-04T03:00:00Z');

$crossTenant = AiConversationCore::turn(
    $readContext,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-b',
        'tool' => 'catalog.read',
        'request_ref' => 'request:cross-tenant-handoff',
        'evidence_ref' => 'evidence:cross-tenant-handoff',
        'timestamp' => '2026-10-04T03:00:00+00:00',
    ],
    [],
    $at,
    $registry,
);

$unknown = AiConversationCore::turn(
    $readContext,
    $policy,
    ['intent' => 'unknown', 'tenant_id' => 'tenant-a'],
    [],
    $at,
    $registry,
);

$sensitive = AiConversationCore::turn(
    $sensitiveContext,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'identity.permission.change',
        'request_ref' => 'request:sensitive-handoff',
        'evidence_ref' => 'evidence:sensitive-handoff',
        'timestamp' => '2026-10-04T03:00:00+00:00',
    ],
    [],
    $at,
    $registry,
);

print json_encode([
    'executions' => $executions,
    'cross_tenant' => $crossTenant,
    'unknown' => $unknown,
    'sensitive' => $sensitive,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(0, observed["executions"])
        for key in ("cross_tenant", "unknown", "sensitive"):
            result = observed[key]
            self.assertEqual("handoff", result["status"], key)
            self.assertFalse(result["executed"], key)
            self.assertEqual("tenant:tenant-a", result["handoff"]["tenant_ref"], key)
            self.assertEqual(
                {"tenant_ref", "route", "reason", "evidence_refs"},
                set(result["handoff"]),
                key,
            )

        self.assertEqual("none", observed["cross_tenant"]["handoff"]["route"])
        self.assertEqual("none", observed["unknown"]["handoff"]["route"])
        self.assertEqual("tool", observed["sensitive"]["handoff"]["route"])
        self.assertEqual(
            "tool_sensitive_requires_human",
            observed["sensitive"]["handoff"]["reason"],
        )


if __name__ == "__main__":
    unittest.main()
