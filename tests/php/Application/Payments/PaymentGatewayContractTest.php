<?php

declare(strict_types=1);

namespace App\Tests\Application\Payments;

use App\Application\Payments\Fake\FakePaymentGateway;
use App\Application\Payments\PaymentGateway;
use App\Application\Payments\PaymentGatewayRegistry;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PaymentGatewayContractTest extends TestCase
{
    private const TEST_KEY = 'synthetic-webhook-key-for-tests-only';

    public function testApprovedRejectedRefundAndInvalidWebhookSignature(): void
    {
        $gateway = new FakePaymentGateway(self::TEST_KEY);
        self::assertInstanceOf(PaymentGateway::class, $gateway);
        self::assertSame('sandbox', $gateway->mode());
        $approved = $gateway->charge(self::charge());
        self::assertSame('approved', $approved['status']);
        self::assertSame(155000, $approved['amount_minor']);
        self::assertSame('COP', $approved['currency']);
        self::assertSame('fake', $approved['ledger_context']['method']);
        self::assertSame('idem-alpha-01', $approved['ledger_context']['idempotency_key']);
        self::assertSame($approved, $gateway->charge(self::charge()));
        self::assertSame($approved, $gateway->query('tenant-alpha', 'company-alpha', $approved['charge_ref']));

        $rejected = $gateway->charge(self::charge('idem-rejected-01') + ['scenario' => 'reject']);
        self::assertSame('rejected', $rejected['status']);
        self::assertNull($rejected['ledger_context']);

        $refundRequest = [
            'tenant_id' => 'tenant-alpha', 'company_id' => 'company-alpha',
            'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155000,
            'idempotency_key' => 'refund-alpha-01',
        ];
        $refunded = $gateway->refund($refundRequest);
        self::assertSame('refunded', $refunded['status']);
        self::assertSame($refunded, $gateway->refund($refundRequest));
        self::assertSame('refunded', $gateway->query('tenant-alpha','company-alpha',$approved['charge_ref'])['status']);
        self::assertNull($gateway->charge(self::charge())['ledger_context']);

        $payload = json_encode(['event' => 'charge.updated', 'charge_ref' => $approved['charge_ref']], JSON_THROW_ON_ERROR);
        $signed = hash_hmac('sha256', $payload, self::TEST_KEY);
        self::assertTrue($gateway->verifyWebhook('tenant-alpha', 'company-alpha', $payload, $signed));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha', 'company-alpha', $payload, str_repeat('0', 64)));
        self::assertFalse($gateway->verifyWebhook('tenant-beta', 'company-alpha', $payload, $signed));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha', 'company-beta', $payload, $signed));
    }

    public function testCompanyRegistryRejectsUnauthorizedTenantCurrencyAndProvider(): void
    {
        $gateway = new FakePaymentGateway(self::TEST_KEY);
        $registry = new PaymentGatewayRegistry([$gateway], [
            'tenant-alpha/company-alpha' => ['providers' => ['fake'], 'currencies' => ['COP']],
            'tenant-beta/company-beta' => ['providers' => ['fake'], 'currencies' => ['USD']],
        ]);
        self::assertSame($gateway, $registry->forCompany('tenant-alpha','company-alpha','fake','COP'));
        self::assertSame(['fake'], $registry->available('tenant-alpha','company-alpha','COP'));
        self::assertSame([], $registry->available('tenant-alpha','company-alpha','USD'));
        foreach ([
            ['tenant-alpha','company-beta','fake','COP'],
            ['tenant-beta','company-beta','fake','COP'],
            ['tenant-alpha','company-alpha','not-enabled','COP'],
            ['tenant-alpha','company-alpha','fake','cop'],
        ] as [$tenant,$company,$provider,$currency]) {
            try {
                $registry->forCompany($tenant,$company,$provider,$currency);
                self::fail('Cross-scope or unconfigured gateway was accepted.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIdempotencyConflictsAndCrossTenantQueriesFailClosed(): void
    {
        $gateway = new FakePaymentGateway(self::TEST_KEY);
        $approved = $gateway->charge(self::charge());
        foreach ([
            [...self::charge(), 'amount_minor' => 255000],
            [...self::charge(), 'company_id' => 'company-beta'],
        ] as $changed) {
            if ($changed['company_id'] === 'company-beta') {
                self::assertSame('approved', $gateway->charge($changed)['status']);
                continue;
            }
            try {
                $gateway->charge($changed);
                self::fail('Replayed amount mismatch was allowed.');
            } catch (DomainException $e) {
                self::assertSame('GATEWAY_IDEMPOTENCY_CONFLICT', $e->getMessage());
            }
        }
        foreach ([
            ['tenant-beta', 'company-alpha'],
            ['tenant-alpha', 'company-beta'],
        ] as [$tenant,$company]) {
            try {
                $gateway->query($tenant, $company, $approved['charge_ref']);
                self::fail('Cross-scope charge query was allowed.');
            } catch (DomainException $e) {
                self::assertSame('GATEWAY_CHARGE_NOT_FOUND', $e->getMessage());
            }
        }
        $refund = ['tenant_id'=>'tenant-alpha', 'company_id'=>'company-alpha',
            'charge_ref'=>$approved['charge_ref'], 'amount_minor'=>155000,
            'idempotency_key'=>'refund-alpha-01'];
        $gateway->refund($refund);
        foreach ([
            [...$refund,'amount_minor'=>155001],
            [...$refund,'idempotency_key'=>'another-refund'],
        ] as $changed) {
            try {
                $gateway->refund($changed);
                self::fail('Conflicting or second refund was allowed.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNoSensitiveInputOrLiveGatewayCanBeActivated(): void
    {
        $gateway = new FakePaymentGateway(self::TEST_KEY);
        foreach ([
            [...self::charge(), 'card_number' => 'not-real'],
            [...self::charge(), 'amount_minor' => true],
            [...self::charge(), 'currency' => 'cop'],
            [...self::charge(), 'idempotency_key' => ''],
        ] as $invalid) {
            try {
                $gateway->charge($invalid);
                self::fail('Sensitive or invalid payload accepted.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
        try {
            new PaymentGatewayRegistry([new class implements PaymentGateway {
                public function id(): string { return 'live'; }
                public function mode(): string { return 'live'; }
                public function charge(array $request): array { return []; }
                public function query(string $tenantId, string $companyId, string $chargeRef): array { return []; }
                public function refund(array $request): array { return []; }
                public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool { return true; }
            }], []);
            self::fail('Non-sandbox gateway allowed.');
        } catch (DomainException $e) {
            self::assertSame('GATEWAY_SANDBOX_ONLY', $e->getMessage());
        }
    }

    /** @return array<string,int|string> */
    private static function charge(string $idempotencyKey='idem-alpha-01'): array
    {
        return [
            'tenant_id'=>'tenant-alpha', 'company_id'=>'company-alpha',
            'order_ref'=>'order-fixture-001', 'amount_minor'=>155000,
            'currency'=>'COP', 'idempotency_key'=>$idempotencyKey,
        ];
    }
}
