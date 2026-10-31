<?php

declare(strict_types=1);

use App\Application\Payments\PaymentGatewayRegistry;
use App\Infrastructure\Payments\ePayco\ePaycoPaymentGateway;
use PHPUnit\Framework\TestCase;

/** Exact named acceptance test for Condor #1437. */
final class Epaycopaymentgatewaytest extends TestCase
{
    private const CUSTOMER_ID = '123456';
    private const P_KEY = 'test_synthetic_epayco_only';

    /** @return array<string,int|string> */
    private static function request(string $key = 'epayco-key-1', string $order = 'epayco-order-1'): array
    {
        return [
            'tenant_id' => 'tenant-a', 'company_id' => 'company-a',
            'order_ref' => $order, 'amount_minor' => 155025,
            'currency' => 'COP', 'idempotency_key' => $key,
        ];
    }

    /** @param Closure(ePaycoPaymentGateway):void $check */
    private static function withGateway(Closure $transport, Closure $check): void
    {
        $file = tempnam(sys_get_temp_dir(), 'epayco-key-');
        self::assertNotFalse($file);
        try {
            self::assertNotFalse(file_put_contents($file, self::P_KEY));
            self::assertTrue(chmod($file, 0600));
            $check(new ePaycoPaymentGateway(self::CUSTOMER_ID, $transport, $file));
        } finally {
            @unlink($file);
        }
    }

    /** @param array<string,string> $fields */
    private static function signedForm(array $fields): array
    {
        $sig = hash('sha256', implode('^', [
            self::CUSTOMER_ID, self::P_KEY,
            $fields['x_ref_payco'], $fields['x_transaction_id'],
            $fields['x_amount'], $fields['x_currency_code'],
        ]));
        return [http_build_query($fields), $sig];
    }

    public function test_criterio_principal(): void
    {
        $calls = [];
        $transport = static function (string $action, array $data) use (&$calls): array {
            self::assertTrue($data['test']);
            $calls[] = $action;
            if ($action === 'refund') {
                return ['x_test_request' => 'TRUE', 'x_ref_payco' => 'synthetic-ref-001',
                    'refund_ref' => 'synthetic-refund-001', 'status' => 'approved'];
            }
            self::assertSame('charge', $action);
            self::assertSame('123456', $data['customer_id']);
            $reject = $data['scenario'] === 'reject';
            return [
                'x_ref_payco' => $reject ? 'synthetic-ref-002' : 'synthetic-ref-001',
                'x_transaction_id' => $reject ? 'synthetic-txn-002' : 'synthetic-txn-001',
                'x_id_invoice' => $data['invoice'], 'x_amount' => $data['amount'],
                'x_currency_code' => $data['currency'],
                'x_response' => $reject ? 'Rechazada' : 'Aceptada',
                'x_test_request' => 'TRUE',
            ];
        };
        self::withGateway($transport, static function (ePaycoPaymentGateway $gateway) use (&$calls): void {
            self::assertSame('epayco', $gateway->id());
            self::assertSame('sandbox', $gateway->mode());
            $registry = new PaymentGatewayRegistry([$gateway], [
                'tenant-a/company-a' => ['providers' => ['epayco'], 'currencies' => ['COP']],
            ]);
            self::assertSame(['epayco'], $registry->available('tenant-a', 'company-a', 'COP'));
            self::assertSame($gateway, $registry->forCompany('tenant-a', 'company-a', 'epayco', 'COP'));
            self::assertSame([], $registry->available('tenant-a', 'company-a', 'USD'));

            $approved = $gateway->charge(self::request());
            self::assertSame('approved', $approved['status']);
            self::assertSame('epayco_sandbox', $approved['ledger_context']['method']);
            self::assertSame($approved, $gateway->charge(self::request()));
            self::assertSame($approved, $gateway->query('tenant-a', 'company-a', $approved['charge_ref']));
            $rejected = $gateway->charge([...self::request('epayco-key-2', 'epayco-order-2'), 'scenario' => 'reject']);
            self::assertSame('rejected', $rejected['status']);
            self::assertNull($rejected['ledger_context']);

            $fields = [
                'x_ref_payco' => 'synthetic-ref-001',
                'x_transaction_id' => 'synthetic-txn-001',
                'x_id_invoice' => 'epayco-order-1', 'x_amount' => '1550.25',
                'x_currency_code' => 'COP', 'x_response' => 'Aceptada',
                'x_test_request' => 'TRUE', 'x_cust_id_cliente' => self::CUSTOMER_ID,
            ];
            [$body, $sig] = self::signedForm($fields);
            self::assertTrue($gateway->verifyWebhook('tenant-a', 'company-a', $body, $sig));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $body, str_repeat('0', 64)));
            self::assertFalse($gateway->verifyWebhook('tenant-b', 'company-a', $body, $sig));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-b', $body, $sig));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $body.'&x_amount=1550.25', $sig));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a',
                str_replace('1550.25', '1550.26', $body), $sig));
            [$liveBody, $liveSig] = self::signedForm([...$fields, 'x_test_request' => 'FALSE']);
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $liveBody, $liveSig));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a',
                http_build_query([...$fields, 'x_id_invoice' => 'another-invoice']), $sig));

            $refund = [
                'tenant_id' => 'tenant-a', 'company_id' => 'company-a',
                'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155025,
                'idempotency_key' => 'refund-key-1',
            ];
            $refunded = $gateway->refund($refund);
            self::assertSame('refunded', $refunded['status']);
            self::assertSame($refunded, $gateway->refund($refund));
            self::assertSame('refunded', $gateway->query('tenant-a', 'company-a', $approved['charge_ref'])['status']);
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $body, $sig));
            self::assertCount(3, $calls);
        });
    }

    public function test_invalid_inputs_scope_and_replays_fail_closed(): void
    {
        self::withGateway(static function (string $action, array $data): array {
            if ($action === 'refund') {
                return ['x_test_request' => 'FALSE', 'x_ref_payco' => 'synthetic-ref-001',
                    'refund_ref' => 'synthetic-refund-001', 'status' => 'approved'];
            }
            return ['x_ref_payco' => 'synthetic-ref-001', 'x_transaction_id' => 'synthetic-txn-001',
                'x_id_invoice' => $data['invoice'], 'x_amount' => $data['amount'],
                'x_currency_code' => $data['currency'], 'x_response' => 'Aceptada',
                'x_test_request' => 'TRUE'];
        }, static function (ePaycoPaymentGateway $gateway): void {
            $approved = $gateway->charge(self::request());
            foreach ([
                [...self::request(), 'amount_minor' => 155026],
                [...self::request(), 'card_number' => 'synthetic'],
                [...self::request(), 'amount_minor' => true],
                [...self::request('different-key'), 'order_ref' => 'epayco-order-1'],
            ] as $bad) {
                try {
                    $gateway->charge($bad);
                    self::fail('Invalid or duplicate charge accepted');
                } catch (DomainException) {
                    self::assertTrue(true);
                }
            }
            foreach ([['tenant-b', 'company-a'], ['tenant-a', 'company-b']] as [$tenant, $company]) {
                try {
                    $gateway->query($tenant, $company, $approved['charge_ref']);
                    self::fail('Cross-tenant query accepted');
                } catch (DomainException) {
                    self::assertTrue(true);
                }
            }
            $refund = ['tenant_id' => 'tenant-a', 'company_id' => 'company-a',
                'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155025,
                'idempotency_key' => 'refund-key-1'];
            try {
                $gateway->refund($refund);
                self::fail('Non-sandbox refund accepted');
            } catch (DomainException $e) {
                self::assertSame('EPAYCO_UNVERIFIABLE_REFUND', $e->getMessage());
            }
            self::assertSame('approved', $gateway->query('tenant-a', 'company-a', $approved['charge_ref'])['status']);
        });
    }

    public function test_missing_or_unsafe_credentials_are_rejected(): void
    {
        $transport = static fn (string $action, array $payload): array => [];
        foreach (['/nonexistent-epayco-private-key', null] as $path) {
            try {
                new ePaycoPaymentGateway(self::CUSTOMER_ID, $transport, $path);
                self::fail('Missing ePayco key accepted');
            } catch (DomainException $e) {
                self::assertSame('EPAYCO_SANDBOX_CREDENTIAL_UNAVAILABLE', $e->getMessage());
            }
        }
    }
}
