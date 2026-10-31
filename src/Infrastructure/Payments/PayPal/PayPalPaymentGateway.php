<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\PayPal;

use App\Application\Payments\PaymentGateway;
use Closure;
use DomainException;
use JsonException;

/**
 * PayPal Orders v2 / Payments v2 sandbox protocol adapter.
 * No HTTP transport exists here. The caller MUST inject an offline transport;
 * no payer/card data, funded transaction or live credentials can be used.
 * Webhook verification is delegated to the injected sandbox postback verifier.
 *
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class PayPalPaymentGateway implements PaymentGateway
{
    /** @var array<string,array{fingerprint:string,ref:string}> */
    private array $requests = [];
    /** @var array<string,array{result:ChargeResult,order:string,capture:string,invoice:string}> */
    private array $charges = [];
    /** @var array<string,bool> */
    private array $orders = [];
    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];
    /** @var array<string,bool> */
    private array $transmissions = [];
    /** @var array<string,bool> */
    private array $events = [];
    private readonly string $clientSecret;
    private readonly string $webhookId;
    private readonly Closure $clock;

    /** @param Closure(string,array<string,mixed>):array<string,mixed> $transport */
    public function __construct(
        private readonly string $clientId,
        private readonly Closure $transport,
        ?string $clientSecretFile = null,
        ?string $webhookIdFile = null,
        ?Closure $clock = null,
    ) {
        if (preg_match('/^test_[A-Za-z0-9_-]{8,160}$/D', $clientId) !== 1) {
            throw new DomainException('PAYPAL_SANDBOX_CLIENT_REQUIRED');
        }
        $this->clientSecret = self::secret($clientSecretFile, 'PAYPAL_CLIENT_SECRET_FILE');
        $this->webhookId = self::secret($webhookIdFile, 'PAYPAL_WEBHOOK_ID_FILE');
        $this->clock = $clock ?? time(...);
    }

    public function id(): string { return 'paypal'; }
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
            throw new DomainException('PAYPAL_INVALID_SCENARIO');
        }
        $scope = $tenant.'/'.$company;
        $idempotency = $scope.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$order,$amount,$currency,$scenario], JSON_THROW_ON_ERROR));
        if (isset($this->requests[$idempotency])) {
            $prev = $this->requests[$idempotency];
            if (!hash_equals($prev['fingerprint'], $fingerprint)) {
                throw new DomainException('PAYPAL_IDEMPOTENCY_CONFLICT');
            }
            return $this->charges[$prev['ref']]['result'];
        }
        if (isset($this->orders[$scope.'/'.$order])) {
            throw new DomainException('PAYPAL_ORDER_ALREADY_SUBMITTED');
        }
        // Real PayPal needs buyer approval; this strictly synthetic response
        // models the outcome WITHOUT creating/capturing a real PayPal order.
        $response = ($this->transport)('create_order', [
            'path' => '/v2/checkout/orders', 'sandbox' => true, 'intent' => 'CAPTURE',
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            'invoice_id' => $order, 'custom_id' => $scope,
            'amount' => ['currency_code' => $currency, 'value' => self::decimal($amount)],
            'scenario' => $scenario, 'request_id' => $idempotency,
        ]);
        $capture = $response['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (($response['sandbox'] ?? null) !== true
            || ($response['intent'] ?? null) !== 'CAPTURE'
            || !self::providerId($response['id'] ?? null)
            || !is_array($capture)
            || !self::providerId($capture['id'] ?? null)
            || ($response['purchase_units'][0]['invoice_id'] ?? null) !== $order
            || ($response['purchase_units'][0]['custom_id'] ?? null) !== $scope
            || ($capture['amount']['currency_code'] ?? null) !== $currency
            || ($capture['amount']['value'] ?? null) !== self::decimal($amount)
            || ($capture['status'] ?? null) !== ($scenario === 'approve' ? 'COMPLETED' : 'DECLINED')) {
            throw new DomainException('PAYPAL_UNVERIFIABLE_SANDBOX_RESPONSE');
        }
        $captureId = $capture['id'];
        foreach ($this->charges as $existing) {
            if ($existing['capture'] === $captureId || $existing['order'] === $response['id']) {
                throw new DomainException('PAYPAL_CAPTURE_REPLAY');
            }
        }
        $ref = 'paypal_'.hash('sha256', $scope.'/'.$response['id'].'/'.$captureId);
        $approved = $scenario === 'approve';
        /** @var ChargeResult $result */
        $result = [
            'charge_ref' => $ref, 'status' => $approved ? 'approved' : 'rejected',
            'tenant_id' => $tenant, 'company_id' => $company,
            'amount_minor' => $amount, 'currency' => $currency,
            'ledger_context' => $approved ? [
                'idempotency_key' => $key, 'amount_minor' => $amount,
                'method' => 'paypal_sandbox', 'currency' => $currency,
                'external_ref' => $ref,
            ] : null,
        ];
        $this->charges[$ref] = [
            'result' => $result, 'order' => $response['id'],
            'capture' => $captureId, 'invoice' => $order,
        ];
        $this->requests[$idempotency] = ['fingerprint' => $fingerprint, 'ref' => $ref];
        $this->orders[$scope.'/'.$order] = true;
        return $result;
    }

    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenant = self::identifier($tenantId);
        $company = self::identifier($companyId);
        $entry = $this->charges[$chargeRef] ?? null;
        if ($entry === null || $entry['result']['tenant_id'] !== $tenant
            || $entry['result']['company_id'] !== $company) {
            throw new DomainException('PAYPAL_CHARGE_NOT_FOUND');
        }
        return $entry['result'];
    }

    public function refund(array $request): array
    {
        self::shape($request, ['tenant_id','company_id','charge_ref','amount_minor','idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $ref = self::chargeRef($request['charge_ref']);
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$ref,$amount], JSON_THROW_ON_ERROR));
        if (isset($this->refunds[$scope])) {
            $previous = $this->refunds[$scope];
            if (!hash_equals($previous['fingerprint'], $fingerprint)) {
                throw new DomainException('PAYPAL_IDEMPOTENCY_CONFLICT');
            }
            return $previous['result'];
        }
        $charge = $this->query($tenant, $company, $ref);
        if ($charge['status'] !== 'approved' || $amount !== $charge['amount_minor']) {
            throw new DomainException('PAYPAL_REFUND_NOT_ALLOWED');
        }
        $response = ($this->transport)('refund_capture', [
            'path' => '/v2/payments/captures/'.$this->charges[$ref]['capture'].'/refund',
            'sandbox' => true, 'capture_id' => $this->charges[$ref]['capture'],
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            'amount' => ['currency_code' => $charge['currency'], 'value' => self::decimal($amount)],
            'request_id' => $scope,
        ]);
        if (($response['sandbox'] ?? null) !== true
            || ($response['status'] ?? null) !== 'COMPLETED'
            || !self::providerId($response['id'] ?? null)
            || ($response['capture_id'] ?? null) !== $this->charges[$ref]['capture']
            || ($response['amount']['currency_code'] ?? null) !== $charge['currency']
            || ($response['amount']['value'] ?? null) !== self::decimal($amount)) {
            throw new DomainException('PAYPAL_UNVERIFIABLE_REFUND');
        }
        /** @var RefundResult $result */
        $result = ['charge_ref' => $ref, 'status' => 'refunded',
            'refunded_minor' => $amount, 'refund_ref' => $response['id']];
        $this->charges[$ref]['result']['status'] = 'refunded';
        $this->charges[$ref]['result']['ledger_context'] = null;
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        if (strlen($payload) < 2 || strlen($payload) > 16384 || strlen($signature) > 2048) return false;
        try {
            $tenant = self::identifier($tenantId);
            $company = self::identifier($companyId);
            // The neutral signature argument contains the five PayPal HTTP
            // verification headers as JSON; never treat it as an HMAC secret.
            $headers = json_decode($signature, true, 8, JSON_THROW_ON_ERROR);
            $event = json_decode($payload, true, 12, JSON_THROW_ON_ERROR);
            if (!is_array($headers) || !is_array($event)
                || count($headers) !== 5
                || array_diff(['transmission_id','transmission_time','cert_url','auth_algo','transmission_sig'], array_keys($headers)) !== []
                || !self::providerId($headers['transmission_id'])
                || !is_string($headers['transmission_sig'])
                || preg_match('~^[A-Za-z0-9+/]{32,1024}={0,2}$~D', $headers['transmission_sig']) !== 1
                || !is_string($headers['transmission_time'])
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $headers['transmission_time']) !== 1
                || ($when = strtotime($headers['transmission_time'])) === false
                || abs(($this->clock)() - $when) > 300
                || $headers['auth_algo'] !== 'SHA256withRSA'
                || !is_string($headers['cert_url'])
                || preg_match('~^https://api\.sandbox\.paypal\.com/v1/notifications/certs/CERT-[A-Za-z0-9_-]{8,150}$~D', $headers['cert_url']) !== 1
                || !self::providerId($event['id'] ?? null)
                || !in_array($event['event_type'] ?? null, ['PAYMENT.CAPTURE.COMPLETED','PAYMENT.CAPTURE.DENIED'], true)
                || !is_array($event['resource'] ?? null)
                || !self::providerId($event['resource']['id'] ?? null)
                || !self::providerId($event['resource']['supplementary_data']['related_ids']['order_id'] ?? null)) return false;
            $scope = $tenant.'/'.$company;
            $entry = null;
            foreach ($this->charges as $charge) {
                if ($charge['result']['tenant_id'] === $tenant && $charge['result']['company_id'] === $company
                    && $charge['capture'] === $event['resource']['id']
                    && $charge['order'] === $event['resource']['supplementary_data']['related_ids']['order_id']) {
                    $entry = $charge;
                    break;
                }
            }
            if ($entry === null || ($event['resource']['amount']['currency_code'] ?? null) !== $entry['result']['currency']
                || ($event['resource']['amount']['value'] ?? null) !== self::decimal($entry['result']['amount_minor'])
                || ($event['resource']['custom_id'] ?? null) !== $scope
                || ($event['resource']['invoice_id'] ?? null) !== $entry['invoice']
                || $entry['result']['status'] !== ($event['event_type'] === 'PAYMENT.CAPTURE.COMPLETED' ? 'approved' : 'rejected')
                || isset($this->transmissions[$headers['transmission_id']])
                || isset($this->events[$event['id']])) return false;
            // PayPal's official postback protocol verifies the raw event and
            // five headers. Never trust the event or a syntactically valid sig.
            $verification = ($this->transport)('verify_webhook', [
                'path' => '/v1/notifications/verify-webhook-signature',
                'sandbox' => true, 'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'webhook_id' => $this->webhookId,
                'transmission_id' => $headers['transmission_id'],
                'transmission_time' => $headers['transmission_time'],
                'cert_url' => $headers['cert_url'],
                'auth_algo' => $headers['auth_algo'],
                'transmission_sig' => $headers['transmission_sig'],
                'webhook_event' => $event,
            ]);
            if (($verification['verification_status'] ?? null) !== 'SUCCESS') return false;
            $this->transmissions[$headers['transmission_id']] = true;
            $this->events[$event['id']] = true;
            return true;
        } catch (DomainException|JsonException) {
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
        if (array_diff($required, $keys) !== [] || array_diff($keys, [...$required,...$optional]) !== []) {
            throw new DomainException('PAYPAL_INVALID_REQUEST');
        }
    }
    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $value) !== 1) {
            throw new DomainException('PAYPAL_INVALID_IDENTIFIER');
        }
        return $value;
    }
    private static function chargeRef(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^paypal_[a-f0-9]{64}$/D', $value) !== 1) {
            throw new DomainException('PAYPAL_INVALID_REFERENCE');
        }
        return $value;
    }
    private static function providerId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{4,120}$/D', $value) === 1;
    }
    private static function currency(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['USD','EUR','GBP'], true)) {
            throw new DomainException('PAYPAL_UNSUPPORTED_CURRENCY');
        }
        return $value;
    }
    private static function amount(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 999999999999) {
            throw new DomainException('PAYPAL_INVALID_AMOUNT');
        }
        return $value;
    }
    private static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }
    private static function secret(?string $path, string $name): string
    {
        $path ??= getenv($name) ?: '';
        if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)
            || is_link($path) || !is_file($path) || !is_readable($path)
            || (fileperms($path) & 0077) !== 0 || filesize($path) === false || filesize($path) > 512) {
            throw new DomainException('PAYPAL_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        $real = realpath($path);
        $root = realpath(dirname(__DIR__, 4));
        if ($real === false || ($root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR))) {
            throw new DomainException('PAYPAL_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        $value = file_get_contents($real);
        if (!is_string($value) || preg_match('/^test_[A-Za-z0-9_-]{12,240}$/D', trim($value)) !== 1) {
            throw new DomainException('PAYPAL_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        return trim($value);
    }
}
