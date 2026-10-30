<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\PayU;

use App\Application\Payments\PaymentGateway;
use Closure;
use DomainException;

/**
 * PayU Latam sandbox protocol adapter. NO transport is built in: a test-only
 * transport must be explicitly injected. Never contacts PayU or handles real
 * card/payer data, and never writes into the real order ledger.
 *
 * @phpstan-type ChargeResult array{charge_ref:string,status:string,tenant_id:string,company_id:string,amount_minor:int,currency:string,ledger_context:array<string,int|string>|null}
 * @phpstan-type RefundResult array{charge_ref:string,status:string,refunded_minor:int,refund_ref:string}
 */
final class PayUPaymentGateway implements PaymentGateway
{
    public const SANDBOX_URL = 'https://sandbox.api.payulatam.com/payments-api/4.0/service.cgi';

    /** @var array<string,array{fingerprint:string,ref:string}> */
    private array $requests = [];

    /** @var array<string,ChargeResult> */
    private array $charges = [];

    /** @var array<string,array{fingerprint:string,result:RefundResult}> */
    private array $refunds = [];

    /** @var array<string,string> */
    private array $saleRefs = [];

    /** @var array<string,string> */
    private array $transactionRefs = [];

    private readonly string $apiKey;
    private readonly string $apiLogin;

    /**
     * Inject only a deterministic, explicitly authorized sandbox transport.
     * No default HTTP fallback exists. Merchant/account IDs are server-side.
     * Credentials MUST come from local private files (*_FILE), never strings.
     *
     * @param Closure(string,array<string,mixed>):array<string,mixed> $transport
     */
    public function __construct(
        private readonly string $merchantId,
        private readonly string $accountId,
        private readonly Closure $transport,
        ?string $apiKeyFile = null,
        ?string $apiLoginFile = null,
    ) {
        if (preg_match('/^[0-9]{4,12}$/D', $merchantId) !== 1
            || preg_match('/^[0-9]{4,12}$/D', $accountId) !== 1) {
            throw new DomainException('PAYU_INVALID_MERCHANT');
        }
        $this->apiKey = self::readPrivateFile($apiKeyFile, 'PAYU_API_KEY_FILE');
        $this->apiLogin = self::readPrivateFile($apiLoginFile, 'PAYU_API_LOGIN_FILE');
    }

    public function id(): string { return 'payu'; }

    public function mode(): string { return 'sandbox'; }

    public function charge(array $request): array
    {
        self::shape($request, ['tenant_id', 'company_id', 'order_ref', 'amount_minor', 'currency', 'idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $order = self::identifier($request['order_ref']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $currency = self::currency($request['currency']);
        $scope = $tenant.'/'.$company;
        $idempotencyScope = $scope.'/'.$key;
        $fingerprint = hash('sha256', json_encode([$tenant, $company, $order, $amount, $currency], JSON_THROW_ON_ERROR));
        if (isset($this->requests[$idempotencyScope])) {
            $previous = $this->requests[$idempotencyScope];
            if (!hash_equals($previous['fingerprint'], $fingerprint)) {
                throw new DomainException('PAYU_IDEMPOTENCY_CONFLICT');
            }
            return $this->charges[$previous['ref']];
        }
        $saleScope = $scope.'/'.$order;
        if (isset($this->saleRefs[$saleScope])) {
            throw new DomainException('PAYU_ORDER_ALREADY_SUBMITTED');
        }
        // The neutral PaymentGateway contract contains no payer/payment method.
        // This is intentionally NOT a live, funded transaction. A registered
        // sandbox double interprets this protocol-only request in CI.
        $response = $this->runSandbox([
            'type' => 'AUTHORIZATION_AND_CAPTURE',
            'order' => [
                'accountId' => $this->accountId,
                'referenceCode' => $order,
                'description' => 'SANDBOX_ONLY',
                'language' => 'es',
                'additionalValues' => ['TX_VALUE' => [
                    'value' => self::decimalValue($amount), 'currency' => $currency,
                ]],
            ],
            'test' => true,
        ]);
        $data = $response['transactionResponse'] ?? null;
        if (!is_array($data) || !isset($data['state'], $data['transactionId'])
            || !is_string($data['state']) || !is_string($data['transactionId'])
            || preg_match('/^[A-Za-z0-9_-]{4,100}$/D', $data['transactionId']) !== 1
            || !in_array($data['state'], ['APPROVED', 'DECLINED'], true)) {
            throw new DomainException('PAYU_SANDBOX_RESPONSE_UNKNOWN');
        }
        $state = $data['state'] === 'APPROVED' ? 'approved' : 'rejected';
        $ref = 'payu_'.hash('sha256', $scope.'/'.$data['transactionId']);
        if (isset($this->charges[$ref])) {
            throw new DomainException('PAYU_TRANSACTION_REPLAY');
        }
        /** @var ChargeResult $result */
        $result = [
            'charge_ref' => $ref, 'status' => $state,
            'tenant_id' => $tenant, 'company_id' => $company,
            'amount_minor' => $amount, 'currency' => $currency,
            'ledger_context' => $state === 'approved' ? [
                'idempotency_key' => $key, 'amount_minor' => $amount,
                'method' => 'payu_sandbox', 'currency' => $currency,
                'external_ref' => $ref,
            ] : null,
        ];
        $this->charges[$ref] = $result;
        $this->transactionRefs[$ref] = $data['transactionId'];
        $this->requests[$idempotencyScope] = ['fingerprint' => $fingerprint, 'ref' => $ref];
        $this->saleRefs[$saleScope] = $ref;
        return $result;
    }

    public function query(string $tenantId, string $companyId, string $chargeRef): array
    {
        $tenantId = self::identifier($tenantId);
        $companyId = self::identifier($companyId);
        $charge = $this->charges[$chargeRef] ?? null;
        if ($charge === null || !hash_equals($charge['tenant_id'], $tenantId)
            || !hash_equals($charge['company_id'], $companyId)) {
            throw new DomainException('PAYU_CHARGE_NOT_FOUND');
        }
        return $charge;
    }

    public function refund(array $request): array
    {
        self::shape($request, ['tenant_id', 'company_id', 'charge_ref', 'amount_minor', 'idempotency_key']);
        $tenant = self::identifier($request['tenant_id']);
        $company = self::identifier($request['company_id']);
        $key = self::identifier($request['idempotency_key']);
        $amount = self::amount($request['amount_minor']);
        $ref = $request['charge_ref'];
        if (preg_match('/^payu_[a-f0-9]{64}$/D', $ref) !== 1) {
            throw new DomainException('PAYU_INVALID_REFERENCE');
        }
        $charge = $this->query($tenant, $company, $ref);
        $scope = $tenant.'/'.$company.'/'.$key;
        $fingerprint = hash('sha256', $ref.'/'.$amount);
        if (isset($this->refunds[$scope])) {
            if (!hash_equals($this->refunds[$scope]['fingerprint'], $fingerprint)) {
                throw new DomainException('PAYU_IDEMPOTENCY_CONFLICT');
            }
            return $this->refunds[$scope]['result'];
        }
        if ($charge['status'] !== 'approved' || $charge['amount_minor'] !== $amount) {
            throw new DomainException('PAYU_REFUND_NOT_ALLOWED');
        }
        // An actual PayU transaction ID was returned by the test transport.
        // The sandbox-only request still lacks a funded payment method.
        $response = $this->runSandbox([
            'type' => 'REFUND', 'order' => ['accountId' => $this->accountId],
            'parentTransactionId' => $this->transactionRefs[$ref],
            'reason' => 'SANDBOX_ONLY', 'test' => true,
        ]);
        if (($response['transactionResponse']['state'] ?? null) !== 'APPROVED') {
            throw new DomainException('PAYU_REFUND_NOT_CONFIRMED');
        }
        $updated = $charge;
        $updated['status'] = 'refunded';
        $updated['ledger_context'] = null;
        $this->charges[$ref] = $updated;
        /** @var RefundResult $result */
        $result = [
            'charge_ref' => $ref, 'status' => 'refunded', 'refunded_minor' => $amount,
            'refund_ref' => 'payuref_'.substr(hash('sha256', $scope.'/'.$ref), 0, 32),
        ];
        $this->refunds[$scope] = ['fingerprint' => $fingerprint, 'result' => $result];
        return $result;
    }

    public function verifyWebhook(string $tenantId, string $companyId, string $payload, string $signature): bool
    {
        if (!self::validIdentifier($tenantId) || !self::validIdentifier($companyId)
            || strlen($payload) > 8192 || strlen($payload) === 0
            || preg_match('/^[a-fA-F0-9]{32}$/D', $signature) !== 1) {
            return false;
        }
        // PayU Confirmation URL sends application/x-www-form-urlencoded POST.
        // Parse strictly: reject duplicate parameters and parameter arrays.
        $values = [];
        foreach (explode('&', $payload) as $entry) {
            if (substr_count($entry, '=') < 1) {
                return false;
            }
            [$name, $value] = explode('=', $entry, 2);
            $name = urldecode($name);
            if (isset($values[$name]) || str_contains($name, '[') || strlen($name) > 100) {
                return false;
            }
            $values[$name] = urldecode($value);
        }
        foreach (['merchant_id', 'reference_sale', 'value', 'currency', 'state_pol'] as $field) {
            if (!isset($values[$field])) {
                return false;
            }
        }
        if (!hash_equals($this->merchantId, $values['merchant_id'])
            || !self::validIdentifier($values['reference_sale'])
            || preg_match('/^[A-Z]{3}$/D', $values['currency']) !== 1
            || !in_array($values['state_pol'], ['4', '5', '6'], true)
            || preg_match('/^[0-9]{1,13}(?:\.[0-9]{1,2})?$/D', $values['value']) !== 1) {
            return false;
        }
        $parts = explode('.', $values['value']);
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        $minor = (int) $parts[0] * 100 + (int) $fraction;
        if ($minor <= 0 || $minor > 9007199254740991) {
            return false;
        }
        $formatted = $parts[0].'.'.(isset($parts[1]) && strlen($parts[1]) === 2 && $parts[1][1] !== '0'
            ? $parts[1] : substr($fraction, 0, 1));
        $expected = md5(implode('~', [
            $this->apiKey, $this->merchantId, $values['reference_sale'],
            $formatted, $values['currency'], $values['state_pol'],
        ]));
        if (!hash_equals($expected, strtolower($signature))) {
            return false;
        }
        $ref = $this->saleRefs[$tenantId.'/'.$companyId.'/'.$values['reference_sale']] ?? null;
        $charge = $ref !== null ? ($this->charges[$ref] ?? null) : null;
        $state = ['4' => 'approved', '5' => 'expired', '6' => 'rejected'][$values['state_pol']];
        return $charge !== null && $charge['amount_minor'] === $minor
            && hash_equals($charge['currency'], $values['currency'])
            && hash_equals($charge['status'], $state);
    }

    /**
     * @param array<string,mixed> $transaction
     * @return array<string,mixed>
     */
    private function runSandbox(array $transaction): array
    {
        return ($this->transport)(self::SANDBOX_URL, [
            'language' => 'es',
            'command' => 'SUBMIT_TRANSACTION',
            'test' => true,
            'merchant' => ['apiLogin' => $this->apiLogin, 'apiKey' => $this->apiKey],
            'transaction' => $transaction,
        ]);
    }

    private static function readPrivateFile(?string $path, string $env): string
    {
        $path ??= getenv($env) ?: '';
        if ($path === '' || str_contains($path, '://') || !is_file($path)
            || is_link($path) || !is_readable($path)) {
            throw new DomainException('PAYU_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        $value = file_get_contents($path);
        if (!is_string($value) || strlen($value) > 256) {
            throw new DomainException('PAYU_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        $value = trim($value);
        if (strlen($value) < 12 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new DomainException('PAYU_SANDBOX_CREDENTIAL_UNAVAILABLE');
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $keys
     */
    private static function shape(array $value, array $keys): void
    {
        if (array_keys($value) !== $keys) {
            throw new DomainException('PAYU_INVALID_PAYLOAD');
        }
    }

    private static function validIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $value) === 1;
    }

    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || !self::validIdentifier($value)) {
            throw new DomainException('PAYU_INVALID_REFERENCE');
        }
        return $value;
    }

    private static function amount(mixed $value): int
    {
        if (!is_int($value) || $value <= 0 || $value > 9007199254740991) {
            throw new DomainException('PAYU_INVALID_AMOUNT');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new DomainException('PAYU_INVALID_CURRENCY');
        }
        return $value;
    }

    private static function decimalValue(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
