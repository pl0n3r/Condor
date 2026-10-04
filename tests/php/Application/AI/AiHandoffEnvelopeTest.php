<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiHandoffEnvelope;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiHandoffEnvelopeTest extends TestCase
{
    public function testSnapshotBindsContextRouteReasonAndMinimalEvidence(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'knowledge.read',
            'knowledge_refs' => ['knowledge:guide'],
        ], $policy);

        $envelope = AiHandoffEnvelope::fromArray(
            $context,
            [
                'tenant_ref' => 'tenant:tenant-a',
                'route' => 'knowledge',
                'reason' => 'evidence_insufficient',
                'evidence_refs' => ['knowledge:guide'],
            ],
        );

        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'route' => 'knowledge',
                'reason' => 'evidence_insufficient',
                'evidence_refs' => ['knowledge:guide'],
            ],
            $envelope->snapshot(),
        );
    }

    public function testCrossTenantRawOrInvalidEvidenceFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);

        $cases = [
            [
                'tenant_ref' => 'tenant:tenant-b',
                'route' => 'tool',
                'reason' => 'tool_failed',
                'evidence_refs' => [],
            ],
            [
                'tenant_ref' => 'tenant:tenant-a',
                'route' => 'tool',
                'reason' => 'tool_failed',
                'evidence_refs' => ['person@example.test'],
            ],
            [
                'tenant_ref' => 'tenant:tenant-a',
                'route' => 'external',
                'reason' => 'tool_failed',
                'evidence_refs' => [],
            ],
            [
                'tenant_ref' => 'tenant:tenant-a',
                'route' => 'tool',
                'reason' => 'tool_failed',
                'evidence_refs' => [],
                'raw_conversation' => ['secret' => 'must-not-leak'],
            ],
        ];

        foreach ($cases as $case) {
            try {
                AiHandoffEnvelope::fromArray($context, $case);
                self::fail('Handoff inconsistente o raw debe fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
