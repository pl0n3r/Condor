<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\BillingAdapter;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BillingAdapterContractTest extends TestCase
{
    public function testProviderNeutralRequestAndResultAreDeterministic(): void
    {
        $request = BillingRequest::fromArray([
            'tenant_ref' => 'tenant:synthetic-a',
            'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
            'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
            'amount_minor' => 199900,
            'currency' => 'COP',
            'idempotency_key' => 'billing:tenant-a:2026-10',
        ]);

        $adapter = new class implements BillingAdapter {
            public function submit(BillingRequest $request): BillingResult
            {
                return BillingResult::fromArray([
                    'outcome' => 'prepared',
                    'provider_ref' => null,
                    'evidence_ref' => 'billing:evidence:' . $request->idempotencyKey(),
                ]);
            }
        };

        $first = $adapter->submit($request);
        $second = $adapter->submit(BillingRequest::fromArray($request->toArray()));

        self::assertSame($request->toArray(), BillingRequest::fromArray($request->toArray())->toArray());
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('prepared', $first->outcome());
        self::assertNull($first->providerRef());
    }

    public function testSensitiveProviderSpecificOrUnknownPayloadFailsClosed(): void
    {
        $valid = [
            'tenant_ref' => 'tenant:synthetic-a',
            'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
            'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
            'amount_minor' => 199900,
            'currency' => 'COP',
            'idempotency_key' => 'billing:tenant-a:2026-10',
        ];

        foreach ([
            $valid + ['email' => 'person@example.com'],
            $valid + ['token' => 'secret'],
            $valid + ['provider' => 'vendor-a'],
            $valid + ['card_number' => '4111111111111111'],
        ] as $payload) {
            try {
                BillingRequest::fromArray($payload);
                self::fail('Payload fuera de contrato aceptado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        foreach ([
            ['outcome' => 'paid', 'provider_ref' => null, 'evidence_ref' => 'billing:evidence:test'],
            ['outcome' => 'unknown', 'provider_ref' => 'vendor@example.com', 'evidence_ref' => 'billing:evidence:test'],
            ['outcome' => 'accepted', 'provider_ref' => null, 'evidence_ref' => 'token=secret'],
        ] as $payload) {
            try {
                BillingResult::fromArray($payload);
                self::fail('Resultado fuera de contrato aceptado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
