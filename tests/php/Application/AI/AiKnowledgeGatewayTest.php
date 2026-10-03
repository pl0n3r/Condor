<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiKnowledgeGateway;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AiKnowledgeGatewayTest extends TestCase
{
    public function testReadyUsesOnlyAuthorizedPublishedFreshKnowledgeWithSources(): void
    {
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
                $this->article(['id' => 'global-guide', 'scope' => 'global']),
                $this->article(['id' => 'tenant-guide', 'scope' => 'tenant:tenant-a']),
                $this->article(['id' => 'not-allowed', 'scope' => 'tenant:tenant-a']),
                $this->article(['id' => 'other-tenant', 'scope' => 'tenant:tenant-b']),
            ],
            $this->request(['minimum_sources' => 2]),
            new DateTimeImmutable('2026-10-03T12:00:00Z'),
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(
            ['knowledge:global-guide', 'knowledge:tenant-guide'],
            $result['evidence_refs'],
        );
        self::assertSame(
            ['knowledge:global-guide', 'knowledge:tenant-guide'],
            array_column($result['sources'], 'knowledge_ref'),
        );
        self::assertSame(
            ['spec:global-guide', 'spec:tenant-guide'],
            array_column($result['sources'], 'source_ref'),
        );
        self::assertArrayNotHasKey('body', $result);
        self::assertArrayNotHasKey('answer', $result);
    }

    public function testScopeMismatchStaleOrInsufficientEvidenceHandoffsWithoutAnswer(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'knowledge.read',
            'knowledge_refs' => ['knowledge:allowed'],
        ], $policy);
        $at = new DateTimeImmutable('2026-10-03T12:00:00Z');

        $cases = [
            AiKnowledgeGateway::retrieve(
                $context,
                $policy,
                [$this->article(['id' => 'allowed', 'scope' => 'tenant:tenant-b'])],
                $this->request(),
                $at,
            ),
            AiKnowledgeGateway::retrieve(
                $context,
                $policy,
                [$this->article([
                    'id' => 'allowed',
                    'stale_after' => '2026-10-01T00:00:00Z',
                ])],
                $this->request(),
                $at,
            ),
            AiKnowledgeGateway::retrieve(
                $context,
                $policy,
                [$this->article(['id' => 'allowed'])],
                $this->request(['minimum_sources' => 2]),
                $at,
            ),
        ];

        foreach ($cases as $result) {
            self::assertSame('handoff', $result['status']);
            self::assertArrayNotHasKey('answer', $result);
            self::assertArrayNotHasKey('body', $result);
        }

        $wrongTool = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => ['knowledge:allowed'],
        ], $policy);
        $denied = AiKnowledgeGateway::retrieve(
            $wrongTool,
            $policy,
            [$this->article(['id' => 'allowed'])],
            $this->request(),
            $at,
        );

        self::assertSame('handoff', $denied['status']);
        self::assertSame('tool_not_authorized', $denied['reason']);
        self::assertSame([], $denied['sources']);
    }

    /** @param array<string, mixed> $overrides */
    private function article(array $overrides = []): KnowledgeArticle
    {
        $base = [
            'id' => 'allowed',
            'version' => 1,
            'state' => 'published',
            'visibility' => 'customer',
            'audience' => 'customer',
            'locale' => 'es-CO',
            'scope' => 'global',
            'title' => 'Guía autorizada',
            'body' => 'Contenido que no debe convertirse en respuesta generada.',
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
        if (isset($overrides['id']) && !isset($overrides['source_ref'])) {
            $payload['source_ref'] = 'spec:' . $overrides['id'];
        }

        return KnowledgeArticle::fromArray($payload);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function request(array $overrides = []): array
    {
        return array_replace([
            'visibility' => 'customer',
            'locale' => 'es-CO',
            'module' => 'admin',
            'evidence_confidence' => 'sufficient',
            'minimum_sources' => 1,
        ], $overrides);
    }
}
