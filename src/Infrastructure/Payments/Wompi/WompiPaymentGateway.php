<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Wompi;

use App\Application\Payments\PaymentGateway;
use Closure;
use DomainException;
use JsonException;

/**
 * Wompi Colombia sandbox protocol, NO external transport by default.
 * A caller must inject a network-free double until a separate legal/live gate.
 * The base contract has no payment method or acceptance token: this adapter
 * cannot initiate a funded payment. No customer/card PII or production keys.
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class WompiPaymentGateway implements PaymentGateway
{
    public const SANDBOX_URL = 'https://sandbox.wompi.co/v1';

    /** @var Closure(string,string,array<string,mixed>,string):array<string,mixed> */
    private readonly Closure $transport;
    /** @var Closure():int */
    private readonly Closure $clock;
    private readonly string $apiKey;
    private readonly string $eventsSecret;

    /** @var array<string,array{fingerprint:string,ref:string}> */
    private array $requests = [];
    /** @var array<string,ChargeResult> */
    private array $charges = [];
    /** @var array<string,string> */
    private array $refundStatuses = [];
    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];

    public function __construct(Closure $transport, ?string $privateKeyFile = null, ?string $eventsSecretFile = null, ?Closure $clock = null)
    {
        $this->apiKey = self::secret($privateKeyFile, 'WOMPI_PRIVATE_KEY_FILE', '/^prv_test_[A-Za-z0-9_-]{16,}$/D');
        $this->eventsSecret = self::secret($eventsSecretFile, 'WOMPI_EVENTS_SECRET_FILE', '/^test_events_[A-Za-z0-9_-]{16,}$/D');
        $this->transport = $transport;
        $this->clock = $clock ?? time(...);
    }

    public function id(): string { return 'wompi'; }
    public function mode(): string { return 'sandbox'; }

    public function charge(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','order_ref','amount_minor','currency','idempotency_key'], ['scenario']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $order = self::identifier($request['order_ref']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $currency = self::currency($request['currency']);
        $scenario = $request['scenario'] ?? 'approve';
        if (!in_array($scenario, ['approve','reject'], true)) throw new DomainException('WOMPI_INVALID_TEST_SCENARIO');
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$tenant,$company,$order,$amount,$currency,$scenario], JSON_THROW_ON_ERROR));
        if (isset($this->requests[$scope])) {
            if (!hash_equals($this->requests[$scope]['fingerprint'], $fingerprint)) throw new DomainException('WOMPI_IDEMPOTENCY_CONFLICT');
            $ref = $this->requests[$scope]['ref'];
            return $this->charges[$ref];
        }
        // A real Wompi transaction requires payment_method and acceptance_token;
        // they are deliberately excluded from this fake-only protocol contract.
        $reply = $this->send('POST', '/transactions', [
            'reference' => $order, 'amount_in_cents' => $amount, 'currency' => $currency,
            'scenario' => $scenario, 'sandbox' => true,
        ]);
        $record = $reply['data'] ?? null;
        self::assertTransaction($record, $order, $amount, $currency);
        $ref = $record['id'];
        if (isset($this->charges[$ref])) throw new DomainException('WOMPI_DUPLICATE_TRANSACTION');
        $result = $this->asResult($record, $tenant, $company, $key);
        $this->requests[$scope] = ['fingerprint' => $fingerprint, 'ref' => $ref];
        $this->charges[$ref] = $result;
        return $result;
    }

    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenant = self::identifier($tenantId);
        $company = self::identifier($companyId);
        self::transactionRef($chargeRef);
        $previous = $this->charges[$chargeRef] ?? null;
        if ($previous === null || $previous['tenant_id'] !== $tenant || $previous['company_id'] !== $company) {
            throw new DomainException('WOMPI_TRANSACTION_NOT_FOUND');
        }
        $reply = $this->send('GET', '/transactions/'.$chargeRef, []);
        $record = $reply['data'] ?? null;
        self::assertTransaction($record, null, $previous['amount_minor'], $previous['currency']);
        if ($record['id'] !== $chargeRef) throw new DomainException('WOMPI_TRANSACTION_SCOPE_MISMATCH');
        $original = $this->charges[$chargeRef];
        // Scope cannot be inferred from provider IDs; keep the original local tenant.
        $context = $original['ledger_context'];
        $key = is_array($context) && is_string($context['idempotency_key'] ?? null)
            ? $context['idempotency_key'] : '';
        $current = $this->asResult($record, $tenant, $company, $key);
        if (isset($this->refundStatuses[$chargeRef])) {
            $current['status'] = $this->refundStatuses[$chargeRef];
            $current['ledger_context'] = null;
        }
        $this->charges[$chargeRef] = $current;
        return $current;
    }

    public function refund(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','charge_ref','amount_minor','idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $chargeRef = self::transactionRef($request['charge_ref']);
        $amount = self::amount($request['amount_minor']);
        $key = self::identifier($request['idempotency_key']);
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$chargeRef,$amount], JSON_THROW_ON_ERROR));
        if (isset($this->refunds[$scope])) {
            if (!hash_equals($this->refunds[$scope]['fingerprint'], $fingerprint)) throw new DomainException('WOMPI_IDEMPOTENCY_CONFLICT');
            return $this->refunds[$scope]['result'];
        }
        $charge = $this->query($tenant, $company, $chargeRef);
        if ($charge['status'] !== 'approved' || $charge['amount_minor'] !== $amount) throw new DomainException('WOMPI_REFUND_NOT_ALLOWED');
        $reply = $this->send('POST', '/refunds', [
            'transaction_id' => $chargeRef, 'amount_in_cents' => $amount,
            'reference' => $key, 'test_scenario' => 'approved',
        ]);
        $record = $reply['data'] ?? null;
        if (!is_array($record) || ($record['transaction_id'] ?? null) !== $chargeRef
            || ($record['amount_in_cents'] ?? null) !== $amount || ($record['status'] ?? null) !== 'APPROVED'
            || !is_string($record['v2_refund_id'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{6,120}$/D', $record['v2_refund_id']) !== 1) {
            throw new DomainException('WOMPI_REFUND_UNVERIFIED');
        }
        $result = [
            'charge_ref' => $chargeRef, 'status' => 'refunded',
            'refunded_minor' => $amount, 'refund_ref' => $record['v2_refund_id'],
        ];
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        $this->refundStatuses[$chargeRef] = 'refunded';
        $this->charges[$chargeRef]['status'] = 'refunded';
        $this->charges[$chargeRef]['ledger_context'] = null;
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        try {
            $tenant = self::identifier($tenantId);
            $company = self::identifier($companyId);
            if (strlen($payload) > 16384 || preg_match('/^[a-fA-F0-9]{64}$/D', $signature) !== 1) return false;
            $body = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body) || ($body['event'] ?? null) !== 'transaction.updated'
                || ($body['environment'] ?? null) !== 'test' || !is_array($body['data'] ?? null)
                || !is_array($body['signature'] ?? null)
                || !is_array($body['signature']['properties'] ?? null)
                || !array_is_list($body['signature']['properties'])
                || !is_string($body['signature']['checksum'] ?? null)
                || !is_int($body['timestamp'] ?? null)) return false;
            $now = ($this->clock)();
            if (!is_int($now) || abs($now - $body['timestamp']) > 300) return false;
            $parts = '';
            $properties = $body['signature']['properties'];
            // The identity, amount and outcome must themselves be checksum-bound;
            // a signed but underspecified property list is not payment evidence.
            foreach (['transaction.id', 'transaction.status', 'transaction.amount_in_cents'] as $required) {
                if (!in_array($required, $properties, true)) return false;
            }
            if (count($properties) > 16 || count(array_unique($properties)) !== count($properties)) return false;
            foreach ($body['signature']['properties'] as $path) {
                if (!is_string($path) || preg_match('/^[a-z_]+(?:\.[a-z_]+){1,4}$/D', $path) !== 1) return false;
                $cursor = $body['data'];
                foreach (explode('.', $path) as $segment) {
                    if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return false;
                    $cursor = $cursor[$segment];
                }
                if (!is_int($cursor) && !is_string($cursor)) return false;
                $parts .= (string)$cursor;
            }
            $hash = hash('sha256', $parts.(string)$body['timestamp'].$this->eventsSecret);
            if (!hash_equals($hash, strtolower($signature)) || !hash_equals($hash, strtolower($body['signature']['checksum']))) return false;
            $tx = $body['data']['transaction'] ?? null;
            if (!is_array($tx) || !is_string($tx['id'] ?? null) || !is_string($tx['status'] ?? null)
                || !is_int($tx['amount_in_cents'] ?? null)) return false;
            $local = $this->charges[$tx['id']] ?? null;
            return $local !== null && hash_equals($local['tenant_id'], $tenant)
                && hash_equals($local['company_id'], $company)
                && $local['amount_minor'] === $tx['amount_in_cents']
                && in_array($tx['status'], ['PENDING','APPROVED','DECLINED','VOIDED','ERROR'], true);
        } catch (DomainException | JsonException | \TypeError | \ValueError) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload): array
    {
        try {
            $data = ($this->transport)($method, $path, $payload, $this->apiKey);
        } catch (\Throwable) {
            throw new DomainException('WOMPI_SANDBOX_UNAVAILABLE');
        }
        if (!is_array($data)) throw new DomainException('WOMPI_UNVERIFIED_RESPONSE');
        return $data;
    }

    /** @param mixed $record */
    private static function assertTransaction(mixed $record, ?string $order, int $amount, string $currency): void
    {
        if (!is_array($record) || !is_string($record['id'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{6,120}$/D', $record['id']) !== 1
            || !is_string($record['reference'] ?? null)
            || ($order !== null && $record['reference'] !== $order)
            || ($record['amount_in_cents'] ?? null) !== $amount || ($record['currency'] ?? null) !== $currency
            || !in_array($record['status'] ?? null, ['PENDING','APPROVED','DECLINED','VOIDED','ERROR'], true)) {
            throw new DomainException('WOMPI_UNVERIFIED_TRANSACTION');
        }
    }

    /**
     * @param array<string, mixed> $tx
     * @return ChargeResult
     */
    private function asResult(array $tx, string $tenant, string $company, string $key): array
    {
        $status = match ($tx['status']) {
            'APPROVED' => 'approved', 'DECLINED', 'VOIDED', 'ERROR' => 'rejected', default => 'pending',
        };
        return [
            'charge_ref' => $tx['id'], 'status' => $status,
            'tenant_id' => $tenant, 'company_id' => $company,
            'amount_minor' => $tx['amount_in_cents'], 'currency' => $tx['currency'],
            'ledger_context' => $status === 'approved' ? [
                'idempotency_key' => $key, 'amount_minor' => $tx['amount_in_cents'],
                'method' => 'wompi', 'currency' => $tx['currency'], 'external_ref' => $tx['id'],
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function shape(array $source, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($source)) !== []
            || array_diff(array_keys($source), [...$required, ...$optional]) !== []) throw new DomainException('WOMPI_INVALID_REQUEST');
    }

    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $value) !== 1) throw new DomainException('WOMPI_INVALID_IDENTIFIER');
        return $value;
    }

    private static function transactionRef(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{6,120}$/D', $value) !== 1) throw new DomainException('WOMPI_INVALID_TRANSACTION');
        return $value;
    }

    private static function amount(mixed $value): int
    {
        if (!is_int($value) || $value <= 0 || $value > 9007199254740991) throw new DomainException('WOMPI_INVALID_AMOUNT');
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if ($value !== 'COP') throw new DomainException('WOMPI_UNSUPPORTED_CURRENCY');
        return $value;
    }

    private static function secret(?string $file, string $env, string $pattern): string
    {
        $file ??= getenv($env) ?: '';
        if ($file === '' || !is_file($file) || is_link($file) || !is_readable($file)) throw new DomainException('WOMPI_PRIVATE_SECRET_REQUIRED');
        $perms = fileperms($file);
        if ($perms === false || ($perms & 0x003f) !== 0 || filesize($file) > 1024) throw new DomainException('WOMPI_PRIVATE_FILE_PERMISSIONS');
        $secret = trim((string)file_get_contents($file));
        if (preg_match($pattern, $secret) !== 1) throw new DomainException('WOMPI_TEST_SECRET_ONLY');
        return $secret;
    }
}
