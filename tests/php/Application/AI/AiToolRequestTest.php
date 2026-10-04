<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolRequestTest extends TestCase
{
    public function testRequestBindsTenantContextToolAndOpaqueReference(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);

        $request = AiToolRequest::fromArray(
            $context,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'request_ref' => 'request:catalog-lookup-001',
            ],
        );

        self::assertSame('tenant-a', $request->tenantId());
        self::assertSame('catalog.read', $request->tool());
        self::assertSame('request:catalog-lookup-001', $request->requestRef());
        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'catalog.read',
                'request_ref' => 'request:catalog-lookup-001',
            ],
            $request->snapshot(),
        );
    }

    public function testUnknownToolCrossTenantOrPersonalPayloadFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);

        $cases = [
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'unknown.read',
                'request_ref' => 'request:unknown',
            ],
            [
                'tenant_id' => 'tenant-b',
                'tool' => 'catalog.read',
                'request_ref' => 'request:cross-tenant',
            ],
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'request_ref' => 'request:with-payload',
                'customer_email' => 'person@example.test',
            ],
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'request_ref' => 'request:with-payload',
                'payload' => ['free_form' => 'secret'],
            ],
        ];

        foreach ($cases as $case) {
            try {
                AiToolRequest::fromArray($context, $policy, $case);
                self::fail('Invalid request must fail closed.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
