<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Payments\PayU;

use App\Application\Payments\PaymentGatewayRegistry;
use App\Infrastructure\Payments\PayU\PayUPaymentGateway;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PayUPaymentGatewayTest extends TestCase
{
    private const MERCHANT = '508029';
    private const ACCOUNT = '512321';
    private const TEST_API_KEY = 'private-synthetic-key-only';
    private const TEST_LOGIN = 'private-synthetic-login-only';

    /** @return array<string,int|string> */
    private static function charge(string $key = 'idem-payu-001', string $order = 'sale-payu-001'): array
    {
        return [
            'tenant_id' => 'tenant-a', 'company_id' => 'company-a',
            'order_ref' => $order, 'amount_minor' => 155025,
            'currency' => 'COP', 'idempotency_key' => $key,
        ];
    }

    /**
     * @param \Closure(string,array<string,mixed>):array<string,mixed> $transport
     * @param \Closure(PayUPaymentGateway):void $run
     */
    private static function withGateway(\Closure $transport, \Closure $run): void
    {
        $key = tempnam(sys_get_temp_dir(), 'payu-key-');
        $login = tempnam(sys_get_temp_dir(), 'payu-login-');
        if ($key === false || $login === false) {
            throw new \RuntimeException('Unable to create synthetic secret files');
        }
        try {
            file_put_contents($key, self::TEST_API_KEY);
            file_put_contents($login, self::TEST_LOGIN);
            chmod($key, 0600);
            chmod($login, 0600);
            $gateway = new PayUPaymentGateway(self::MERCHANT, self::ACCOUNT, $transport, $key, $login);
            $run($gateway);
        } finally {
            @unlink($key);
            @unlink($login);
        }
    }

    public function testApprovedRejectedRefundedAndInvalidSignature(): void
    {
        $calls = [];
        $transport = static function (string $url, array $request) use (&$calls): array {
            self::assertSame(PayUPaymentGateway::SANDBOX_URL, $url);
            self::assertSame(true, $request['test']);
            self::assertSame('SUBMIT_TRANSACTION', $request['command']);
            self::assertSame('private-synthetic-key-only', $request['merchant']['apiKey']);
            self::assertSame('private-synthetic-login-only', $request['merchant']['apiLogin']);
            $calls[] = $request['transaction'];
            if (($request['transaction']['type'] ?? '') === 'REFUND') {
                self::assertSame('test-transaction-001', $request['transaction']['parentTransactionId']);
                return ['transactionResponse' => ['state' => 'APPROVED']];
            }
            return ['transactionResponse' => [
                'state' => $request['transaction']['order']['referenceCode'] === 'sale-payu-rejected'
                    ? 'DECLINED' : 'APPROVED',
                'transactionId' => $request['transaction']['order']['referenceCode'] === 'sale-payu-rejected'
                    ? 'test-transaction-002' : 'test-transaction-001',
            ]];
        };
        self::withGateway($transport, static function (PayUPaymentGateway $gateway) use (&$calls): void {
            self::assertSame('payu', $gateway->id());
            self::assertSame('sandbox', $gateway->mode());
            $registry = new PaymentGatewayRegistry([$gateway], [
                'tenant-a/company-a' => ['providers' => ['payu'], 'currencies' => ['COP']],
                'tenant-b/company-b' => ['providers' => ['payu'], 'currencies' => ['USD']],
            ]);
            self::assertSame(['payu'], $registry->available('tenant-a', 'company-a', 'COP'));
            self::assertSame([], $registry->available('tenant-a', 'company-a', 'USD'));
            self::assertSame($gateway, $registry->forCompany('tenant-a', 'company-a', 'payu', 'COP'));

            $approved = $gateway->charge(self::charge());
            self::assertSame('approved', $approved['status']);
            self::assertSame('COP', $approved['currency']);
            self::assertSame('payu_sandbox', $approved['ledger_context']['method']);
            self::assertSame('1550.25', $calls[0]['order']['additionalValues']['TX_VALUE']['value']);
            self::assertSame($approved, $gateway->charge(self::charge()));
            self::assertSame(1, count($calls));
            self::assertSame($approved, $gateway->query('tenant-a', 'company-a', $approved['charge_ref']));
            $declined = $gateway->charge(self::charge('idem-payu-rejected', 'sale-payu-rejected'));
            self::assertSame('rejected', $declined['status']);
            self::assertNull($declined['ledger_context']);

            $signParts = [self::TEST_API_KEY, self::MERCHANT, 'sale-payu-001', '1550.25', 'COP', '4'];
            $signature = md5(implode('~', $signParts));
            $form = http_build_query([
                'merchant_id' => self::MERCHANT, 'reference_sale' => 'sale-payu-001',
                'value' => '1550.25', 'currency' => 'COP', 'state_pol' => '4',
            ]);
            self::assertTrue($gateway->verifyWebhook('tenant-a', 'company-a', $form, $signature));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $form, str_repeat('0', 32)));
            self::assertFalse($gateway->verifyWebhook('tenant-b', 'company-a', $form, $signature));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-b', $form, $signature));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $form.'&state_pol=4', $signature));
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', str_replace('1550.25', '1550.26', $form), $signature));

            $refundRequest = [
                'tenant_id' => 'tenant-a', 'company_id' => 'company-a',
                'charge_ref' => $approved['charge_ref'], 'amount_minor' => 155025,
                'idempotency_key' => 'refund-key-001',
            ];
            $refunded = $gateway->refund($refundRequest);
            self::assertSame('refunded', $refunded['status']);
            self::assertSame($refunded, $gateway->refund($refundRequest));
            self::assertSame('refunded', $gateway->query('tenant-a', 'company-a', $approved['charge_ref'])['status']);
            self::assertNull($gateway->charge(self::charge())['ledger_context']);
            self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-a', $form, $signature));
            self::assertSame(3, count($calls));
        });
    }

    public function testPayURoundingSignatureAndTenantScope(): void
    {
        self::withGateway(
            static fn(string $url, array $payload): array => [
                'transactionResponse' => ['state' => 'APPROVED', 'transactionId' => 'test-transaction-003'],
            ],
            static function (PayUPaymentGateway $gateway): void {
                $request = self::charge();
                $request['amount_minor'] = 15000;
                $approved = $gateway->charge($request);
                $body = http_build_query([
                    'merchant_id' => self::MERCHANT, 'reference_sale' => 'sale-payu-001',
                    'value' => '150.00', 'currency' => 'COP', 'state_pol' => '4',
                ]);
                self::assertTrue($gateway->verifyWebhook(
                    'tenant-a', 'company-a', $body,
                    md5(implode('~', [self::TEST_API_KEY, self::MERCHANT, 'sale-payu-001', '150.0', 'COP', '4']))
                ));
                self::assertFalse($gateway->verifyWebhook('tenant-a', 'company-b', $body, str_repeat('a', 32)));
                self::assertSame($approved, $gateway->query('tenant-a', 'company-a', $approved['charge_ref']));
            },
        );
    }

    public function testIdempotencyAndRefundFailClosed(): void
    {
        $times = 0;
        $transport = static function (string $url, array $body) use (&$times): array {
            $times++;
            if (($body['transaction']['type'] ?? '') === 'REFUND') {
                return ['transactionResponse' => ['state' => 'DECLINED']];
            }
            return ['transactionResponse' => ['state' => 'APPROVED', 'transactionId' => 'test-transaction-004']];
        };
        self::withGateway($transport, static function (PayUPaymentGateway $gateway) use (&$times): void {
            $charge = $gateway->charge(self::charge());
            foreach ([
                [...self::charge(), 'amount_minor' => 155026],
                self::charge('other-key', 'sale-payu-001'),
            ] as $conflict) {
                try {
                    $gateway->charge($conflict);
                    self::fail('Conflicting or duplicate PayU charge allowed');
                } catch (DomainException) {
                    self::assertTrue(true);
                }
            }
            foreach ([
                ['tenant-b', 'company-a'], ['tenant-a', 'company-b'],
            ] as [$tenant, $company]) {
                try {
                    $gateway->query($tenant, $company, $charge['charge_ref']);
                    self::fail('Cross-tenant PayU query allowed');
                } catch (DomainException) {
                    self::assertTrue(true);
                }
            }
            $refund = [
                'tenant_id' => 'tenant-a', 'company_id' => 'company-a',
                'charge_ref' => $charge['charge_ref'], 'amount_minor' => 155025,
                'idempotency_key' => 'refuse-refund',
            ];
            try {
                $gateway->refund($refund);
                self::fail('Rejected PayU refund allowed');
            } catch (DomainException) {
                self::assertSame('approved', $gateway->query('tenant-a', 'company-a', $charge['charge_ref'])['status']);
            }
            self::assertSame(2, $times);
        });
    }

    public function testNoLiveSecretOrSensitivePayloadAndUnknownFailsClosed(): void
    {
        self::withGateway(static fn(string $url, array $req): array => [],
            static function (PayUPaymentGateway $gateway): void {
                foreach ([
                    [...self::charge(), 'card_number' => 'synthetic'],
                    [...self::charge(), 'amount_minor' => true],
                    [...self::charge(), 'currency' => 'cop'],
                ] as $bad) {
                    try {
                        $gateway->charge($bad);
                        self::fail('Invalid or sensitive request allowed');
                    } catch (DomainException) {
                        self::assertTrue(true);
                    }
                }
                try {
                    $gateway->charge(self::charge());
                    self::fail('Unrecognised sandbox outcome accepted');
                } catch (DomainException $exception) {
                    self::assertSame('PAYU_SANDBOX_RESPONSE_UNKNOWN', $exception->getMessage());
                }
            });
        try {
            new PayUPaymentGateway(self::MERCHANT, self::ACCOUNT,
                static fn(string $url, array $req): array => [], '/missing-private-key', '/missing-private-login');
            self::fail('Missing sandbox credentials were allowed');
        } catch (DomainException $e) {
            self::assertSame('PAYU_SANDBOX_CREDENTIAL_UNAVAILABLE', $e->getMessage());
        }
    }
}
