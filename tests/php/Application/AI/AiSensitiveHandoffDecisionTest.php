<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiSensitiveHandoffDecision;
use App\Application\AI\AiToolDecision;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiSensitiveHandoffDecisionTest extends TestCase
{
    public function testSensitiveDenialBuildsMinimizedHumanReviewSnapshot(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'identity.permission.change',
            'knowledge_refs' => [],
        ], $policy);

        $decision = AiToolDecision::decide(
            $context,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'identity.permission.change',
                'request_ref' => 'request:permission-review-001',
            ],
        );

        $handoff = AiSensitiveHandoffDecision::fromDecision(
            $context,
            $policy,
            $decision,
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'identity.permission.change',
                'request_ref' => 'request:permission-review-001',
                'evidence_ref' => 'evidence:policy-check-001',
            ],
        );

        self::assertSame(
            [
                'status' => 'denied',
                'reason' => 'sensitive_requires_human',
                'executed' => false,
                'risk' => 'sensitive',
                'request' => [
                    'tenant_ref' => 'tenant:tenant-a',
                    'tool_ref' => 'identity.permission.change',
                    'request_ref' => 'request:permission-review-001',
                    'evidence_ref' => 'evidence:policy-check-001',
                ],
            ],
            $handoff->snapshot(),
        );
    }

    public function testInvalidDecisionOrRequestFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'identity.permission.change',
            'knowledge_refs' => [],
        ], $policy);

        $decision = AiToolDecision::decide(
            $context,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'identity.permission.change',
                'request_ref' => 'request:permission-review-001',
            ],
        );
        $request = [
            'tenant_ref' => 'tenant:tenant-a',
            'tool_ref' => 'identity.permission.change',
            'request_ref' => 'request:permission-review-001',
            'evidence_ref' => 'evidence:policy-check-001',
        ];

        $invalidDecisions = [
            array_replace($decision, ['status' => 'authorized']),
            array_replace($decision, ['reason' => 'policy_allows']),
            array_replace($decision, ['executed' => true]),
            array_replace($decision, ['risk' => 'read_only']),
            array_replace($decision, ['request' => null]),
            $decision + ['approval_token' => 'opaque'],
        ];

        foreach ($invalidDecisions as $invalidDecision) {
            try {
                AiSensitiveHandoffDecision::fromDecision(
                    $context,
                    $policy,
                    $invalidDecision,
                    $request,
                );
                self::fail('La decisión sensible inválida debía fallar cerrada.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $invalidRequests = [
            array_replace($request, ['tenant_ref' => 'tenant:tenant-b']),
            array_replace($request, ['request_ref' => 'request:other-review']),
            array_replace($request, ['evidence_ref' => 'user@example.test']),
            $request + ['permission_id' => 'admin'],
        ];

        foreach ($invalidRequests as $invalidRequest) {
            try {
                AiSensitiveHandoffDecision::fromDecision(
                    $context,
                    $policy,
                    $decision,
                    $invalidRequest,
                );
                self::fail('La solicitud sensible inválida debía fallar cerrada.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
