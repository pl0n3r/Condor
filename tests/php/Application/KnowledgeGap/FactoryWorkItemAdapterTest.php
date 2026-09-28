<?php

declare(strict_types=1);

namespace App\Tests\Application\KnowledgeGap;

use App\Application\KnowledgeGap\FactoryWorkItemAdapter;
use App\Domain\KnowledgeGap\KnowledgeGap;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FactoryWorkItemAdapterTest extends TestCase
{
    public function testDerivedWorkUsesFactoryWorkItemContract(): void
    {
        $gap = $this->aggregateGap();
        $work = FactoryWorkItemAdapter::toWorkItem($gap, $this->context());

        self::assertSame('automatic', $work['origin_mode']);
        self::assertSame('product', $work['origin_system']);
        self::assertSame('knowledge_documentation', $work['work_type']);
        self::assertSame('pl0n3r-group', $work['group_id']);
        self::assertSame('condor', $work['venture_id']);
        self::assertSame('condor', $work['project_id']);
        self::assertSame('pl0n3r/Condor', $work['repository_ref']);
        self::assertSame('operational', $work['authority_level']);
        self::assertSame('medium', $work['priority_class']);
        self::assertSame([], $work['depends_on']);
        self::assertSame('2026-09-28T16:00:00Z', $work['observed_at']);
        self::assertMatchesRegularExpression(
            '/^knowledge-gap:[a-f0-9]{20}:knowledge_documentation$/',
            $work['work_id'],
        );
        self::assertMatchesRegularExpression(
            '/^knowledge-gap:[a-f0-9]{64}:knowledge_documentation$/',
            $work['idempotency_key'],
        );
    }

    public function testWorkItemPreservesProvenanceWithoutRawQuestion(): void
    {
        $work = FactoryWorkItemAdapter::toWorkItem($this->aggregateGap(), $this->context());

        self::assertSame(
            [
                'knowledge:password-reset',
                'retrieval:handoff-001',
                'support:chat-aggregate',
                'support:human-escalation',
            ],
            $work['evidence_refs'],
        );
        self::assertArrayNotHasKey('question', $work);
        self::assertArrayNotHasKey('question_key', $work);
        self::assertArrayNotHasKey('customer_id', $work);
        self::assertArrayNotHasKey('ticket_payload', $work);
    }

    public function testIdempotencyDoesNotChangeWhenGapCountsIncrease(): void
    {
        $first = $this->aggregateGap();
        $extra = KnowledgeGap::fromArray($this->gapPayload([
            'question' => 'no encuentro cómo cambiar la contraseña',
            'source_ref' => 'support:another-channel',
            'evidence_refs' => ['retrieval:handoff-002'],
            'observed_at' => 1_790_611_300,
            'outcome' => 'unanswered',
        ]));
        $second = KnowledgeGap::aggregate([
            KnowledgeGap::fromArray($this->gapPayload()),
            KnowledgeGap::fromArray($this->gapPayload([
                'question' => 'no encuentro cómo cambiar la contraseña.',
                'source_ref' => 'support:human-escalation',
                'evidence_refs' => ['retrieval:handoff-001'],
                'observed_at' => 1_790_611_200,
                'outcome' => 'escalated',
            ])),
            $extra,
        ])[0];

        $firstWork = FactoryWorkItemAdapter::toWorkItem($first, $this->context());
        $secondWork = FactoryWorkItemAdapter::toWorkItem($second, $this->context());

        self::assertSame($firstWork['work_id'], $secondWork['work_id']);
        self::assertSame($firstWork['idempotency_key'], $secondWork['idempotency_key']);
        self::assertNotSame($firstWork['evidence_refs'], $secondWork['evidence_refs']);
    }

    public function testOnlyCanonicalKnowledgeWorkTypesAreAllowed(): void
    {
        foreach (['knowledge_documentation', 'content', 'product'] as $workType) {
            $context = $this->context(['work_type' => $workType]);
            self::assertSame(
                $workType,
                FactoryWorkItemAdapter::toWorkItem($this->aggregateGap(), $context)['work_type'],
            );
        }

        $this->expectException(DomainException::class);
        FactoryWorkItemAdapter::toWorkItem(
            $this->aggregateGap(),
            $this->context(['work_type' => 'private_scheduler']),
        );
    }

    public function testAdapterCannotPublishKnowledgeOrGrantExecutionAuthority(): void
    {
        $work = FactoryWorkItemAdapter::toWorkItem($this->aggregateGap(), $this->context());

        foreach (['provider', 'model', 'executor', 'publish', 'state', 'approval_ref', 'budget_ref'] as $field) {
            self::assertArrayNotHasKey($field, $work);
        }

        self::assertSame('automatic', $work['origin_mode']);
        self::assertSame('factory:queue-v1', $work['policy_ref']);
        self::assertSame(['docs/knowledge-support-gaps.md'], $work['claims']);
    }

    /** @return array<string, mixed> */
    private function aggregateGap(): array
    {
        $first = KnowledgeGap::fromArray($this->gapPayload());
        $second = KnowledgeGap::fromArray($this->gapPayload([
            'question' => 'no encuentro cómo cambiar la contraseña.',
            'source_ref' => 'support:human-escalation',
            'evidence_refs' => ['retrieval:handoff-001'],
            'observed_at' => 1_790_611_200,
            'outcome' => 'escalated',
        ]));

        return KnowledgeGap::aggregate([$first, $second])[0];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function gapPayload(array $overrides = []): array
    {
        return array_replace([
            'question' => 'No encuentro cómo cambiar la contraseña',
            'locale' => 'es-CO',
            'module' => 'admin',
            'scope' => 'tenant:acme',
            'source_ref' => 'support:chat-aggregate',
            'evidence_refs' => ['knowledge:password-reset'],
            'observed_at' => 1_790_611_000,
            'outcome' => 'unanswered',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function context(array $overrides = []): array
    {
        return array_replace([
            'group_id' => 'pl0n3r-group',
            'venture_id' => 'condor',
            'project_id' => 'condor',
            'repository_ref' => 'pl0n3r/Condor',
            'work_type' => 'knowledge_documentation',
            'requested_capabilities' => ['content', 'product'],
            'required_roles' => ['contenido', 'producto'],
            'authority_level' => 'operational',
            'producer_ref' => 'condor:knowledge-support',
            'priority_class' => 'medium',
            'claims' => ['docs/knowledge-support-gaps.md'],
            'policy_ref' => 'factory:queue-v1',
        ], $overrides);
    }
}
