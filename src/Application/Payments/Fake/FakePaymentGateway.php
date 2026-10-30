<?php

declare(strict_types=1);

namespace App\Application\Payments\Fake;

use App\Application\Payments\PaymentGateway;
use DomainException;

/**
 * In-memory simulation, never an issuer, ledger writer, or real provider.
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string,array{fingerprint:string,result:ChargeResult}> */
    private array $requests = [];

    /** @var array<string,ChargeResult> */
    private array $charges = [];

    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];

    public function __construct(private readonly string $testWebhookKey)
    {
        if (strlen($testWebhookKey) < 16 || str_contains($testWebhookKey, "\n")) {
            throw new DomainException('GATEWAY_FAKE_KEY_INVALID');
        }
    }

    public function id(): string { return 'fake'; }

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
        if (!in_array($scenario, ['approve','reject'], true)) {
            throw new DomainException('GATEWAY_INVALID_SCENARIO');
        }
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$tenant,$company,$order,$amount,$currency,$scenario], JSON_THROW_ON_ERROR));
        $existing = $this->requests[$scope] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                throw new DomainException('GATEWAY_IDEMPOTENCY_CONFLICT');
            }
            return $this->charges[$existing['result']['charge_ref']];
        }
        $ref = 'fake_'.substr(hash('sha256', $scope), 0, 32);
        $status = $scenario === 'approve' ? 'approved' : 'rejected';
        /** @var ChargeResult $result */
        $result = [
            'charge_ref' => $ref,
            'status' => $status,
            'tenant_id' => $tenant,
            'company_id' => $company,
            'amount_minor' => $amount,
            'currency' => $currency,
            // Compatible with OrderPaymentService's manual event context;
            // caller must reconcile atomically before recording any real event.
            'ledger_context' => $status === 'approved' ? [
                'idempotency_key' => $key,
                'amount_minor' => $amount,
                'method' => 'fake',
                'currency' => $currency,
                'external_ref' => $ref,
            ] : null,
        ];
        $this->requests[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        $this->charges[$ref] = $result;
        return $result;
    }

    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenantId = self::identifier($tenantId);
        $companyId = self::identifier($companyId);
        $charge = $this->charges[$chargeRef] ?? null;
        if ($charge === null || !hash_equals($charge['tenant_id'], $tenantId)
            || !hash_equals($charge['company_id'], $companyId)) {
            throw new DomainException('GATEWAY_CHARGE_NOT_FOUND');
        }
        return $charge;
    }

    public function refund(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','charge_ref','amount_minor','idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $chargeRef = $request['charge_ref'];
        if (!preg_match('/^fake_[a-f0-9]{32}$/D', $chargeRef)) {
            throw new DomainException('GATEWAY_INVALID_REF');
        }
        $charge = $this->query($tenant, $company, $chargeRef);
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$chargeRef, $amount], JSON_THROW_ON_ERROR));
        $existing = $this->refunds[$scope] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                throw new DomainException('GATEWAY_IDEMPOTENCY_CONFLICT');
            }
            return $existing['result'];
        }
        if ($charge['status'] !== 'approved' || $charge['amount_minor'] !== $amount) {
            throw new DomainException('GATEWAY_REFUND_NOT_ALLOWED');
        }
        $this->charges[$chargeRef]['status'] = 'refunded';
        $this->charges[$chargeRef]['ledger_context'] = null;
        /** @var RefundResult $result */
        $result = [
            'charge_ref' => $chargeRef,
            'status' => 'refunded',
            'refunded_minor' => $amount,
            'refund_ref' => 'ref_'.substr(hash('sha256', $scope), 0, 32),
        ];
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $tenantId)
            || !preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $companyId)
            || strlen($payload) > 8192 || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $this->testWebhookKey);
        if (!hash_equals($expected, $signature)) {
            return false;
        }
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        return is_array($decoded)
            && array_keys($decoded) === ['event','charge_ref']
            && ($decoded['event'] ?? null) === 'charge.updated'
            && is_string($decoded['charge_ref'] ?? null)
            && isset($this->charges[$decoded['charge_ref']])
            && hash_equals($this->charges[$decoded['charge_ref']]['tenant_id'], $tenantId)
            && hash_equals($this->charges[$decoded['charge_ref']]['company_id'], $companyId);
    }

    /**
     * @param array<string,mixed> $request
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function shape(array $request, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($request)) !== []
            || array_diff(array_keys($request), [...$required, ...$optional]) !== []) {
            throw new DomainException('GATEWAY_REQUEST_SHAPE_INVALID');
        }
    }

    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $value)) {
            throw new DomainException('GATEWAY_INVALID_REFERENCE');
        }
        return $value;
    }

    private static function amount(mixed $value): int
    {
        if (!is_int($value) || $value <= 0 || $value > 9007199254740991) {
            throw new DomainException('GATEWAY_INVALID_AMOUNT');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new DomainException('GATEWAY_INVALID_CURRENCY');
        }
        return $value;
    }
}
