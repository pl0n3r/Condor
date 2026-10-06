<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiSensitiveHandoffRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiSensitiveHandoffRequestTest extends TestCase
{
    public function testSnapshotAcceptsOnlyCanonicalSensitiveIdentity(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'identity.permission.change',
            'knowledge_refs' => [],
        ], $policy);

        $request = AiSensitiveHandoffRequest::fromArray(
            $context,
            $policy,
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'identity.permission.change',
                'request_ref' => 'request:permission-review-001',
                'evidence_ref' => 'evidence:policy-check-001',
            ],
        );

        self::assertSame('tenant:tenant-a', $request->tenantRef());
        self::assertSame('identity.permission.change', $request->toolRef());
        self::assertSame('request:permission-review-001', $request->requestRef());
        self::assertSame('evidence:policy-check-001', $request->evidenceRef());
        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'identity.permission.change',
                'request_ref' => 'request:permission-review-001',
                'evidence_ref' => 'evidence:policy-check-001',
            ],
            $request->snapshot(),
        );
    }

    public function testInvalidCrossTenantNonSensitiveUnknownExtraOrFreeformMaterialFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $sensitiveContext = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'identity.permission.change',
            'knowledge_refs' => [],
        ], $policy);
        $readContext = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);

        $base = [
            'tenant_ref' => 'tenant:tenant-a',
            'tool_ref' => 'identity.permission.change',
            'request_ref' => 'request:permission-review-001',
            'evidence_ref' => 'evidence:policy-check-001',
        ];

        $cases = [
            [$sensitiveContext, array_replace($base, ['tenant_ref' => 'tenant:tenant-b'])],
            [$readContext, array_replace($base, ['tool_ref' => 'catalog.read'])],
            [$sensitiveContext, array_replace($base, ['tool_ref' => 'unknown.permission.change'])],
            [$sensitiveContext, $base + ['permission_id' => 'admin']],
            [$sensitiveContext, $base + ['note' => 'change permissions for user@example.test']],
            [$sensitiveContext, array_replace($base, ['evidence_ref' => 'user@example.test'])],
        ];

        foreach ($cases as [$context, $case]) {
            try {
                AiSensitiveHandoffRequest::fromArray($context, $policy, $case);
                self::fail('La solicitud sensible inválida debía fallar cerrada.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
