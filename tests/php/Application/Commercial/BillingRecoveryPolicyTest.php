<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\BillingReconciliationEvidence;
use App\Application\Commercial\BillingRecoveryPolicy;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BillingRecoveryPolicyTest extends TestCase
{
    public function testUnknownRequiresExplicitReconciliation(): void
    {
        $policy = new BillingRecoveryPolicy();
        $unknown = new BillingResult(
            'unknown',
            'processor:synthetic-001',
            'billing:evidence:unknown-001',
        );

        self::assertSame('reconciliation_required', $policy->actionFor($unknown));
        self::assertSame('terminal', $policy->actionFor(new BillingResult(
            'accepted',
            'processor:synthetic-001',
            'billing:evidence:accepted-001',
        )));
        self::assertSame('prepared', $policy->actionFor(new BillingResult(
            'prepared',
            null,
            'billing:evidence:prepared-001',
        )));

        $resolved = $policy->reconcile(
            self::request(),
            $unknown,
            BillingReconciliationEvidence::fromArray([
                'idempotency_key' => 'billing:tenant-a:2026-10',
                'outcome' => 'accepted',
                'evidence_ref' => 'billing:reconciliation:accepted-001',
                'observed_at' => '2026-10-03T09:10:00.000000Z',
            ]),
        );

        self::assertSame('accepted', $resolved->outcome());
        self::assertSame('processor:synthetic-001', $resolved->providerRef());
        self::assertSame(
            'billing:reconciliation:accepted-001',
            $resolved->evidenceRef(),
        );
    }

    public function testMismatchedOrAmbiguousReconciliationFailsClosed(): void
    {
        $policy = new BillingRecoveryPolicy();
        $unknown = new BillingResult(
            'unknown',
            null,
            'billing:evidence:unknown-001',
        );

        try {
            $policy->reconcile(
                self::request(),
                $unknown,
                BillingReconciliationEvidence::fromArray([
                    'idempotency_key' => 'billing:other-tenant:2026-10',
                    'outcome' => 'rejected',
                    'evidence_ref' => 'billing:reconciliation:rejected-001',
                    'observed_at' => '2026-10-03T09:10:00.000000Z',
                ]),
            );
            self::fail('Mismatched reconciliation was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('billing_reconciliation_mismatch', $exception->getMessage());
        }

        foreach ([
            [
                'idempotency_key' => 'billing:tenant-a:2026-10',
                'outcome' => 'unknown',
                'evidence_ref' => 'billing:reconciliation:ambiguous-001',
                'observed_at' => '2026-10-03T09:10:00.000000Z',
            ],
            [
                'idempotency_key' => 'billing:tenant-a:2026-10',
                'outcome' => 'accepted',
                'evidence_ref' => 'billing:reconciliation:accepted-001',
                'observed_at' => '2026-10-03T09:10:00.000000Z',
                'note' => 'free-form data is forbidden',
            ],
            [
                'idempotency_key' => 'billing:tenant-a:2026-10',
                'outcome' => 'accepted',
                'evidence_ref' => 'email:person@example.com',
                'observed_at' => '2026-10-03T09:10:00.000000Z',
            ],
        ] as $payload) {
            try {
                BillingReconciliationEvidence::fromArray($payload);
                self::fail('Invalid reconciliation evidence was accepted.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    private static function request(): BillingRequest
    {
        return BillingRequest::fromArray([
            'tenant_ref' => 'tenant:synthetic-a',
            'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
            'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
            'amount_minor' => 199900,
            'currency' => 'COP',
            'idempotency_key' => 'billing:tenant-a:2026-10',
        ]);
    }
}
