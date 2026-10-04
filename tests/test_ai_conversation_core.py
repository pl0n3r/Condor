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


class AiConversationCoreTests(unittest.TestCase):
    def test_turn_routes_knowledge_and_authorized_tools_through_single_tenant_context(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$at = new DateTimeImmutable('2026-10-04T07:00:00Z');
$article = KnowledgeArticle::fromArray([
    'id' => 'guide',
    'version' => 1,
    'state' => 'published',
    'visibility' => 'customer',
    'audience' => 'customer',
    'locale' => 'es-CO',
    'scope' => 'tenant:tenant-a',
    'title' => 'Guía autorizada',
    'body' => 'Contenido de prueba.',
    'owner_ref' => 'team:support',
    'source_ref' => 'spec:guide',
    'tags' => ['support'],
    'modules' => ['admin'],
    'product_version_refs' => ['plan-version:negocio@2'],
    'capability_refs' => ['capability:knowledge'],
    'reviewed_at' => '2026-09-01T00:00:00Z',
    'stale_after' => '2026-11-01T00:00:00Z',
]);

$knowledge = AiConversationCore::turn(
    AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => 'knowledge.read',
        'knowledge_refs' => ['knowledge:guide'],
    ], $policy),
    $policy,
    [
        'intent' => 'knowledge',
        'tenant_id' => 'tenant-a',
        'knowledge_request' => [
            'visibility' => 'customer',
            'locale' => 'es-CO',
            'module' => 'admin',
            'evidence_confidence' => 'sufficient',
            'minimum_sources' => 1,
        ],
    ],
    [$article],
    $at,
    AiToolRegistry::fromArray($policy, []),
);

$executions = 0;
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
        'handler' => static function (array $inputs) use (&$executions): array {
            ++$executions;
            return ['opaque' => 'not-exposed'];
        },
    ]],
);
$tool = AiConversationCore::turn(
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
        'request_ref' => 'request:core-001',
        'evidence_ref' => 'evidence:core-001',
        'timestamp' => '2026-10-04T07:00:00+00:00',
    ],
    [],
    $at,
    $registry,
);

print json_encode([
    'knowledge' => $knowledge,
    'tool' => $tool,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("ready", observed["knowledge"]["status"])
        self.assertFalse(observed["knowledge"]["executed"])
        self.assertEqual(1, observed["executions"])
        self.assertEqual("completed", observed["tool"]["status"])
        self.assertEqual("catalog.read", observed["tool"]["audit"]["tool_ref"])

    def test_sensitive_unknown_or_evidence_gap_handoffs_without_side_effects(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$at = new DateTimeImmutable('2026-10-04T07:00:00Z');
$registry = AiToolRegistry::fromArray($policy, []);

$sensitive = AiConversationCore::turn(
    AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => 'identity.permission.change',
        'knowledge_refs' => [],
    ], $policy),
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'identity.permission.change',
        'inputs' => [],
        'request_ref' => 'request:sensitive',
        'evidence_ref' => 'evidence:sensitive',
        'timestamp' => '2026-10-04T07:00:00+00:00',
    ],
    [],
    $at,
    $registry,
);

$unregistered = AiConversationCore::turn(
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
        'request_ref' => 'request:unregistered',
        'evidence_ref' => 'evidence:unregistered',
        'timestamp' => '2026-10-04T07:00:00+00:00',
    ],
    [],
    $at,
    $registry,
);

$unknown = AiConversationCore::turn(
    AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'knowledge_refs' => [],
    ], $policy),
    $policy,
    ['intent' => 'unknown', 'tenant_id' => 'tenant-a'],
    [],
    $at,
    $registry,
);

print json_encode([
    'sensitive' => $sensitive,
    'unregistered' => $unregistered,
    'unknown' => $unknown,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("handoff", observed["sensitive"]["status"])
        self.assertEqual("tool_sensitive_requires_human", observed["sensitive"]["reason"])
        self.assertEqual("handoff", observed["unregistered"]["status"])
        self.assertEqual("tool_handler_unavailable", observed["unregistered"]["reason"])
        self.assertEqual("handoff", observed["unknown"]["status"])
        for key in ("sensitive", "unregistered", "unknown"):
            self.assertFalse(observed[key]["executed"])


if __name__ == "__main__":
    unittest.main()
