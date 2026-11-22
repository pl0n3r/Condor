<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Payments\Wompi;

use App\Application\Payments\PaymentGatewayRegistry;
use App\Infrastructure\Payments\Wompi\WompiPaymentGateway;
use App\Infrastructure\Payments\Wompi\WompiSandboxHttpTransport;
use Doctrine\DBAL\Connection;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Real adapter against a deterministic offline Wompi API double, no network. */
final class WompiPaymentGatewayTest extends TestCase
{
    private const NOW = 1793373000;
    private const EVENTS_SECRET = 'test_events_000000000000000000000000000';
    /** @var list<string> */
    private array $privateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->privateFiles as $path) @unlink($path);
        putenv('WOMPI_PRIVATE_KEY_FILE');
        putenv('WOMPI_EVENTS_SECRET_FILE');
        $this->privateFiles = [];
    }

    private function gateway(?\Closure $transport = null): WompiPaymentGateway
    {
        foreach ([
            'WOMPI_PRIVATE_KEY_FILE' => 'prv_test_0000000000000000000000000000',
            'WOMPI_EVENTS_SECRET_FILE' => self::EVENTS_SECRET,
        ] as $name => $value) {
            $path = tempnam(sys_get_temp_dir(), 'wompi_fake_');
            if ($path === false) throw new \RuntimeException('Cannot create test file');
            file_put_contents($path, $value."\n");
            chmod($path, 0600);
            putenv($name.'='.$path);
            $this->privateFiles[] = $path;
        }
        return new WompiPaymentGateway($transport ?? $this->transport(), null, null, static fn (): int => self::NOW);
    }

    private function transport(): \Closure
    {
        $payments = [];
        return static function (string $method, string $path, array $input, string $apiKey) use (&$payments): array {
            if (!str_starts_with($apiKey, 'prv_test_')) throw new \RuntimeException('Live key blocked');
            if ($method === 'POST' && $path === '/transactions') {
                $ref = 'wompi_'.substr(hash('sha256', $input['reference'].'-'.$input['scenario']), 0, 24);
                $payments[$ref] = ['id' => $ref,'reference' => $input['reference'],
                    'amount_in_cents' => $input['amount_in_cents'], 'currency' => $input['currency'],
                    'status' => $input['scenario'] === 'approve' ? 'APPROVED' : 'DECLINED'];
                return ['data' => $payments[$ref]];
            }
            if ($method === 'GET' && str_starts_with($path, '/transactions/')) {
                $ref = substr($path, strlen('/transactions/'));
                return ['data' => $payments[$ref] ?? ['error' => 'unknown']];
            }
            if ($method === 'POST' && $path === '/refunds') {
                return ['data' => ['transaction_id' => $input['transaction_id'],
                    'amount_in_cents' => $input['amount_in_cents'], 'status' => 'APPROVED',
                    'v2_refund_id' => 'v2_refund_'.substr(hash('sha256', $input['reference']),0,24)]];
            }
            throw new \RuntimeException('Unexpected fake HTTP request');
        };
    }

    private static function request(string $idempotency = 'idem-alpha-01'): array
    {
        return ['tenant_id' => 'tenant-alpha','company_id' => 'company-alpha',
            'order_ref' => 'order-alpha-01', 'amount_minor' => 150000,
            'currency' => 'COP', 'idempotency_key' => $idempotency];
    }

    public function testApprovedRejectedRefundAndInvalidWebhookSignature(): void
    {
        $gateway = $this->gateway();
        self::assertSame('wompi', $gateway->id());
        self::assertSame('sandbox', $gateway->mode());
        $registry = new PaymentGatewayRegistry([$gateway], [
            'tenant-alpha/company-alpha' => ['providers' => ['wompi'], 'currencies' => ['COP']],
        ]);
        self::assertSame($gateway, $registry->forCompany('tenant-alpha','company-alpha','wompi','COP'));
        $approved = $gateway->charge(self::request());
        self::assertSame('approved', $approved['status']);
        self::assertSame('wompi', $approved['ledger_context']['method']);
        self::assertSame($approved, $gateway->charge(self::request()));
        self::assertSame('approved', $gateway->query('tenant-alpha','company-alpha',$approved['charge_ref'])['status']);
        $declined = $gateway->charge(self::request('idem-declined-01') + ['scenario'=>'reject']);
        self::assertSame('rejected', $declined['status']);
        self::assertNull($declined['ledger_context']);
        $tx = ['id'=>$approved['charge_ref'], 'status'=>'APPROVED', 'amount_in_cents'=>150000];
        $body = ['event'=>'transaction.updated','data'=>['transaction'=>$tx], 'environment'=>'test',
            'signature'=>['properties'=>['transaction.id','transaction.status','transaction.amount_in_cents'],
                'checksum'=>''], 'timestamp'=>self::NOW];
        $checksum = hash('sha256', $tx['id'].$tx['status'].$tx['amount_in_cents'].self::NOW.self::EVENTS_SECRET);
        $body['signature']['checksum'] = strtoupper($checksum);
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertTrue($gateway->verifyWebhook('tenant-alpha','company-alpha',$json,$checksum));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',$json,str_repeat('0',64)));
        self::assertFalse($gateway->verifyWebhook('tenant-beta','company-alpha',$json,$checksum));
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-beta',$json,$checksum));
        $body['data']['transaction']['amount_in_cents'] += 1;
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',json_encode($body,JSON_THROW_ON_ERROR),$checksum));
        $body['timestamp'] = self::NOW-301;
        self::assertFalse($gateway->verifyWebhook('tenant-alpha','company-alpha',json_encode($body,JSON_THROW_ON_ERROR),$checksum));
        $refund = ['tenant_id'=>'tenant-alpha','company_id'=>'company-alpha',
            'charge_ref'=>$approved['charge_ref'],'amount_minor'=>150000,'idempotency_key'=>'refund-alpha-01'];
        $result = $gateway->refund($refund);
        self::assertSame('refunded', $result['status']);
        self::assertSame(150000, $result['refunded_minor']);
        self::assertSame($result, $gateway->refund($refund));
        self::assertSame('refunded', $gateway->query('tenant-alpha','company-alpha',$approved['charge_ref'])['status']);
    }

    public function testIdempotencyAndCrossTenantAndUnverifiableStatusFailClosed(): void
    {
        $gateway = $this->gateway();
        $first = $gateway->charge(self::request());
        try {
            $gateway->charge(array_replace(self::request(), ['amount_minor'=>160000]));
            self::fail('Idempotency collision accepted');
        } catch (DomainException $e) { self::assertSame('WOMPI_IDEMPOTENCY_CONFLICT',$e->getMessage()); }
        try {
            $gateway->query('tenant-beta','company-alpha',$first['charge_ref']);
            self::fail('Cross-tenant query accepted');
        } catch (DomainException $e) { self::assertSame('WOMPI_TRANSACTION_NOT_FOUND',$e->getMessage()); }
        try {
            $gateway->charge(array_replace(self::request(), ['currency'=>'USD','idempotency_key'=>'other-01']));
            self::fail('Unsupported currency accepted');
        } catch (DomainException $e) { self::assertSame('WOMPI_UNSUPPORTED_CURRENCY',$e->getMessage()); }
    }

    public function testWithoutTransportOrLiveSecretsIsNotEnabled(): void
    {
        $gateway = $this->gateway(static fn(): array => ['error'=>'fake timeout']);
        try {
            $gateway->charge(self::request());
            self::fail('Unverifiable response accepted');
        } catch (DomainException $e) { self::assertSame('WOMPI_UNVERIFIED_TRANSACTION',$e->getMessage()); }
        $this->tearDown();
        try {
            new WompiPaymentGateway(static fn(): array => []);
            self::fail('Missing test secrets accepted');
        } catch (DomainException $e) { self::assertSame('WOMPI_PRIVATE_SECRET_REQUIRED',$e->getMessage()); }
    }
    public function testStoredTestSecretsAndProdKeyRejection(): void
    {
        $stored = static fn (string $key): string => $key === 'wompi.private_key'
            ? 'prv_test_'.str_repeat('a', 24) : self::EVENTS_SECRET;
        $gateway = new WompiPaymentGateway(storedSecret: $stored);
        try {
            $gateway->charge(self::request());
            self::fail('Unconfigured network path allowed');
        } catch (DomainException $exception) {
            self::assertSame('WOMPI_SANDBOX_UNAVAILABLE', $exception->getMessage());
        }

        $live = static fn (string $key): string => $key === 'wompi.private_key'
            ? 'prv_prod_'.str_repeat('a', 24) : self::EVENTS_SECRET;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('WOMPI_TEST_SECRET_ONLY');
        new WompiPaymentGateway(storedSecret: $live);
    }

    public function testSignedSandboxEventRejectsInvalidSignaturesAndProd(): void
    {
        $tx = ['id' => 'wompi_test_0001', 'status' => 'APPROVED', 'amount_in_cents' => 150000];
        $time = time();
        $checksum = hash('sha256', $tx['id'].$tx['status'].$tx['amount_in_cents'].$time.self::EVENTS_SECRET);
        $event = ['event' => 'transaction.updated', 'environment' => 'test',
            'data' => ['transaction' => $tx],
            'signature' => ['properties' => ['transaction.id','transaction.status','transaction.amount_in_cents'],
                'checksum' => $checksum], 'timestamp' => $time];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertTrue(WompiPaymentGateway::verifySignedSandboxEvent($body, $checksum, self::EVENTS_SECRET));
        self::assertFalse(WompiPaymentGateway::verifySignedSandboxEvent($body, str_repeat('0',64), self::EVENTS_SECRET));
        self::assertFalse(WompiPaymentGateway::verifySignedSandboxEvent($body, $checksum, 'prod_events_'.str_repeat('x',24)));
        $event['data']['transaction']['amount_in_cents']++;
        self::assertFalse(WompiPaymentGateway::verifySignedSandboxEvent(
            json_encode($event, JSON_THROW_ON_ERROR), $checksum, self::EVENTS_SECRET));
    }

    public function testSandboxTransportIsDisabledByDefaultAndUsesOnlyFixedHost(): void
    {
        $old = getenv('CONDOR_PAYMENTS_SANDBOX_ENABLED');
        $files = [];
        foreach ([
            'WOMPI_PUBLIC_KEY_FILE' => 'pub_test_'.str_repeat('a', 24),
            'WOMPI_PRIVATE_KEY_FILE' => 'prv_test_'.str_repeat('b', 24),
            'WOMPI_INTEGRITY_SECRET_FILE' => 'test_integrity_'.str_repeat('c', 24),
        ] as $env => $value) {
            $file = tempnam(sys_get_temp_dir(), 'wompi_fixture_');
            self::assertIsString($file);
            file_put_contents($file, $value);
            chmod($file, 0600);
            putenv($env.'='.$file);
            $files[$env] = $file;
        }
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $calls = [];
        $http = static function (string $method, string $url, ?array $payload, string $public, string $private) use (&$calls): array {
            $calls[] = $url;
            self::assertStringStartsWith(WompiPaymentGateway::SANDBOX_URL.'/', $url);
            self::assertStringStartsWith('pub_test_', $public);
            self::assertStringStartsWith('prv_test_', $private);
            if ($method === 'GET') {
                return ['data' => [
                    'presigned_acceptance' => ['acceptance_token' => str_repeat('a', 24)],
                    'presigned_personal_data_auth' => ['acceptance_token' => str_repeat('b', 24)],
                ]];
            }
            self::assertSame('NEQUI', $payload['payment_method']['type']);
            self::assertSame('3991111111', $payload['payment_method']['phone_number']);
            self::assertSame(hash('sha256', $payload['reference'].'150000COPtest_integrity_'.str_repeat('c',24)),
                $payload['signature']);
            return ['data' => [
                'id' => 'wompi_sandbox_001', 'reference' => $payload['reference'],
                'amount_in_cents' => 150000, 'currency' => 'COP', 'status' => 'PENDING',
            ]];
        };
        try {
            $transport = new WompiSandboxHttpTransport($connection, str_repeat('a',64), $http);
            putenv('CONDOR_PAYMENTS_SANDBOX_ENABLED=false');
            try {
                $transport->testPayment();
                self::fail('Network allowed by default');
            } catch (DomainException $exception) {
                self::assertSame('WOMPI_SANDBOX_DISABLED', $exception->getMessage());
            }
            self::assertSame([], $calls);
            putenv('CONDOR_PAYMENTS_SANDBOX_ENABLED=true');
            $result = $transport->testPayment();
            self::assertSame('PENDING', $result['status']);
            self::assertSame('wompi_sandbox_001', $result['transaction_id']);
            self::assertSame([
                WompiPaymentGateway::SANDBOX_URL.'/merchants/info',
                WompiPaymentGateway::SANDBOX_URL.'/transactions',
            ], $calls);
        } finally {
            putenv($old === false ? 'CONDOR_PAYMENTS_SANDBOX_ENABLED' : 'CONDOR_PAYMENTS_SANDBOX_ENABLED='.$old);
            foreach ($files as $env => $path) {
                putenv($env);
                @unlink($path);
            }
        }
    }

}
