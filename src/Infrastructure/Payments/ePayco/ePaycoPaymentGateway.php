<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\ePayco;

use App\Application\Payments\PaymentGateway;
use Closure;
use DomainException;

/**
 * ePayco sandbox-only protocol adapter. It does not provide HTTP or a live
 * checkout: a deterministic test transport must be injected explicitly.
 * No payer/card data, real charges, live keys or ledger writes are accepted.
 *
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class ePaycoPaymentGateway implements PaymentGateway
{
    /** @var array<string,array{fingerprint:string,ref:string}> */
    private array $requests = [];

    /** @var array<string,array{result:ChargeResult,order:string,provider_ref:string,transaction:string}> */
    private array $charges = [];

    /** @var array<string,bool> */
    private array $orders = [];

    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];

    private readonly string $pKey;

    /**
     * @param Closure(string,array<string,mixed>):array<string,mixed> $transport
     */
    public function __construct(
        private readonly string $customerId,
        private readonly Closure $transport,
        ?string $pKeyFile = null,
    ) {
        if (preg_match('/^[0-9]{4,12}$/D', $customerId) !== 1) {
            throw new DomainException('EPAYCO_INVALID_CUSTOMER_ID');
        }
        $this->pKey = self::readTestKey($pKeyFile);
    }

    public function id(): string
    {
        return 'epayco';
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    public function charge(array $request): array
    {
        self::shape($request, ['tenant_id', 'company_id', 'order_ref', 'amount_minor', 'currency', 'idempotency_key'], ['scenario']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $order = self::identifier($request['order_ref']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $currency = self::currency($request['currency']);
        $scenario = $request['scenario'] ?? 'approve';
        if (!in_array($scenario, ['approve', 'reject'], true)) {
            throw new DomainException('EPAYCO_SANDBOX_SCENARIO_UNKNOWN');
        }
        $scope = $tenant.'/'.$company;
        $requestScope = $scope.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$order, $amount, $currency, $scenario], JSON_THROW_ON_ERROR));
        if (isset($this->requests[$requestScope])) {
            $saved = $this->requests[$requestScope];
            if (!hash_equals($saved['fingerprint'], $fingerprint)) {
                throw new DomainException('EPAYCO_IDEMPOTENCY_CONFLICT');
            }
            return $this->charges[$saved['ref']]['result'];
        }
        if (isset($this->orders[$scope.'/'.$order])) {
            throw new DomainException('EPAYCO_ORDER_ALREADY_SUBMITTED');
        }

        // A protocol-only request; the neutral contract has no payment method
        // or cardholder and cannot execute a real funded transaction.
        $response = ($this->transport)('charge', [
            'test' => true,
            'customer_id' => $this->customerId,
            'invoice' => $order,
            'amount' => self::decimal($amount),
            'currency' => $currency,
            'scenario' => $scenario,
            'idempotency_key' => $requestScope,
        ]);
        self::responseShape($response, ['x_ref_payco', 'x_transaction_id', 'x_id_invoice', 'x_amount', 'x_currency_code', 'x_response', 'x_test_request']);
        if ($response['x_test_request'] !== 'TRUE'
            || $response['x_id_invoice'] !== $order
            || $response['x_amount'] !== self::decimal($amount)
            || $response['x_currency_code'] !== $currency
            || !in_array($response['x_response'], ['Aceptada', 'Rechazada'], true)
            || ($scenario === 'approve' && $response['x_response'] !== 'Aceptada')
            || ($scenario === 'reject' && $response['x_response'] !== 'Rechazada')) {
            throw new DomainException('EPAYCO_UNVERIFIABLE_SANDBOX_RESPONSE');
        }
        $providerRef = self::providerRef($response['x_ref_payco']);
        $transaction = self::providerRef($response['x_transaction_id']);
        foreach ($this->charges as $existing) {
            if ($existing['provider_ref'] === $providerRef || $existing['transaction'] === $transaction) {
                throw new DomainException('EPAYCO_TRANSACTION_REPLAY');
            }
        }
        $chargeRef = 'epayco_'.hash('sha256', $scope.'/'.$providerRef.'/'.$transaction);
        $approved = $response['x_response'] === 'Aceptada';
        /** @var ChargeResult $result */
        $result = [
            'charge_ref' => $chargeRef,
            'status' => $approved ? 'approved' : 'rejected',
            'tenant_id' => $tenant,
            'company_id' => $company,
            'amount_minor' => $amount,
            'currency' => $currency,
            'ledger_context' => $approved ? [
                'idempotency_key' => $key,
                'amount_minor' => $amount,
                'method' => 'epayco_sandbox',
                'currency' => $currency,
                'external_ref' => $chargeRef,
            ] : null,
        ];
        $this->charges[$chargeRef] = [
            'result' => $result, 'order' => $order,
            'provider_ref' => $providerRef, 'transaction' => $transaction,
        ];
        $this->requests[$requestScope] = ['fingerprint' => $fingerprint, 'ref' => $chargeRef];
        $this->orders[$scope.'/'.$order] = true;
        return $result;
    }

    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenant = self::identifier($tenantId);
        $company = self::identifier($companyId);
        $saved = $this->charges[$chargeRef] ?? null;
        if ($saved === null || $saved['result']['tenant_id'] !== $tenant
            || $saved['result']['company_id'] !== $company) {
            throw new DomainException('EPAYCO_CHARGE_NOT_FOUND');
        }
        return $saved['result'];
    }

    public function refund(array $request): array
    {
        self::shape($request, ['tenant_id', 'company_id', 'charge_ref', 'amount_minor', 'idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        if (preg_match('/^epayco_[a-f0-9]{64}$/D', $request['charge_ref']) !== 1) {
            throw new DomainException('EPAYCO_INVALID_REF');
        }
        $ref = $request['charge_ref'];
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$ref, $amount], JSON_THROW_ON_ERROR));
        if (isset($this->refunds[$scope])) {
            $saved = $this->refunds[$scope];
            if (!hash_equals($saved['fingerprint'], $fingerprint)) {
                throw new DomainException('EPAYCO_IDEMPOTENCY_CONFLICT');
            }
            return $saved['result'];
        }
        $charge = $this->query($tenant, $company, $ref);
        if ($charge['status'] !== 'approved' || $charge['amount_minor'] !== $amount) {
            throw new DomainException('EPAYCO_REFUND_NOT_ALLOWED');
        }
        $response = ($this->transport)('refund', [
            'test' => true, 'ref_payco' => $this->charges[$ref]['provider_ref'],
            'transaction_id' => $this->charges[$ref]['transaction'],
            'amount' => self::decimal($amount), 'currency' => $charge['currency'],
            'idempotency_key' => $scope,
        ]);
        self::responseShape($response, ['x_ref_payco', 'refund_ref', 'status', 'x_test_request']);
        if ($response['x_test_request'] !== 'TRUE'
            || $response['x_ref_payco'] !== $this->charges[$ref]['provider_ref']
            || $response['status'] !== 'approved') {
            throw new DomainException('EPAYCO_UNVERIFIABLE_REFUND');
        }
        $refundRef = self::providerRef($response['refund_ref']);
        /** @var RefundResult $result */
        $result = [
            'charge_ref' => $ref, 'status' => 'refunded',
            'refunded_minor' => $amount, 'refund_ref' => $refundRef,
        ];
        $this->charges[$ref]['result']['status'] = 'refunded';
        $this->charges[$ref]['result']['ledger_context'] = null;
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        // A signature is necessary, never sufficient: match the stored tenant,
        // invoice, transaction, amount, currency, test flag and outcome.
        if (strlen($payload) > 16384 || preg_match('/^[a-fA-F0-9]{64}$/D', $signature) !== 1) {
            return false;
        }
        try {
            $tenant = self::identifier($tenantId);
            $company = self::identifier($companyId);
            $data = self::form($payload);
            foreach (['x_ref_payco', 'x_transaction_id', 'x_id_invoice', 'x_amount', 'x_currency_code', 'x_response', 'x_test_request'] as $name) {
                if (!isset($data[$name]) || $data[$name] === '') {
                    return false;
                }
            }
            if ($data['x_test_request'] !== 'TRUE' || !in_array($data['x_response'], ['Aceptada', 'Rechazada'], true)) {
                return false;
            }
            if (isset($data['x_cust_id_cliente']) && $data['x_cust_id_cliente'] !== $this->customerId) {
                return false;
            }
            if (isset($data['x_signature']) && !hash_equals(strtolower($signature), strtolower($data['x_signature']))) {
                return false;
            }
            $expected = hash('sha256', implode('^', [
                $this->customerId, $this->pKey, $data['x_ref_payco'],
                $data['x_transaction_id'], $data['x_amount'], $data['x_currency_code'],
            ]));
            if (!hash_equals($expected, strtolower($signature))) {
                return false;
            }
            $amount = self::parseDecimal($data['x_amount']);
            foreach ($this->charges as $entry) {
                $result = $entry['result'];
                if ($result['tenant_id'] === $tenant && $result['company_id'] === $company
                    && $entry['provider_ref'] === $data['x_ref_payco']
                    && $entry['transaction'] === $data['x_transaction_id']
                    && $entry['order'] === $data['x_id_invoice']
                    && $result['amount_minor'] === $amount
                    && $result['currency'] === $data['x_currency_code']
                    && $result['status'] === ($data['x_response'] === 'Aceptada' ? 'approved' : 'rejected')) {
                    return true;
                }
            }
            return false;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function shape(array $value, array $required, array $optional = []): void
    {
        $keys = array_keys($value);
        if (array_diff($required, $keys) !== [] || array_diff($keys, [...$required, ...$optional]) !== []) {
            throw new DomainException('EPAYCO_INVALID_REQUEST');
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $required
     */
    private static function responseShape(array $value, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($value[$field]) || !is_string($value[$field])) {
                throw new DomainException('EPAYCO_UNVERIFIABLE_SANDBOX_RESPONSE');
            }
        }
    }

    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $value) !== 1) {
            throw new DomainException('EPAYCO_INVALID_IDENTIFIER');
        }
        return $value;
    }

    private static function providerRef(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{4,96}$/D', $value) !== 1) {
            throw new DomainException('EPAYCO_INVALID_PROVIDER_REF');
        }
        return $value;
    }

    private static function amount(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 999999999999) {
            throw new DomainException('EPAYCO_INVALID_AMOUNT');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['COP', 'USD'], true)) {
            throw new DomainException('EPAYCO_INVALID_CURRENCY');
        }
        return $value;
    }

    private static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function parseDecimal(string $value): int
    {
        if (preg_match('/^([0-9]{1,11})(?:\.([0-9]{1,2}))?$/D', $value, $m) !== 1) {
            throw new DomainException('EPAYCO_INVALID_AMOUNT');
        }
        return self::amount(((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0'));
    }

    /** @return array<string,string> */
    private static function form(string $payload): array
    {
        $result = [];
        if ($payload === '') {
            return [];
        }
        foreach (explode('&', $payload) as $part) {
            $pair = explode('=', $part, 2);
            $key = urldecode($pair[0]);
            $value = urldecode($pair[1] ?? '');
            if ($key === '' || isset($result[$key]) || strlen($key) > 100 || strlen($value) > 1024) {
                throw new DomainException('EPAYCO_INVALID_WEBHOOK');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private static function readTestKey(?string $path): string
    {
        $path ??= getenv('EPAYCO_P_KEY_FILE') ?: '';
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)
            || (fileperms($path) & 0077) !== 0 || filesize($path) > 1024) {
            throw new DomainException('EPAYCO_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        $data = file_get_contents($path);
        if (!is_string($data) || preg_match('/^test_[A-Za-z0-9_-]{8,200}$/D', trim($data)) !== 1) {
            throw new DomainException('EPAYCO_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        return trim($data);
    }
}
