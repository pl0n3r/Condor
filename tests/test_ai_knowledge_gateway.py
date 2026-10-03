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


class AiKnowledgeGatewayTests(unittest.TestCase):
    def test_ready_uses_only_authorized_published_fresh_knowledge_with_sources(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiKnowledgeGateway;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;

function article(array $overrides): KnowledgeArticle {
    $base = [
        'id' => 'allowed',
        'version' => 1,
        'state' => 'published',
        'visibility' => 'customer',
        'audience' => 'customer',
        'locale' => 'es-CO',
        'scope' => 'global',
        'title' => 'Guía autorizada',
        'body' => 'No convertir en respuesta generada.',
        'owner_ref' => 'team:support',
        'source_ref' => 'spec:allowed',
        'tags' => ['support'],
        'modules' => ['admin'],
        'product_version_refs' => ['plan-version:negocio@2'],
        'capability_refs' => ['capability:knowledge'],
        'reviewed_at' => '2026-09-01T00:00:00Z',
        'stale_after' => '2026-11-01T00:00:00Z',
    ];
    $payload = array_replace($base, $overrides);
    if (array_key_exists('id', $overrides) && !array_key_exists('source_ref', $overrides)) {
        $payload['source_ref'] = 'spec:' . $overrides['id'];
    }
    return KnowledgeArticle::fromArray($payload);
}

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'knowledge.read',
    'knowledge_refs' => ['knowledge:global-guide', 'knowledge:tenant-guide'],
], $policy);

$result = AiKnowledgeGateway::retrieve(
    $context,
    $policy,
    [
        article(['id' => 'global-guide', 'scope' => 'global']),
        article(['id' => 'tenant-guide', 'scope' => 'tenant:tenant-a']),
        article(['id' => 'not-allowed', 'scope' => 'tenant:tenant-a']),
        article(['id' => 'other-tenant', 'scope' => 'tenant:tenant-b']),
    ],
    [
        'visibility' => 'customer',
        'locale' => 'es-CO',
        'module' => 'admin',
        'evidence_confidence' => 'sufficient',
        'minimum_sources' => 2,
    ],
    new DateTimeImmutable('2026-10-03T12:00:00Z'),
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("ready", observed["status"])
        self.assertEqual(
            ["knowledge:global-guide", "knowledge:tenant-guide"],
            observed["evidence_refs"],
        )
        self.assertEqual(
            ["spec:global-guide", "spec:tenant-guide"],
            [source["source_ref"] for source in observed["sources"]],
        )
        self.assertNotIn("answer", observed)
        self.assertNotIn("body", observed)

    def test_scope_mismatch_stale_or_insufficient_evidence_handoffs_without_answer(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiKnowledgeGateway;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;

function article(array $overrides): KnowledgeArticle {
    $base = [
        'id' => 'allowed',
        'version' => 1,
        'state' => 'published',
        'visibility' => 'customer',
        'audience' => 'customer',
        'locale' => 'es-CO',
        'scope' => 'global',
        'title' => 'Guía autorizada',
        'body' => 'No convertir en respuesta generada.',
        'owner_ref' => 'team:support',
        'source_ref' => 'spec:allowed',
        'tags' => ['support'],
        'modules' => ['admin'],
        'product_version_refs' => ['plan-version:negocio@2'],
        'capability_refs' => ['capability:knowledge'],
        'reviewed_at' => '2026-09-01T00:00:00Z',
        'stale_after' => '2026-11-01T00:00:00Z',
    ];
    return KnowledgeArticle::fromArray(array_replace($base, $overrides));
}

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'knowledge.read',
    'knowledge_refs' => ['knowledge:allowed'],
], $policy);
$request = [
    'visibility' => 'customer',
    'locale' => 'es-CO',
    'module' => 'admin',
    'evidence_confidence' => 'sufficient',
    'minimum_sources' => 1,
];
$at = new DateTimeImmutable('2026-10-03T12:00:00Z');

$out = [];
$out['scope'] = AiKnowledgeGateway::retrieve(
    $context,
    $policy,
    [article(['scope' => 'tenant:tenant-b'])],
    $request,
    $at,
);
$out['stale'] = AiKnowledgeGateway::retrieve(
    $context,
    $policy,
    [article(['stale_after' => '2026-10-01T00:00:00Z'])],
    $request,
    $at,
);
$request['minimum_sources'] = 2;
$out['insufficient'] = AiKnowledgeGateway::retrieve(
    $context,
    $policy,
    [article([])],
    $request,
    $at,
);

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual({"scope", "stale", "insufficient"}, set(observed))
        for name, result in observed.items():
            self.assertEqual("handoff", result["status"], name)
            self.assertNotIn("answer", result, name)
            self.assertNotIn("body", result, name)


if __name__ == "__main__":
    unittest.main()
