<?php

declare(strict_types=1);

use App\Application\Payments\PaymentGatewayRegistry;
use App\Infrastructure\Payments\PayPal\PayPalPaymentGateway;
use PHPUnit\Framework\TestCase;

/** Exact named acceptance test for Condor #1438; all fixtures synthetic. */
final class Paypalpaymentgatewaytest extends TestCase
{
    private const CLIENT = 'test_client_synthetic_only';
    private const SECRET = 'test_private_fake_secret_only';
    private const WEBHOOK_ID = 'test_webhook_synthetic_only';
    private const NOW = 1700000000;

    /** @return array<string,int|string> */
    private static function request(string $key = 'key-01', string $order = 'order-01'): array
    {
        return [
            'tenant_id' => 'tenant-a', 'company_id' => 'company-a', 'order_ref' => $order,
            'amount_minor' => 155025, 'currency' => 'USD', 'idempotency_key' => $key,
        ];
    }

    /** @return array<string,mixed> */
    private static function event(): array
    {
        return [
            'id' => 'WH-TEST-001', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'TESTCAPTURE001', 'custom_id' => 'tenant-a/company-a',
                'invoice_id' => 'order-01',
                'supplementary_data' => ['related_ids' => ['order_id' => 'TESTORDER001']],
                'amount' => ['currency_code' => 'USD', 'value' => '1550.25'],
            ],
        ];
    }

    private static function headers(string $transmission = 'TESTTX-001', string $signature = ''): string
    {
        return json_encode([
            'transmission_id' => $transmission,
            'transmission_time' => gmdate('Y-m-d\TH:i:s\Z', self::NOW),
            'cert_url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-TEST001A',
            'auth_algo' => 'SHA256withRSA',
            'transmission_sig' => $signature !== '' ? $signature : str_repeat('A', 40),
        ], JSON_THROW_ON_ERROR);
    }

    /** @param Closure(PayPalPaymentGateway):void $run */
    private static function withGateway(Closure $transport, Closure $run): void
    {
        $secretPath = tempnam(sys_get_temp_dir(), 'paypal-secret-');
        $webhookPath = tempnam(sys_get_temp_dir(), 'paypal-hook-');
        self::assertNotFalse($secretPath);
        self::assertNotFalse($webhookPath);
        try {
            self::assertNotFalse(file_put_contents($secretPath, self::SECRET));
            self::assertNotFalse(file_put_contents($webhookPath, self::WEBHOOK_ID));
            self::assertTrue(chmod($secretPath, 0600));
            self::assertTrue(chmod($webhookPath, 0600));
            $gateway = new PayPalPaymentGateway(self::CLIENT, $transport, $secretPath, $webhookPath,
                static fn (): int => self::NOW);
            $run($gateway);
        } finally {
            @unlink($secretPath);
            @unlink($webhookPath);
        }
    }

    public function test_criterio_principal(): void
    {
        $operations = [];
        $transport = static function (string $action, array $payload) use (&$operations): array {
            self::assertTrue($payload['sandbox']);
            self::assertSame(self::CLIENT, $payload['client_id']);
            self::assertSame(self::SECRET, $payload['client_secret']);
            $operations[] = $action;
            if ($action === 'verify_webhook') {
                self::assertSame('/v1/notifications/verify-webhook-signature', $payload['path']);
                self::assertSame(self::WEBHOOK_ID, $payload['webhook_id']);
                return ['verification_status' => $payload['transmission_sig'] === str_repeat('A', 40) ? 'SUCCESS' : 'FAILURE'];
            }
            if ($action === 'refund_capture') {
                self::assertSame('/v2/payments/captures/TESTCAPTURE001/refund', $payload['path']);
                return [
                    'sandbox' => true, 'id' => 'REFUNDTEST001', 'status' => 'COMPLETED',
                    'capture_id' => 'TESTCAPTURE001', 'amount' => $payload['amount'],
                ];
            }
            self::assertSame('create_order', $action);
            self::assertSame('/v2/checkout/orders', $payload['path']);
            $reject = $payload['scenario'] === 'reject';
            return [
                'sandbox' => true, 'id' => $reject ? 'TESTORDER002' : 'TESTORDER001',
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'invoice_id' => $payload['invoice_id'], 'custom_id' => $payload['custom_id'],
                    'payments' => ['captures' => [[
                        'id' => $reject ? 'TESTCAPTURE002' : 'TESTCAPTURE001',
                        'status' => $reject ? 'DECLINED' : 'COMPLETED',
                        'amount' => $payload['amount'],
                    ]]],
                ]],
            ];
        };
        self::withGateway($transport, static function (PayPalPaymentGateway $gateway) use (&$operations): void {
            self::assertSame('paypal', $gateway->id());
            self::assertSame('sandbox', $gateway->mode());
            $registry = new PaymentGatewayRegistry([$gateway], [
                'tenant-a/company-a' => ['providers' => ['paypal'], 'currencies' => ['USD']],
            ]);
            self::assertSame(['paypal'], $registry->available('tenant-a', 'company-a', 'USD'));
            self::assertSame([], $registry->available('tenant-b', 'company-a', 'USD'));
            self::assertSame($gateway, $registry->forCompany('tenant-a', 'company-a', 'paypal', 'USD'));
            $approved = $gateway->charge(self::request());
            self::assertSame('approved', $approved['status']);
            self::assertSame('paypal_sandbox', $approved['ledger_context']['method']);
            self::assertSame($approved, $gateway->charge(self::request()));
            self::assertSame($approved, $gateway->query('tenant-a', 'company-a', $approved['charge_ref']));
            $rejected = $gateway->charge([...self::request('key-02', 'order-02'), 'scenario' => 'reject']);
            self::assertSame('rejected', $rejected['status']);
            self::assertNull($rejected['ledger_context']);

            $event = json_encode(self::event(), JSON_THROW_ON_ERROR);
            self::assertFalse($gateway->verifyWebhook('tenant-b', 'company-a', $event, self::headers()));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-b', $event, self::headers()));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $event, self::headers('TESTTX-001', str_repeat('B', 40))));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a',
                json_encode([...self::event(), 'resource' => [...self::event()['resource'], 'invoice_id' => 'wrong']], JSON_THROW_ON_ERROR),
                self::headers()));
            self::assertTrue($gateway->verifyWebhook('tenant-a', 'company-a', $event, self::headers()));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $event, self::headers()));

            $refund = [
                'tenant_id' => 'tenant-a', 'company_id' => 'company-a',
                'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155025,
                'idempotency_key' => 'refund-01',
            ];
            $result = $gateway->refund($refund);
            self::assertSame('refunded', $result['status']);
            self::assertSame($result, $gateway->refund($refund));
            self::assertSame('refunded', $gateway->query('tenant-a', 'company-a', $approved['charge_ref'])['status']);
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $event, self::headers('TESTTX-002')));
            self::assertSame(['create_order','create_order','verify_webhook','verify_webhook','refund_capture'], $operations);
        });
    }

    public function test_bad_inputs_live_and_scope_fail_closed(): void
    {
        self::withGateway(static function (string $action, array $payload): array {
            if ($action !== 'create_order') return ['verification_status' => 'FAILURE'];
            return ['sandbox' => false, 'id' => 'TESTORDER001', 'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'invoice_id' => $payload['invoice_id'], 'custom_id' => $payload['custom_id'],
                    'payments' => ['captures' => [['id' => 'TESTCAPTURE001',
                        'status' => 'COMPLETED', 'amount' => $payload['amount']]]],
                ]],
            ];
        }, static function (PayPalPaymentGateway $gateway): void {
            foreach ([
                self::request(),
                [...self::request(), 'currency' => 'COP'],
                [...self::request(), 'amount_minor' => true],
                [...self::request(), 'card' => 'synthetic-card-never-allowed'],
            ] as $bad) {
                try {
                    $gateway->charge($bad);
                    self::fail('Unsafe or live request accepted');
                } catch (DomainException) {
                    self::assertTrue(true);
                }
            }
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', '{}', '{}'));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', '{}', self::headers()));
        });
    }

    public function test_missing_or_real_credentials_rejected(): void
    {
        $transport = static fn (string $action, array $params): array => [];
        foreach ([null, '/missing-paypal-test-secret'] as $path) {
            try {
                new PayPalPaymentGateway(self::CLIENT, $transport, $path, $path);
                self::fail('Missing private credentials accepted');
            } catch (DomainException $e) {
                self::assertSame('PAYPAL_SANDBOX_CREDENTIAL_UNAVAILABLE', $e->getMessage());
            }
        }
        $this->expectException(DomainException::class);
        new PayPalPaymentGateway('live_paypal_client', $transport);
    }
}
