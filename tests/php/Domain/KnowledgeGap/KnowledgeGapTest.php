<?php

declare(strict_types=1);

namespace App\Tests\Domain\KnowledgeGap;

use App\Domain\KnowledgeGap\KnowledgeGap;
use DomainException;
use PHPUnit\Framework\TestCase;

final class KnowledgeGapTest extends TestCase
{
    public function testEquivalentQuestionsDeduplicateToStableFingerprint(): void
    {
        $first = KnowledgeGap::fromArray($this->payload([
            'question' => '¿Cómo recupero mi acceso?',
        ]));
        $second = KnowledgeGap::fromArray($this->payload([
            'question' => '  cómo   recupero mi acceso!!!  ',
            'observed_at' => 2000,
        ]));

        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame('cómo recupero mi acceso', $first->snapshot()['question_key']);

        $aggregated = KnowledgeGap::aggregate([$second, $first]);
        self::assertCount(1, $aggregated);
        self::assertSame(2, $aggregated[0]['unanswered_count']);
        self::assertSame(0, $aggregated[0]['escalated_count']);
        self::assertSame(1000, $aggregated[0]['first_observed_at']);
        self::assertSame(2000, $aggregated[0]['last_observed_at']);
    }

    public function testGapRejectsPiiSecretsAndUnexpectedFields(): void
    {
        foreach ([
            'mi correo es persona@example.com y no puedo entrar',
            'mi teléfono 300 123 4567 no funciona',
            'Bearer abcdefghijklmnopqrstuvwxyz',
            'api_key sk-abcdefghijklmnopqrstuvwxyz',
        ] as $question) {
            try {
                KnowledgeGap::fromArray($this->payload(['question' => $question]));
                self::fail('El gap debe rechazar datos sensibles.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(DomainException::class);
        KnowledgeGap::fromArray($this->payload(['customer_id' => 'customer-123']));
    }

    public function testAggregateCountsDoNotChangeIdentityAndMergeProvenance(): void
    {
        $first = KnowledgeGap::fromArray($this->payload([
            'question' => 'No encuentro cómo cambiar la contraseña',
            'source_ref' => 'support:chat-aggregate',
            'evidence_refs' => ['knowledge:password-reset'],
            'observed_at' => 1000,
            'outcome' => 'unanswered',
        ]));
        $second = KnowledgeGap::fromArray($this->payload([
            'question' => 'no encuentro cómo cambiar la contraseña.',
            'source_ref' => 'support:human-escalation',
            'evidence_refs' => ['retrieval:handoff-001'],
            'observed_at' => 3000,
            'outcome' => 'escalated',
        ]));

        $rows = KnowledgeGap::aggregate([$first, $second]);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($first->fingerprint(), $row['fingerprint']);
        self::assertSame(1, $row['unanswered_count']);
        self::assertSame(1, $row['escalated_count']);
        self::assertSame(
            ['knowledge:password-reset', 'retrieval:handoff-001'],
            $row['evidence_refs'],
        );
        self::assertSame(
            ['support:chat-aggregate', 'support:human-escalation'],
            $row['source_refs'],
        );
        self::assertSame(1000, $row['first_observed_at']);
        self::assertSame(3000, $row['last_observed_at']);
    }

    public function testAggregationOrderIsDeterministic(): void
    {
        $a = KnowledgeGap::fromArray($this->payload([
            'question' => 'Dónde veo mis facturas',
            'module' => 'billing',
        ]));
        $b = KnowledgeGap::fromArray($this->payload([
            'question' => 'Cómo cambio la contraseña',
            'module' => 'admin',
        ]));

        $first = KnowledgeGap::aggregate([$a, $b]);
        $second = KnowledgeGap::aggregate([$b, $a]);

        self::assertSame($first, $second);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'question' => '¿Cómo recupero mi acceso?',
            'locale' => 'es-CO',
            'module' => 'admin',
            'scope' => 'tenant:acme',
            'source_ref' => 'support:retrieval-handoff',
            'evidence_refs' => ['knowledge:password-reset'],
            'observed_at' => 1000,
            'outcome' => 'unanswered',
        ], $overrides);
    }
}
