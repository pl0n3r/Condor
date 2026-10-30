<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\MercadoPago;

use App\Application\Payments\PaymentGateway;
use Closure;
use DomainException;

/**
 * Mercado Pago sandbox-only adapter. No default HTTP transport: every API
 * interaction must use the explicitly injected test double. No payer/card PII
 * or credentials may enter a payment request or escape in results.
 *
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class MercadoPagoPaymentGateway implements PaymentGateway
{
    public const SANDBOX_ENDPOINT = 'https://api.mercadopago.com';

    private readonly string $testToken;
    private readonly string $webhookSecret;

    /** @var array<string,array{fingerprint:string,ref:string}> */
    private array $requests = [];
    /** @var array<string,ChargeResult> */
    private array $charges = [];
    /** @var array<string,string> */
    private array $orderRefs = [];
    /** @var array<string,string> */
    private array $idempotencyByRef = [];
    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];
    /** @var array<string,string> */
    private array $refundStatuses = [];
    /** @var Closure():int */
    private readonly Closure $clock;

    /**
     * @param Closure(string,string,array<string,mixed>,string,string):array<string,mixed> $transport
     * @param Closure():int|null $clock
     * The caller must inject an offline transport. No production fallback.
     */
    public function __construct(
        private readonly Closure $transport,
        ?string $tokenFile = null,
        ?string $webhookSecretFile = null,
        ?Closure $clock = null,
    ) {
        $this->testToken = self::readPrivateFile($tokenFile, 'MERCADOPAGO_ACCESS_TOKEN_FILE', true);
        $this->webhookSecret = self::readPrivateFile($webhookSecretFile, 'MERCADOPAGO_WEBHOOK_SECRET_FILE', false);
        $this->clock = $clock ?? time(...);
    }

    public function id(): string { return 'mercadopago'; }

    public function mode(): string { return 'sandbox'; }

    /** @return ChargeResult */
    public function charge(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','order_ref','amount_minor','currency','idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $order = self::identifier($request['order_ref']);
        $idempotency = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $currency = self::currency($request['currency']);
        $scope = $tenant.'/'.$company;
        $idemScope = hash('sha256', $scope.'/'.$idempotency);
        $fingerprint = hash('sha256', json_encode([$tenant,$company,$order,$amount,$currency], JSON_THROW_ON_ERROR));
        $prior = $this->requests[$idemScope] ?? null;
        if ($prior !== null) {
            if (!hash_equals($prior['fingerprint'], $fingerprint)) {
                throw new DomainException('MP_IDEMPOTENCY_CONFLICT');
            }
            return $this->query($tenant, $company, $prior['ref']);
        }
        if (isset($this->orderRefs[$scope.'/'.$order])) {
            throw new DomainException('MP_ORDER_ALREADY_SUBMITTED');
        }
        // The neutral contract has no payer/payment method: no funded request.
        $response = $this->send('POST', '/v1/payments', [
            'transaction_amount' => self::decimal($amount),
            'currency_id' => $currency,
            'external_reference' => $order,
            'metadata' => ['tenant_id' => $tenant, 'company_id' => $company, 'order_ref' => $order],
            'sandbox' => true,
        ], 'charge-'.$idemScope);
        self::assertPayment($response, $tenant, $company, $order, $amount, $currency);
        $result = $this->asCharge($response, $tenant, $company, $idempotency);
        if (isset($this->charges[$result['charge_ref']])) {
            throw new DomainException('MP_PAYMENT_REPLAY');
        }
        $this->requests[$idemScope] = ['fingerprint' => $fingerprint, 'ref' => $result['charge_ref']];
        $this->idempotencyByRef[$result['charge_ref']] = $idempotency;
        $this->orderRefs[$scope.'/'.$order] = $result['charge_ref'];
        $this->charges[$result['charge_ref']] = $result;
        return $result;
    }

    /** @return ChargeResult */
    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenant = self::identifier($tenantId);
        $company = self::identifier($companyId);
        self::paymentRef($chargeRef);
        $existing = $this->charges[$chargeRef] ?? null;
        if ($existing === null || $existing['tenant_id'] !== $tenant || $existing['company_id'] !== $company) {
            throw new DomainException('MP_CHARGE_NOT_FOUND');
        }
        $response = $this->send('GET', '/v1/payments/'.$chargeRef, [], '');
        $order = $this->paymentOrder($tenant, $company, $chargeRef);
        self::assertPayment($response, $tenant, $company, $order, $existing['amount_minor'], $existing['currency'], $chargeRef);
        $result = $this->asCharge($response, $tenant, $company, $this->paymentIdempotency($chargeRef));
        if (isset($this->refundStatuses[$chargeRef])) {
            $result['status'] = $this->refundStatuses[$chargeRef];
            $result['ledger_context'] = null;
        }
        $this->charges[$chargeRef] = $result;
        return $result;
    }

    public function refund(array $request): array
    {
        $fields = ['tenant_id','company_id','charge_ref','amount_minor','idempotency_key'];
        self::shape($request, $fields);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $ref = $request['charge_ref'];
        self::paymentRef($ref);
        $scope = hash('sha256', $tenant.'/'.$company.'/'.$key);
        $fingerprint = hash('sha256', $ref.'/'.$amount);
        $previous = $this->refunds[$scope] ?? null;
        if ($previous !== null) {
            if (!hash_equals($previous['fingerprint'], $fingerprint)) {
                throw new DomainException('MP_IDEMPOTENCY_CONFLICT');
            }
            return $previous['result'];
        }
        $charge = $this->query($tenant, $company, $ref);
        if ($charge['status'] !== 'approved' || $amount !== $charge['amount_minor']) {
            throw new DomainException('MP_REFUND_NOT_ALLOWED');
        }
        $response = $this->send('POST', '/v1/payments/'.$ref.'/refunds', [
            'amount' => self::decimal($amount),
            'sandbox' => true,
        ], 'refund-'.$scope);
        if (array_keys($response) !== ['id','payment_id','amount','status','sandbox']
            || !is_string($response['id']) || preg_match('/^[0-9]{6,40}$/D', $response['id']) !== 1
            || $response['payment_id'] !== $ref || $response['amount'] !== self::decimal($amount)
            || $response['sandbox'] !== true
            || !in_array($response['status'], ['approved','pending','rejected'], true)) {
            throw new DomainException('MP_UNVERIFIABLE_REFUND');
        }
        $state = match ($response['status']) {
            'approved' => 'refunded', 'pending' => 'refund_pending', default => 'refund_failed',
        };
        $result = ['charge_ref' => $ref, 'status' => $state,
            'refunded_minor' => $state === 'refunded' ? $amount : 0,
            'refund_ref' => 'mp_ref_'.$response['id']];
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        if (in_array($state, ['refunded', 'refund_pending'], true)) {
            $this->refundStatuses[$ref] = $state;
        }
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        if (!self::validIdentifier($tenantId) || !self::validIdentifier($companyId)
            || strlen($payload) > 8192 || strlen($signature) > 256) return false;
        // Four-argument neutral contract: request_id comes from a test envelope.
        // A future HTTP controller MUST bind authentic x-request-id and data.id
        // from headers/query; this adapter installs no HTTP controller.
        try {
            $event = json_decode($payload, true, 10, JSON_THROW_ON_ERROR);
        } catch (\JsonException) { return false; }
        if (!is_array($event) || array_keys($event) !== ['type','action','live_mode','data','request_id']
            || $event['type'] !== 'payment' || !in_array($event['action'], ['payment.created','payment.updated'], true)
            || $event['live_mode'] !== false || !is_array($event['data'])
            || array_keys($event['data']) !== ['id'] || !is_string($event['data']['id'])
            || preg_match('/^[0-9]{6,40}$/D', $event['data']['id']) !== 1
            || !is_string($event['request_id'])
            || preg_match('/^[A-Za-z0-9-]{8,100}$/D', $event['request_id']) !== 1) return false;
        if (preg_match('/^ts=([0-9]{10,13}),v1=([a-f0-9]{64})$/D', $signature, $parts) !== 1) return false;
        $ts = $parts[1];
        $seconds = strlen($ts) === 13 ? intdiv((int)$ts, 1000) : (int)$ts;
        if (abs(($this->clock)() - $seconds) > 300) return false;
        $manifest = 'id:'.strtolower($event['data']['id']).';request-id:'.$event['request_id'].';ts:'.$ts.';';
        $expected = hash_hmac('sha256', $manifest, $this->webhookSecret);
        if (!hash_equals($expected, $parts[2])) return false;
        $charge = $this->charges[$event['data']['id']] ?? null;
        return $charge !== null && $charge['tenant_id'] === $tenantId
            && $charge['company_id'] === $companyId && !isset($this->refundStatuses[$event['data']['id']]);
    }

    /** @param array<string,mixed> $response */
    private static function assertPayment(array $response, string $tenant, string $company, string $order, int $amount, string $currency, ?string $ref = null): void
    {
        if (array_keys($response) !== ['id','status','transaction_amount','currency_id','external_reference','metadata','sandbox']
            || !is_string($response['id']) || preg_match('/^[0-9]{6,40}$/D', $response['id']) !== 1
            || ($ref !== null && $response['id'] !== $ref)
            || !in_array($response['status'], ['approved','rejected','pending','in_process','refunded'], true)
            || $response['transaction_amount'] !== self::decimal($amount)
            || $response['currency_id'] !== $currency || $response['external_reference'] !== $order
            || !is_array($response['metadata'])
            || $response['metadata'] !== ['tenant_id' => $tenant,'company_id' => $company,'order_ref' => $order]
            || $response['sandbox'] !== true) {
            throw new DomainException('MP_UNVERIFIABLE_PAYMENT');
        }
    }

    /**
     * @param array<string,mixed> $response
     * @return ChargeResult
     */
    private function asCharge(array $response, string $tenant, string $company, string $idem): array
    {
        $status = match ($response['status']) {
            'approved' => 'approved', 'rejected' => 'rejected', 'refunded' => 'refunded', default => 'pending',
        };
        $amount = self::amountFromDecimal($response['transaction_amount']);
        return ['charge_ref' => $response['id'], 'status' => $status,
            'tenant_id' => $tenant, 'company_id' => $company,
            'amount_minor' => $amount, 'currency' => $response['currency_id'],
            'ledger_context' => $status === 'approved' ? [
                'idempotency_key' => $idem, 'amount_minor' => $amount,
                'method' => 'mercadopago_sandbox', 'currency' => $response['currency_id'],
                'external_ref' => $response['id'],
            ] : null];
    }

    private function paymentOrder(string $tenant, string $company, string $ref): string
    {
        foreach ($this->orderRefs as $scope => $value) {
            if ($value === $ref && str_starts_with($scope, $tenant.'/'.$company.'/')) {
                return substr($scope, strlen($tenant.'/'.$company.'/'));
            }
        }
        throw new DomainException('MP_CHARGE_NOT_FOUND');
    }

    private function paymentIdempotency(string $ref): string
    {
        return $this->idempotencyByRef[$ref] ?? throw new DomainException('MP_CHARGE_NOT_FOUND');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $payload, string $idempotencyKey): array
    {
        $result = ($this->transport)($method, $path, $payload, $this->testToken, $idempotencyKey);
        if ($result === []) {
            throw new DomainException('MP_UNVERIFIABLE_RESPONSE');
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $request
     * @param list<string> $required
     */
    private static function shape(array $request, array $required): void
    {
        $keys = array_keys($request);
        sort($keys); sort($required);
        if ($keys !== $required) throw new DomainException('MP_INVALID_REQUEST');
    }

    private static function validIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $value) === 1;
    }

    private static function identifier(mixed $value): string
    {
        if (!self::validIdentifier($value)) throw new DomainException('MP_INVALID_REFERENCE');
        return $value;
    }

    private static function paymentRef(mixed $ref): void
    {
        if (!is_string($ref) || preg_match('/^[0-9]{6,40}$/D', $ref) !== 1) {
            throw new DomainException('MP_INVALID_PAYMENT_REFERENCE');
        }
    }

    private static function amount(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 9007199254740991) {
            throw new DomainException('MP_INVALID_AMOUNT');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new DomainException('MP_INVALID_CURRENCY');
        }
        return $value;
    }

    private static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function amountFromDecimal(string $value): int
    {
        if (preg_match('/^([0-9]{1,14})\.([0-9]{2})$/D', $value, $m) !== 1) {
            throw new DomainException('MP_INVALID_AMOUNT');
        }
        return self::amount(((int)$m[1] * 100) + (int)$m[2]);
    }

    private static function readPrivateFile(?string $path, string $name, bool $testOnly): string
    {
        $file = $path ?? getenv($name);
        if (!is_string($file) || $file === '' || str_contains($file, "\n") || str_contains($file, "\0")
            || !is_file($file) || is_link($file) || !is_readable($file)) {
            throw new DomainException('MP_PRIVATE_FILE_REQUIRED');
        }
        $perms = fileperms($file);
        if (!is_int($perms) || ($perms & 0077) !== 0 || filesize($file) === false || filesize($file) > 4096) {
            throw new DomainException('MP_PRIVATE_FILE_UNSAFE');
        }
        $value = file_get_contents($file);
        if (!is_string($value)) throw new DomainException('MP_PRIVATE_FILE_UNREADABLE');
        $secret = rtrim($value, "\r\n");
        if (strlen($secret) < 24 || strlen($secret) > 256 || preg_match('/^[A-Za-z0-9_-]+$/D', $secret) !== 1
            || ($testOnly && !str_starts_with($secret, 'TEST-'))) {
            throw new DomainException('MP_SANDBOX_SECRET_INVALID');
        }
        return $secret;
    }
}
