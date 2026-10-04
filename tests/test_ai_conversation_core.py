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
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$at = new DateTimeImmutable('2026-10-03T12:00:00Z');
$article = KnowledgeArticle::fromArray([
    'id' => 'guide',
    'version' => 1,
    'state' => 'published',
    'visibility' => 'customer',
    'audience' => 'customer',
    'locale' => 'es-CO',
    'scope' => 'tenant:tenant-a',
    'title' => 'Guía autorizada',
    'body' => 'No debe convertirse en respuesta generada.',
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
    static fn (): null => null,
);

$executions = 0;
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
        'evidence_ref' => 'evidence:catalog-turn',
        'timestamp' => '2026-10-03T12:00:00+00:00',
    ],
    [],
    $at,
    static function () use (&$executions): array {
        ++$executions;
        return ['raw' => 'must-not-leak'];
    },
);

print json_encode([
    'knowledge' => $knowledge,
    'tool' => $tool,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("ready", observed["knowledge"]["status"])
        self.assertEqual(["knowledge:guide"], observed["knowledge"]["evidence_refs"])
        self.assertFalse(observed["knowledge"]["executed"])
        self.assertEqual(1, observed["executions"])
        self.assertEqual("completed", observed["tool"]["status"])
        self.assertEqual("tenant:tenant-a", observed["tool"]["audit"]["tenant_ref"])
        self.assertEqual(["evidence:catalog-turn"], observed["tool"]["evidence_refs"])
        for result in (observed["knowledge"], observed["tool"]):
            self.assertNotIn("answer", result)
            self.assertNotIn("body", result)
            self.assertNotIn("prompt", result)

    def test_sensitive_unknown_or_evidence_gap_handoffs_without_side_effects(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$at = new DateTimeImmutable('2026-10-03T12:00:00Z');
$executions = 0;
$executor = static function () use (&$executions): void { ++$executions; };

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
        'evidence_ref' => 'evidence:sensitive-turn',
        'timestamp' => '2026-10-03T12:00:00+00:00',
    ],
    [],
    $at,
    $executor,
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
    $executor,
);

$cross = AiConversationCore::turn(
    AiTenantContext::fromArray([
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'knowledge_refs' => [],
    ], $policy),
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-b',
        'tool' => 'catalog.read',
        'evidence_ref' => 'evidence:cross-tenant',
        'timestamp' => '2026-10-03T12:00:00+00:00',
    ],
    [],
    $at,
    $executor,
);

$article = KnowledgeArticle::fromArray([
    'id' => 'guide',
    'version' => 1,
    'state' => 'published',
    'visibility' => 'customer',
    'audience' => 'customer',
    'locale' => 'es-CO',
    'scope' => 'tenant:tenant-a',
    'title' => 'Guía autorizada',
    'body' => 'No devolver como respuesta.',
    'owner_ref' => 'team:support',
    'source_ref' => 'spec:guide',
    'tags' => ['support'],
    'modules' => ['admin'],
    'product_version_refs' => ['plan-version:negocio@2'],
    'capability_refs' => ['capability:knowledge'],
    'reviewed_at' => '2026-09-01T00:00:00Z',
    'stale_after' => '2026-11-01T00:00:00Z',
]);

$gap = AiConversationCore::turn(
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
            'minimum_sources' => 2,
        ],
    ],
    [$article],
    $at,
    $executor,
);

print json_encode([
    'executions' => $executions,
    'sensitive' => $sensitive,
    'unknown' => $unknown,
    'cross' => $cross,
    'gap' => $gap,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(0, observed["executions"])
        self.assertEqual("denied", observed["sensitive"]["status"])
        self.assertEqual("handoff", observed["unknown"]["status"])
        self.assertEqual("intent_not_supported", observed["unknown"]["reason"])
        self.assertEqual("handoff", observed["cross"]["status"])
        self.assertEqual("tenant_context_mismatch", observed["cross"]["reason"])
        self.assertEqual("handoff", observed["gap"]["status"])
        self.assertEqual("evidence_insufficient", observed["gap"]["reason"])
        for name in ("sensitive", "unknown", "cross", "gap"):
            self.assertFalse(observed[name]["executed"])
            self.assertNotIn("answer", observed[name])
            self.assertNotIn("body", observed[name])
            self.assertNotIn("prompt", observed[name])


if __name__ == "__main__":
    unittest.main()
