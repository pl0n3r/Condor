<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Payments\Stripe;

use App\Application\Payments\PaymentGatewayRegistry;
use App\Infrastructure\Payments\Stripe\StripePaymentGateway;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Exercises the real PHP adapter with a deterministic, network-free Stripe double. */
final class StripePaymentGatewayTest extends TestCase
{
    private const NOW = 1793373000;
    private const WEBHOOK_SECRET = 'whsec_00000000000000000000000000000000';
    /** @var list<string> */
    private array $privateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->privateFiles as $path) @unlink($path);
        putenv('STRIPE_SECRET_KEY_FILE');
        putenv('STRIPE_WEBHOOK_SECRET_FILE');
    }

    private function gateway(?callable $transport = null): StripePaymentGateway
    {
        foreach (['STRIPE_SECRET_KEY_FILE' => 'sk_test_00000000000000000000000000000000',
            'STRIPE_WEBHOOK_SECRET_FILE' => self::WEBHOOK_SECRET] as $name => $secret) {
            $path = tempnam(sys_get_temp_dir(), 'stripe_fixture_');
            if ($path === false) throw new \RuntimeException('Unable to create synthetic secret fixture');
            file_put_contents($path, $secret."\n");
            chmod($path, 0600);
            putenv($name.'='.$path);
            $this->privateFiles[] = $path;
        }
        return new StripePaymentGateway($transport ?? $this->transport(), static fn(): int => self::NOW);
    }

    private function transport(): callable
    {
        $intents = [];
        return static function (string $method, string $path, array $data, string $secret, string $idem) use (&$intents): array {
            if (!str_starts_with($secret, 'sk_test_')) throw new \RuntimeException('Non-test key');
            if ($method === 'POST' && $path === '/v1/payment_intents') {
                $id = 'pi_'.substr(hash('sha256', $idem), 0, 20);
                $intents[$id] = [
                    'id' => $id, 'object' => 'payment_intent', 'livemode' => false,
                    'status' => $data['payment_method'] === 'pm_card_visa' ? 'succeeded' : 'requires_payment_method',
                    'amount' => $data['amount'], 'currency' => $data['currency'], 'metadata' => $data['metadata'],
                ];
                return $intents[$id];
            }
            if ($method === 'GET' && str_starts_with($path, '/v1/payment_intents/')) {
                return $intents[substr($path, strlen('/v1/payment_intents/'))] ?? ['error' => ['type' => 'invalid_request_error']];
            }
            if ($method === 'POST' && $path === '/v1/refunds') {
                return [
                    'id' => 're_'.substr(hash('sha256', $idem), 0, 20), 'object' => 'refund', 'livemode' => false,
                    'status' => 'succeeded', 'amount' => $data['amount'],
                    'payment_intent' => $data['payment_intent'], 'metadata' => $data['metadata'],
                ];
            }
            throw new \RuntimeException('Unexpected Stripe request');
        };
    }

    /** @return array<string,int|string> */
    private static function request(string $key = 'idem-alpha-01'): array
    {
        return ['tenant_id' => 'tenant-alpha', 'company_id' => 'company-alpha',
            'order_ref' => 'order-fixture-01', 'amount_minor' => 155000,
            'currency' => 'COP', 'idempotency_key' => $key];
    }

    public function testApprovedRejectedRefundAndInvalidWebhookSignature(): void
    {
        $gateway = $this->gateway();
        self::assertSame('stripe', $gateway->id());
        self::assertSame('sandbox', $gateway->mode());
        $registry = new PaymentGatewayRegistry([$gateway], [
            'tenant-alpha/company-alpha' => ['providers' => ['stripe'], 'currencies' => ['COP']],
        ]);
        self::assertSame($gateway, $registry->forCompany('tenant-alpha','company-alpha','stripe','COP'));
        self::assertSame([], $registry->available('tenant-beta','company-alpha','COP'));
        $charge = $gateway->charge(self::request());
        self::assertSame('approved', $charge['status']);
        self::assertSame(155000, $charge['amount_minor']);
        self::assertSame('stripe', $charge['ledger_context']['method']);
        self::assertSame($charge, $gateway->charge(self::request()));
        self::assertSame($charge, $gateway->query('tenant-alpha','company-alpha',$charge['charge_ref']));
        $rejected = $gateway->charge(self::request('idem-rejected-01') + ['scenario' => 'reject']);
        self::assertSame('rejected', $rejected['status']);
        self::assertNull($rejected['ledger_context']);

        $refund = ['tenant_id' => 'tenant-alpha','company_id' => 'company-alpha',
            'charge_ref' => $charge['charge_ref'],'amount_minor' => 155000,
            'idempotency_key' => 'refund-alpha-01'];
        $refunded = $gateway->refund($refund);
        self::assertSame('refunded', $refunded['status']);
        self::assertSame(155000, $refunded['refunded_minor']);
        self::assertSame($refunded, $gateway->refund($refund));
        self::assertSame('refunded', $gateway->query('tenant-alpha','company-alpha',$charge['charge_ref'])['status']);
        self::assertNull($gateway->charge(self::request())['ledger_context']);

        $payload = json_encode(['id' => 'evt_test_001','type' => 'payment_intent.succeeded',
            'livemode' => false, 'data' => ['object' => ['id' => $charge['charge_ref'],
                'livemode' => false, 'metadata' => ['tenant_id' => 'tenant-alpha',
                    'company_id' => 'company-alpha']]]], JSON_THROW_ON_ERROR);
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$payload, self::WEBHOOK_SECRET);
        self::assertTrue($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,$signature));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,$signature.'0'));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,'t='.self::NOW.',v1='.str_repeat('0', 64)));
        self::assertFalse($gateway->verifyWebhook('tenant-beta','company-alpha',$payload,$signature));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-beta',$payload,$signature));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,'t='.(self::NOW-301).',v1='.hash_hmac('sha256', (self::NOW-301).'.'.$payload, self::WEBHOOK_SECRET)));
    }

    public function testFailClosedOnCrossTenantAmbiguityAndNonTestKeys(): void
    {
        $gateway = $this->gateway();
        $approved = $gateway->charge(self::request());
        foreach ([['tenant-beta','company-alpha'], ['tenant-alpha','company-beta']] as [$tenant, $company]) {
            try { $gateway->query($tenant, $company, $approved['charge_ref']); self::fail('Cross-tenant query passed'); }
            catch (DomainException $e) { self::assertSame('STRIPE_UNVERIFIABLE_RESPONSE', $e->getMessage()); }
        }
        foreach ([
            [...self::request(), 'amount_minor' => 0],
            [...self::request(), 'amount_minor' => 155001],
            [...self::request(), 'card_number' => 'not-real'],
            [...self::request(), 'currency' => 'cop'],
        ] as $invalid) {
            try { $gateway->charge($invalid); self::fail('Invalid charge accepted'); }
            catch (DomainException) { self::assertTrue(true); }
        }
        $keyFile = getenv('STRIPE_SECRET_KEY_FILE');
        if (!is_string($keyFile)) throw new \RuntimeException('Missing fixture path');
        file_put_contents($keyFile, 'sk_live_FORBIDDEN_TEST_KEY_0000000000');
        try { new StripePaymentGateway($this->transport()); self::fail('Live key accepted'); }
        catch (DomainException $e) { self::assertSame('STRIPE_SANDBOX_SECRET_INVALID', $e->getMessage()); }
    }
}
