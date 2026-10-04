<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolDecision;
use App\Application\AI\AiToolReceipt;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolReceiptTest extends TestCase
{
    public function testReceiptBindsAuthorizedRequestOutcomeAndAuditEvidence(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);
        $request = [
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'request_ref' => 'request:receipt-001',
        ];
        $decision = AiToolDecision::decide($context, $policy, $request);

        $receipt = AiToolReceipt::fromArray(
            $context,
            $policy,
            [
                'request' => $decision['request'],
                'decision' => $decision,
                'audit' => [
                    'tenant_ref' => 'tenant:tenant-a',
                    'tool_ref' => 'catalog.read',
                    'outcome' => 'success',
                    'evidence_ref' => 'evidence:receipt-001',
                    'timestamp' => '2026-10-03T21:00:00+00:00',
                ],
            ],
        );

        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'catalog.read',
                'request_ref' => 'request:receipt-001',
                'decision' => 'authorized',
                'risk' => AiToolPolicy::READ_ONLY,
                'outcome' => 'success',
                'evidence_ref' => 'evidence:receipt-001',
                'timestamp' => '2026-10-03T21:00:00+00:00',
            ],
            $receipt->snapshot(),
        );
    }

    public function testMismatchOrRawPayloadFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);
        $decision = AiToolDecision::decide(
            $context,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'request_ref' => 'request:receipt-001',
            ],
        );

        $valid = [
            'request' => $decision['request'],
            'decision' => $decision,
            'audit' => [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'catalog.read',
                'outcome' => 'success',
                'evidence_ref' => 'evidence:receipt-001',
                'timestamp' => '2026-10-03T21:00:00+00:00',
            ],
        ];

        $cases = [];

        $crossTenant = $valid;
        $crossTenant['request']['tenant_ref'] = 'tenant:tenant-b';
        $cases[] = $crossTenant;

        $toolMismatch = $valid;
        $toolMismatch['audit']['tool_ref'] = 'inventory.read';
        $cases[] = $toolMismatch;

        $requestMismatch = $valid;
        $requestMismatch['request']['request_ref'] = 'request:other';
        $cases[] = $requestMismatch;

        $rawInput = $valid;
        $rawInput['raw_input'] = ['customer_email' => 'person@example.test'];
        $cases[] = $rawInput;

        $rawOutput = $valid;
        $rawOutput['audit']['raw_output'] = ['secret' => 'must-not-leak'];
        $cases[] = $rawOutput;

        $deniedDecision = $valid;
        $deniedDecision['decision']['status'] = 'denied';
        $cases[] = $deniedDecision;

        foreach ($cases as $case) {
            try {
                AiToolReceipt::fromArray($context, $policy, $case);
                self::fail('Inconsistent or raw receipt data must fail closed.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
