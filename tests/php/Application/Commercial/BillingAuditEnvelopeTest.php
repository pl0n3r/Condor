<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\BillingAuditEnvelope;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BillingAuditEnvelopeTest extends TestCase
{
    public function testEnvelopeKeepsOnlyMinimizedCanonicalEvidence(): void
    {
        $request = self::request();
        $result = new BillingResult(
            'accepted',
            'processor:synthetic-001',
            'billing:evidence:accepted-001',
        );

        $payload = BillingAuditEnvelope::fromContracts(
            $request,
            $result,
            '2026-10-03T09:20:00.000000Z',
        )->toArray();

        self::assertSame([
            'tenant_ref',
            'idempotency_key',
            'request_fingerprint',
            'outcome',
            'provider_ref',
            'evidence_ref',
            'observed_at',
        ], array_keys($payload));
        self::assertSame('tenant:synthetic-a', $payload['tenant_ref']);
        self::assertSame('billing:tenant-a:2026-10', $payload['idempotency_key']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $payload['request_fingerprint']);
        self::assertSame('accepted', $payload['outcome']);
        self::assertSame('processor:synthetic-001', $payload['provider_ref']);
        self::assertSame('billing:evidence:accepted-001', $payload['evidence_ref']);
        self::assertSame('2026-10-03T09:20:00.000000Z', $payload['observed_at']);

        self::assertSame(
            $payload,
            BillingAuditEnvelope::fromContracts(
                BillingRequest::fromArray($request->toArray()),
                BillingResult::fromArray($result->toArray()),
                '2026-10-03T09:20:00.000000Z',
            )->toArray(),
        );
    }

    public function testSensitivePaymentOrFreeFormPayloadFailsClosed(): void
    {
        $valid = BillingAuditEnvelope::fromContracts(
            self::request(),
            new BillingResult('unknown', null, 'billing:evidence:unknown-001'),
            '2026-10-03T09:20:00.000000Z',
        )->toArray();

        $invalidPayloads = [
            [...$valid, 'note' => 'synthetic'],
            [...$valid, 'email' => 'synthetic'],
            [...$valid, 'pan' => 'synthetic'],
            [...$valid, 'cvv' => 'synthetic'],
            [...$valid, 'secret' => 'synthetic'],
            [...$valid, 'provider_payload' => ['status' => 'synthetic']],
            [...$valid, 'tenant_ref' => 'email:synthetic'],
            [...$valid, 'idempotency_key' => 'provider:synthetic-key'],
            [...$valid, 'provider_ref' => 'token:synthetic'],
            [...$valid, 'request_fingerprint' => 'not-a-sha256'],
            [...$valid, 'observed_at' => '2026-10-03 09:20:00'],
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                BillingAuditEnvelope::fromArray($payload);
                self::fail('Invalid billing audit payload was accepted.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    private static function request(): BillingRequest
    {
        return BillingRequest::fromArray([
            'tenant_ref' => 'tenant:synthetic-a',
            'subscription_ref' => 'subscription:synthetic-a',
            'quote_ref' => 'quote:synthetic-a',
            'amount_minor' => 199900,
            'currency' => 'COP',
            'idempotency_key' => 'billing:tenant-a:2026-10',
        ]);
    }
}
