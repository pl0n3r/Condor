<?php

declare(strict_types=1);

namespace App\Tests\Domain\Knowledge;

use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class KnowledgeArticleTest extends TestCase
{
    public function testSnapshotAndFingerprintAreDeterministic(): void
    {
        $first = KnowledgeArticle::fromArray($this->payload([
            'tags' => ['billing', 'account', 'billing'],
            'modules' => ['support', 'admin'],
            'capability_refs' => ['capability:reset', 'capability:accounts'],
        ]));
        $second = KnowledgeArticle::fromArray($this->payload([
            'tags' => ['account', 'billing'],
            'modules' => ['admin', 'support'],
            'capability_refs' => ['capability:accounts', 'capability:reset'],
        ]));

        self::assertSame($first->snapshot(), $second->snapshot());
        self::assertSame($first->versionFingerprint(), $second->versionFingerprint());
        self::assertSame(['plan-version:negocio@2'], $first->snapshot()['product_version_refs']);
    }

    public function testLifecyclePublishesOnlyApprovedFreshKnowledge(): void
    {
        $approved = KnowledgeArticle::fromArray($this->payload(['state' => 'approved']));
        $published = $approved->transitionTo('published', new DateTimeImmutable('2026-09-27T12:00:00Z'));
        self::assertSame('published', $published->state());
        self::assertSame(2, $published->version());

        $this->expectException(DomainException::class);
        KnowledgeArticle::fromArray($this->payload(['state' => 'draft']))
            ->transitionTo('published', new DateTimeImmutable('2026-09-27T12:00:00Z'));
    }

    public function testStaleAndUnknownFreshnessFailClosed(): void
    {
        $unknown = KnowledgeArticle::fromArray($this->payload([
            'state' => 'approved', 'reviewed_at' => null, 'stale_after' => null,
        ]));
        self::assertTrue($unknown->isStaleAt(new DateTimeImmutable('2026-09-27T12:00:00Z')));

        $stale = KnowledgeArticle::fromArray($this->payload([
            'state' => 'approved', 'stale_after' => '2026-09-20T00:00:00Z',
        ]));
        $this->expectException(DomainException::class);
        $stale->transitionTo('published', new DateTimeImmutable('2026-09-27T12:00:00Z'));
    }

    public function testUnknownFieldsAndImplicitTenantPublicScopeAreRejected(): void
    {
        try {
            KnowledgeArticle::fromArray($this->payload(['unexpected' => true]));
            self::fail('Un campo extra debe fallar cerrado.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        KnowledgeArticle::fromArray($this->payload([
            'visibility' => 'public', 'audience' => 'public', 'scope' => 'tenant:acme',
        ]));
    }

    public function testCanonicalReferencesRejectEmbeddedCommercialPayloads(): void
    {
        $this->expectException(DomainException::class);
        KnowledgeArticle::fromArray($this->payload([
            'product_version_refs' => [['plan' => 'negocio', 'price' => 199900]],
        ]));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'id' => 'password-reset',
            'version' => 1,
            'state' => 'review',
            'visibility' => 'customer',
            'audience' => 'customer',
            'locale' => 'es-CO',
            'scope' => 'global',
            'title' => 'Cómo recuperar acceso',
            'body' => 'Contenido compartido por FAQ y Help Center.',
            'owner_ref' => 'team:support',
            'source_ref' => 'spec:identity-recovery',
            'tags' => ['account', 'security'],
            'modules' => ['admin'],
            'product_version_refs' => ['plan-version:negocio@2'],
            'capability_refs' => ['capability:accounts'],
            'reviewed_at' => '2026-09-01T00:00:00Z',
            'stale_after' => '2026-10-01T00:00:00Z',
        ], $overrides);
    }
}
