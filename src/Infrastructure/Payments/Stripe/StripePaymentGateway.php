<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Stripe;

use App\Application\Payments\PaymentGateway;
use Closure;
use DomainException;

/**
 * Stripe PaymentIntents adapter. Sandbox only; the registry supplies the
 * company allowlist, while each response is independently scope-checked.
 * Amounts are integer minor units; no customer or card data enter this API.
 *
 * Transport receives (method, path, form parameters, test key, idempotency key)
 * and returns decoded Stripe JSON. Tests inject an offline transport.
 *
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class StripePaymentGateway implements PaymentGateway
{
    private readonly string $apiKey;
    private readonly string $webhookSecret;
    private readonly Closure $transport;
    private readonly Closure $clock;

    /** @var array<string,array{fingerprint:string,result:ChargeResult}> */
    private array $charges = [];
    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];
    /** @var array<string,string> */
    private array $refundStatus = [];

    public function __construct(?callable $transport = null, ?callable $clock = null)
    {
        $this->apiKey = self::privateSecret('STRIPE_SECRET_KEY_FILE', '/^sk_test_[A-Za-z0-9]{16,}$/D');
        $this->webhookSecret = self::privateSecret('STRIPE_WEBHOOK_SECRET_FILE', '/^whsec_[A-Za-z0-9]{16,}$/D');
        $this->transport = $transport === null ? self::sandboxTransport(...) : Closure::fromCallable($transport);
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
    }

    public function id(): string { return 'stripe'; }

    public function charge(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','order_ref','amount_minor','currency','idempotency_key'], ['scenario']);
        $scenario = $request['scenario'] ?? 'approve';
        $currency = self::currency($request['currency']);
        $amount = self::amount($request['amount_minor']);
        $key = self::identifier($request['idempotency_key']);
        $order = self::identifier($request['order_ref']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        if (!in_array($scenario, ['approve', 'reject'], true)) {
            throw new DomainException('GATEWAY_INVALID_SCENARIO');
        }
        $scope = hash('sha256', $tenant.'/'.$company.'/'.$key);
        $fingerprint = hash('sha256', json_encode([$tenant,$company,$order,$amount,$currency,$scenario], JSON_THROW_ON_ERROR));
        if (isset($this->charges[$scope])) {
            if (!hash_equals($this->charges[$scope]['fingerprint'], $fingerprint)) {
                throw new DomainException('GATEWAY_IDEMPOTENCY_CONFLICT');
            }
            return $this->query($tenant, $company, $this->charges[$scope]['result']['charge_ref']);
        }
        $response = $this->send('POST', '/v1/payment_intents', [
            'amount' => $amount, 'currency' => strtolower($currency), 'confirm' => 'true',
            'payment_method' => $scenario === 'approve' ? 'pm_card_visa' : 'pm_card_chargeDeclined',
            'payment_method_types' => ['card'],
            'metadata' => ['tenant_id' => $tenant, 'company_id' => $company, 'order_ref' => $order],
        ], 'charge_'.$scope);
        // A test-card decline may arrive as a Stripe 402 error containing its intent.
        $intent = $response['error']['payment_intent'] ?? $response;
        if (!is_array($intent)) {
            throw new DomainException('STRIPE_UNVERIFIABLE_RESPONSE');
        }
        self::assertIntent($intent, $tenant, $company, $amount, $currency, $order);
        $result = $this->result($intent, $tenant, $company);
        $this->charges[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        return $result;
    }

    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenant = self::identifier($tenantId);
        $company = self::identifier($companyId);
        if (!preg_match('/^pi_[A-Za-z0-9]{8,120}$/D', $chargeRef)) {
            throw new DomainException('GATEWAY_INVALID_REF');
        }
        $intent = $this->send('GET', '/v1/payment_intents/'.$chargeRef, []);
        self::assertIntent($intent, $tenant, $company);
        $result = $this->result($intent, $tenant, $company);
        if (isset($this->refundStatus[$chargeRef])) {
            $result['status'] = $this->refundStatus[$chargeRef];
            $result['ledger_context'] = null;
        }
        return $result;
    }

    public function refund(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','charge_ref','amount_minor','idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $ref = $request['charge_ref'];
        if (!preg_match('/^pi_[A-Za-z0-9]{8,120}$/D', $ref)) {
            throw new DomainException('GATEWAY_INVALID_REF');
        }
        $scope = hash('sha256', $tenant.'/'.$company.'/'.$key);
        $fingerprint = hash('sha256', json_encode([$ref, $amount], JSON_THROW_ON_ERROR));
        if (isset($this->refunds[$scope])) {
            if (!hash_equals($this->refunds[$scope]['fingerprint'], $fingerprint)) {
                throw new DomainException('GATEWAY_IDEMPOTENCY_CONFLICT');
            }
            return $this->refunds[$scope]['result'];
        }
        $intent = $this->query($tenant, $company, $ref);
        if ($intent['status'] !== 'approved' || $intent['amount_minor'] !== $amount) {
            throw new DomainException('GATEWAY_REFUND_NOT_ALLOWED');
        }
        $response = $this->send('POST', '/v1/refunds', [
            'payment_intent' => $ref, 'amount' => $amount,
            'metadata' => ['tenant_id' => $tenant, 'company_id' => $company],
        ], 'refund_'.$scope);
        if (($response['object'] ?? null) !== 'refund' || ($response['livemode'] ?? null) !== false
            || !is_string($response['id'] ?? null) || !preg_match('/^re_[A-Za-z0-9]{8,120}$/D', $response['id'])
            || ($response['payment_intent'] ?? null) !== $ref || ($response['amount'] ?? null) !== $amount
            || ($response['metadata']['tenant_id'] ?? null) !== $tenant
            || ($response['metadata']['company_id'] ?? null) !== $company
            || !in_array($response['status'] ?? null, ['succeeded', 'pending', 'failed', 'canceled'], true)) {
            throw new DomainException('STRIPE_UNVERIFIABLE_RESPONSE');
        }
        $status = match ($response['status']) {
            'succeeded' => 'refunded', 'pending' => 'refund_pending', default => 'refund_failed',
        };
        /** @var RefundResult $result */
        $result = [
            'charge_ref' => $ref, 'status' => $status,
            'refunded_minor' => $status === 'refunded' ? $amount : 0,
            'refund_ref' => $response['id'],
        ];
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        if (in_array($status, ['refunded', 'refund_pending'], true)) {
            $this->refundStatus[$ref] = $status;
        }
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        if (!self::validId($tenantId) || !self::validId($companyId) || strlen($payload) > 16384
            || strlen($signature) > 1024 || str_contains($signature, "\n") || str_contains($signature, "\r")) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signature) as $part) {
            $item = explode('=', trim($part), 2);
            if (count($item) !== 2) return false;
            if ($item[0] === 't') {
                if ($timestamp !== null || !preg_match('/^[0-9]{1,12}$/D', $item[1])) return false;
                $timestamp = (int) $item[1];
            } elseif ($item[0] === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $item[1])) {
                $signatures[] = $item[1];
                if (count($signatures) > 5) return false;
            } else {
                return false;
            }
        }
        if ($timestamp === null || $signatures === [] || abs(($this->clock)() - $timestamp) > 300) return false;
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);
        if (!array_any($signatures, static fn(string $value): bool => hash_equals($expected, $value))) return false;
        try {
            $event = json_decode($payload, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        $obj = is_array($event) ? ($event['data']['object'] ?? null) : null;
        return is_array($obj) && ($event['livemode'] ?? null) === false
            && in_array($event['type'] ?? null, ['payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.refunded'], true)
            && ($obj['livemode'] ?? null) === false
            && ($obj['metadata']['tenant_id'] ?? null) === $tenantId
            && ($obj['metadata']['company_id'] ?? null) === $companyId
            && is_string($obj['id'] ?? null)
            && preg_match('/^(pi_|ch_)[A-Za-z0-9]{8,120}$/D', $obj['id']) === 1;
    }

    public function mode(): string { return 'sandbox'; }

    /** @param array<string,mixed> $intent */
    private static function assertIntent(array $intent, string $tenant, string $company, ?int $amount = null, ?string $currency = null, ?string $order = null): void
    {
        if (($intent['object'] ?? null) !== 'payment_intent' || ($intent['livemode'] ?? null) !== false
            || !is_string($intent['id'] ?? null) || !preg_match('/^pi_[A-Za-z0-9]{8,120}$/D', $intent['id'])
            || ($intent['metadata']['tenant_id'] ?? null) !== $tenant
            || ($intent['metadata']['company_id'] ?? null) !== $company
            || ($amount !== null && ($intent['amount'] ?? null) !== $amount)
            || ($currency !== null && ($intent['currency'] ?? null) !== strtolower($currency))
            || ($order !== null && ($intent['metadata']['order_ref'] ?? null) !== $order)
            || !array_key_exists('amount', $intent) || !is_int($intent['amount']) || $intent['amount'] <= 0
            || !is_string($intent['currency'] ?? null) || preg_match('/^[a-z]{3}$/D', $intent['currency']) !== 1
            || !in_array($intent['status'] ?? null, ['succeeded','requires_payment_method','canceled','processing','requires_action'], true)) {
            throw new DomainException('STRIPE_UNVERIFIABLE_RESPONSE');
        }
    }

    /**
     * @param array<string,mixed> $intent
     * @return ChargeResult
     */
    private function result(array $intent, string $tenant, string $company): array
    {
        $status = match ($intent['status']) {
            'succeeded' => 'approved', 'requires_payment_method', 'canceled' => 'rejected', default => 'pending',
        };
        $ref = $intent['id'];
        return [
            'charge_ref' => $ref, 'status' => $status, 'tenant_id' => $tenant, 'company_id' => $company,
            'amount_minor' => $intent['amount'], 'currency' => strtoupper($intent['currency']),
            'ledger_context' => $status === 'approved' ? [
                'idempotency_key' => 'stripe_'.substr(hash('sha256', $ref), 0, 32),
                'amount_minor' => $intent['amount'], 'method' => 'stripe',
                'currency' => strtoupper($intent['currency']), 'external_ref' => $ref,
            ] : null,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $params, string $idempotencyKey = ''): array
    {
        $response = ($this->transport)($method, $path, $params, $this->apiKey, $idempotencyKey);
        if (!is_array($response)) throw new DomainException('STRIPE_UNVERIFIABLE_RESPONSE');
        return $response;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function sandboxTransport(string $method, string $path, array $data, string $apiKey, string $idempotencyKey): array
    {
        if (!function_exists('curl_init') || !preg_match('~^/v1/(payment_intents(?:/pi_[A-Za-z0-9]{8,120})?|refunds)$~D', $path)) {
            throw new DomainException('STRIPE_SANDBOX_TRANSPORT_UNAVAILABLE');
        }
        $curl = curl_init('https://api.stripe.com'.$path);
        if ($curl === false) throw new DomainException('STRIPE_SANDBOX_TRANSPORT_UNAVAILABLE');
        $headers = ['Authorization: Bearer '.$apiKey, 'Stripe-Version: 2024-06-20'];
        if ($idempotencyKey !== '') $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        } elseif ($method !== 'GET') {
            curl_close($curl);
            throw new DomainException('STRIPE_INVALID_METHOD');
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body) || strlen($body) > 131072 || $code < 200 || $code >= 500) {
            throw new DomainException('STRIPE_SANDBOX_UNAVAILABLE');
        }
        try {
            $json = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('STRIPE_UNVERIFIABLE_RESPONSE');
        }
        if (!is_array($json) || ($code >= 400 && !isset($json['error']['payment_intent']))) {
            throw new DomainException('STRIPE_SANDBOX_REJECTED');
        }
        return $json;
    }

    private static function privateSecret(string $name, string $pattern): string
    {
        $path = getenv($name);
        if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || !is_readable($path)
            || filesize($path) === false || filesize($path) > 2048) {
            throw new DomainException('STRIPE_PRIVATE_FILE_REQUIRED');
        }
        $real = realpath($path);
        $root = realpath(dirname(__DIR__, 4));
        if ($real === false || ($root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR))) {
            throw new DomainException('STRIPE_PRIVATE_FILE_REQUIRED');
        }
        $secret = file_get_contents($real);
        if (!is_string($secret) || preg_match($pattern, rtrim($secret, "\r\n")) !== 1) {
            throw new DomainException('STRIPE_SANDBOX_SECRET_INVALID');
        }
        return rtrim($secret, "\r\n");
    }

    /**
     * @param array<string,mixed> $request
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function shape(array $request, array $required, array $optional = []): void
    {
        $keys = array_keys($request);
        if (count(array_diff($required, $keys)) > 0
            || count(array_diff($keys, array_merge($required, $optional))) > 0) {
            throw new DomainException('GATEWAY_REQUEST_SHAPE_INVALID');
        }
    }

    private static function validId(string $s): bool { return preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $s) === 1; }
    private static function identifier(mixed $v): string
    {
        if (!is_string($v) || !self::validId($v)) throw new DomainException('GATEWAY_INVALID_REFERENCE');
        return $v;
    }
    private static function amount(mixed $v): int
    {
        if (!is_int($v) || $v <= 0 || $v > 9007199254740991) throw new DomainException('GATEWAY_INVALID_AMOUNT');
        return $v;
    }
    private static function currency(mixed $v): string
    {
        if (!is_string($v) || preg_match('/^[A-Z]{3}$/D', $v) !== 1) throw new DomainException('GATEWAY_INVALID_CURRENCY');
        return $v;
    }
}
