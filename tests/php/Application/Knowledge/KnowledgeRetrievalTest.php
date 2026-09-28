<?php

declare(strict_types=1);

namespace App\Tests\Application\Knowledge;

use App\Application\Knowledge\KnowledgeRetrieval;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class KnowledgeRetrievalTest extends TestCase
{
    public function testUsesOnlyPublishedFreshPermittedKnowledge(): void
    {
        $at = new DateTimeImmutable('2026-09-28T12:00:00Z');
        $result = KnowledgeRetrieval::retrieve([
            $this->article(['id' => 'published', 'state' => 'published']),
            $this->article(['id' => 'approved', 'state' => 'approved']),
            $this->article(['id' => 'draft', 'state' => 'draft']),
            $this->article([
                'id' => 'stale',
                'state' => 'published',
                'stale_after' => '2026-09-20T00:00:00Z',
            ]),
        ], $this->request(), $at);

        self::assertSame('ready', $result['status']);
        self::assertSame(1, $result['evidence_count']);
        self::assertSame('published', $result['evidence'][0]['knowledge_id']);
    }

    public function testScopeVisibilityAndTenantMismatchFailClosed(): void
    {
        $at = new DateTimeImmutable('2026-09-28T12:00:00Z');
        $articles = [
            $this->article([
                'id' => 'tenant-acme',
                'state' => 'published',
                'scope' => 'tenant:acme',
            ]),
            $this->article([
                'id' => 'tenant-other',
                'state' => 'published',
                'scope' => 'tenant:other',
            ]),
            $this->article([
                'id' => 'staff-global',
                'state' => 'published',
                'visibility' => 'staff',
                'audience' => 'staff',
            ]),
        ];

        $result = KnowledgeRetrieval::retrieve(
            $articles,
            $this->request(['scope' => 'tenant:acme']),
            $at,
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(['tenant-acme'], array_column($result['evidence'], 'knowledge_id'));

        $staff = KnowledgeRetrieval::retrieve(
            $articles,
            $this->request([
                'visibility' => 'staff',
                'scope' => 'tenant:acme',
            ]),
            $at,
        );
        self::assertSame(['staff-global'], array_column($staff['evidence'], 'knowledge_id'));
    }

    public function testResponsePreservesEvidenceAndSourceReferences(): void
    {
        $result = KnowledgeRetrieval::retrieve(
            [$this->article(['id' => 'evidence', 'state' => 'published'])],
            $this->request(),
            new DateTimeImmutable('2026-09-28T12:00:00Z'),
        );

        $evidence = $result['evidence'][0];
        self::assertSame('spec:identity-recovery', $evidence['source_ref']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence['knowledge_fingerprint']);
        self::assertSame('2026-09-01T00:00:00+00:00', $evidence['reviewed_at']);
        self::assertSame('2026-10-01T00:00:00+00:00', $evidence['stale_after']);
    }

    public function testInsufficientConfidenceOrEvidenceProducesExplicitHandoff(): void
    {
        $at = new DateTimeImmutable('2026-09-28T12:00:00Z');
        $articles = [$this->article(['state' => 'published'])];

        $confidence = KnowledgeRetrieval::retrieve(
            $articles,
            $this->request(['evidence_confidence' => 'unknown']),
            $at,
        );
        self::assertSame('handoff', $confidence['status']);
        self::assertSame('confidence_insufficient', $confidence['reason']);

        $evidence = KnowledgeRetrieval::retrieve(
            $articles,
            $this->request(['minimum_sources' => 2]),
            $at,
        );
        self::assertSame('handoff', $evidence['status']);
        self::assertSame('evidence_insufficient', $evidence['reason']);
        self::assertSame(1, $evidence['evidence_count']);
    }

    public function testContractIsChannelAgnostic(): void
    {
        $result = KnowledgeRetrieval::retrieve(
            [$this->article(['state' => 'published'])],
            $this->request(),
            new DateTimeImmutable('2026-09-28T12:00:00Z'),
        );

        self::assertSame('support-context-v1', $result['channel_contract']);
        self::assertArrayNotHasKey('chat', $result);
        self::assertArrayNotHasKey('voice', $result);
        self::assertArrayNotHasKey('provider', $result);
    }

    public function testInvalidRequestClaimsFailClosed(): void
    {
        $this->expectException(DomainException::class);

        KnowledgeRetrieval::retrieve(
            [$this->article(['state' => 'published'])],
            $this->request(['visibility' => 'public', 'scope' => 'tenant:acme']),
            new DateTimeImmutable('2026-09-28T12:00:00Z'),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function article(array $overrides = []): KnowledgeArticle
    {
        return KnowledgeArticle::fromArray(array_replace([
            'id' => 'password-reset',
            'version' => 1,
            'state' => 'review',
            'visibility' => 'customer',
            'audience' => 'customer',
            'locale' => 'es-CO',
            'scope' => 'global',
            'title' => 'Cómo recuperar acceso',
            'body' => 'Contenido compartido por FAQ y soporte.',
            'owner_ref' => 'team:support',
            'source_ref' => 'spec:identity-recovery',
            'tags' => ['account', 'security'],
            'modules' => ['admin'],
            'product_version_refs' => ['plan-version:negocio@2'],
            'capability_refs' => ['capability:accounts'],
            'reviewed_at' => '2026-09-01T00:00:00Z',
            'stale_after' => '2026-10-01T00:00:00Z',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function request(array $overrides = []): array
    {
        return array_replace([
            'visibility' => 'customer',
            'locale' => 'es-CO',
            'module' => 'admin',
            'scope' => 'global',
            'evidence_confidence' => 'sufficient',
            'minimum_sources' => 1,
        ], $overrides);
    }
}
