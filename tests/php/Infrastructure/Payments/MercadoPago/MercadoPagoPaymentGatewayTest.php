<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Payments\MercadoPago;

use App\Application\Payments\PaymentGatewayRegistry;
use App\Infrastructure\Payments\MercadoPago\MercadoPagoPaymentGateway;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Network-free synthetic scenarios. No customer data, real card or real API. */
final class MercadoPagoPaymentGatewayTest extends TestCase
{
    private const NOW = 1793373000;
    private const TOKEN = 'TEST-SYNTHETIC-ACCESS-TOKEN-000001';
    private const WEBHOOK = 'synthetic-webhook-secret-only-000001';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        $this->files = [];
    }

    private function secret(string $value): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mp-sandbox-');
        if ($file === false) throw new \RuntimeException('No synthetic fixture file');
        file_put_contents($file, $value."\n");
        chmod($file, 0600);
        $this->files[] = $file;
        return $file;
    }

    private function gateway(?\Closure $transport = null): MercadoPagoPaymentGateway
    {
        return new MercadoPagoPaymentGateway(
            $transport ?? self::transport(),
            $this->secret(self::TOKEN),
            $this->secret(self::WEBHOOK),
            static fn(): int => self::NOW,
        );
    }

    private static function transport(): \Closure
    {
        $payments = [];
        return static function (string $method, string $path, array $body, string $token, string $idem) use (&$payments): array {
            self::assertSame(self::TOKEN, $token);
            if ($method === 'POST' && $path === '/v1/payments') {
                self::assertSame(true, $body['sandbox']);
                self::assertTrue(str_starts_with($idem, 'charge-'));
                $id = (string) (1000000000 + count($payments));
                $payment = [
                    'id' => $id,
                    'status' => $body['external_reference'] === 'order-reject' ? 'rejected' : 'approved',
                    'transaction_amount' => $body['transaction_amount'],
                    'currency_id' => $body['currency_id'],
                    'external_reference' => $body['external_reference'],
                    'metadata' => $body['metadata'],
                    'sandbox' => true,
                ];
                $payments[$id] = $payment;
                return $payment;
            }
            if ($method === 'GET' && str_starts_with($path, '/v1/payments/')) {
                return $payments[substr($path, strlen('/v1/payments/'))] ?? [];
            }
            if ($method === 'POST' && preg_match('~^/v1/payments/([0-9]+)/refunds$~D', $path, $m) === 1) {
                self::assertSame(true, $body['sandbox']);
                self::assertTrue(str_starts_with($idem, 'refund-'));
                return ['id' => '9000000001', 'payment_id' => $m[1],
                    'amount' => $body['amount'], 'status' => 'approved', 'sandbox' => true];
            }
            throw new \RuntimeException('Unexpected sandbox double request');
        };
    }

    /** @return array<string,int|string> */
    private static function request(string $key = 'idem-approve-01', string $order = 'order-approve'): array
    {
        return ['tenant_id' => 'tenant-alpha', 'company_id' => 'company-alpha',
            'order_ref' => $order, 'amount_minor' => 155025,
            'currency' => 'COP', 'idempotency_key' => $key];
    }

    public function testApprovedRejectedRefundAndInvalidWebhookSignature(): void
    {
        $gateway = $this->gateway();
        self::assertSame('mercadopago', $gateway->id());
        self::assertSame('sandbox', $gateway->mode());
        $registry = new PaymentGatewayRegistry([$gateway], [
            'tenant-alpha/company-alpha' => ['providers' => ['mercadopago'], 'currencies' => ['COP']],
        ]);
        self::assertSame($gateway, $registry->forCompany('tenant-alpha','company-alpha','mercadopago','COP'));
        self::assertSame([], $registry->available('tenant-beta','company-alpha','COP'));

        $approved = $gateway->charge(self::request());
        self::assertSame('approved', $approved['status']);
        self::assertSame(155025, $approved['amount_minor']);
        self::assertSame('COP', $approved['currency']);
        self::assertSame('mercadopago_sandbox', $approved['ledger_context']['method']);
        self::assertSame('idem-approve-01', $approved['ledger_context']['idempotency_key']);
        self::assertSame($approved, $gateway->charge(self::request()));
        self::assertSame($approved, $gateway->query('tenant-alpha','company-alpha',$approved['charge_ref']));
        $rejected = $gateway->charge(self::request('idem-reject-01', 'order-reject'));
        self::assertSame('rejected', $rejected['status']);
        self::assertNull($rejected['ledger_context']);

        $payload = json_encode(['type' => 'payment', 'action' => 'payment.updated',
            'live_mode' => false, 'data' => ['id' => $approved['charge_ref']],
            'request_id' => 'req-id-123456'], JSON_THROW_ON_ERROR);
        $timestamp = (string)(self::NOW * 1000);
        $manifest = 'id:'.$approved['charge_ref'].';request-id:req-id-123456;ts:'.$timestamp.';';
        $signed = 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, self::WEBHOOK);
        self::assertTrue($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,$signed));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,'ts='.$timestamp.',v1='.str_repeat('0',64)));
        self::assertFalse($gateway->verifyWebhook('tenant-beta','company-alpha',$payload,$signed));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-beta',$payload,$signed));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',
            str_replace('req-id-123456', 'req-id-999999', $payload), $signed));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,
            'ts='.(self::NOW-301).',v1='.hash_hmac('sha256',
                'id:'.$approved['charge_ref'].';request-id:req-id-123456;ts:'.(self::NOW-301).';',self::WEBHOOK)));

        $refund = ['tenant_id' => 'tenant-alpha', 'company_id' => 'company-alpha',
            'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155025,
            'idempotency_key' => 'refund-alpha-01'];
        $refunded = $gateway->refund($refund);
        self::assertSame('refunded', $refunded['status']);
        self::assertSame(155025, $refunded['refunded_minor']);
        self::assertSame($refunded, $gateway->refund($refund));
        self::assertSame('refunded', $gateway->query('tenant-alpha','company-alpha',$approved['charge_ref'])['status']);
        self::assertNull($gateway->charge(self::request())['ledger_context']);
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$payload,$signed));
    }

    public function testScopeAndIdempotencyFailClosed(): void
    {
        $gateway = $this->gateway();
        $approved = $gateway->charge(self::request());
        foreach ([['tenant-beta','company-alpha'], ['tenant-alpha','company-beta']] as [$tenant,$company]) {
            try { $gateway->query($tenant,$company,$approved['charge_ref']); self::fail('Cross-scope query accepted'); }
            catch (DomainException $e) { self::assertSame('MP_CHARGE_NOT_FOUND', $e->getMessage()); }
        }
        foreach ([
            [...self::request(), 'amount_minor' => 155026],
            [...self::request(), 'amount_minor' => 0],
            [...self::request(), 'currency' => 'cop'],
            [...self::request(), 'card_number' => 'synthetic'],
            [...self::request(), 'order_ref' => 'new-order'],
        ] as $invalid) {
            try { $gateway->charge($invalid); self::fail('Invalid/idempotency charge accepted'); }
            catch (DomainException) { self::assertTrue(true); }
        }
        try { $gateway->charge(self::request('new-idem','order-approve')); self::fail('Duplicate order accepted'); }
        catch (DomainException $e) { self::assertSame('MP_ORDER_ALREADY_SUBMITTED', $e->getMessage()); }
        foreach ([['tenant_id' => 'tenant-beta'], ['amount_minor' => 1], ['amount_minor' => 155026]] as $changes) {
            $refund = array_replace(['tenant_id' => 'tenant-alpha', 'company_id' => 'company-alpha',
                'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155025,
                'idempotency_key' => 'refund-alpha-01'], $changes);
            try { $gateway->refund($refund); self::fail('Invalid refund accepted'); }
            catch (DomainException) { self::assertTrue(true); }
        }
    }

    public function testForgedProviderResponseAndSecretFileFailClosed(): void
    {
        $gateway = $this->gateway(static fn(string $m,string $p,array $body,string $key,string $idem): array => [
            'id' => '1000000000', 'status' => 'approved', 'transaction_amount' => '1550.25',
            'currency_id' => 'COP', 'external_reference' => 'order-approve',
            'metadata' => ['tenant_id' => 'tenant-beta', 'company_id' => 'company-alpha',
                'order_ref' => 'order-approve'],
            'sandbox' => true,
        ]);
        try { $gateway->charge(self::request()); self::fail('Cross-tenant provider reply accepted'); }
        catch (DomainException $e) { self::assertSame('MP_UNVERIFIABLE_PAYMENT', $e->getMessage()); }
        $token = $this->secret('APP_USR-00000000000000000000000000');
        $hook = $this->secret(self::WEBHOOK);
        try { new MercadoPagoPaymentGateway(self::transport(),$token,$hook); self::fail('Live key accepted'); }
        catch (DomainException $e) { self::assertSame('MP_SANDBOX_SECRET_INVALID', $e->getMessage()); }
        $insecure = $this->secret(self::TOKEN);
        chmod($insecure, 0644);
        try { new MercadoPagoPaymentGateway(self::transport(),$insecure,$hook); self::fail('World-readable token accepted'); }
        catch (DomainException $e) { self::assertSame('MP_PRIVATE_FILE_UNSAFE', $e->getMessage()); }
    }
}
