<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;

final readonly class BillingRequest
{
    private const REF_PATTERN = '/^[a-z][a-z0-9:_-]{2,159}$/D';
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/D';
    private const FORBIDDEN_REF_PREFIXES = [
        'card:',
        'credential:',
        'cvv:',
        'email:',
        'name:',
        'pan:',
        'password:',
        'phone:',
        'provider:',
        'secret:',
        'token:',
    ];

    public function __construct(
        private string $tenantRef,
        private string $subscriptionRef,
        private string $quoteRef,
        private int $amountMinor,
        private string $currency,
        private string $idempotencyKey,
    ) {
        self::assertOpaqueRef($tenantRef, 'tenant_ref');
        self::assertOpaqueRef($subscriptionRef, 'subscription_ref');
        self::assertOpaqueRef($quoteRef, 'quote_ref');
        self::assertOpaqueRef($idempotencyKey, 'idempotency_key');

        if ($amountMinor < 1) {
            throw new DomainException('invalid_amount_minor');
        }
        if (preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
            throw new DomainException('invalid_currency');
        }
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        self::assertExactKeys(
            $input,
            [
                'tenant_ref',
                'subscription_ref',
                'quote_ref',
                'amount_minor',
                'currency',
                'idempotency_key',
            ],
        );

        if (
            !is_string($input['tenant_ref'])
            || !is_string($input['subscription_ref'])
            || !is_string($input['quote_ref'])
            || is_bool($input['amount_minor'])
            || !is_int($input['amount_minor'])
            || !is_string($input['currency'])
            || !is_string($input['idempotency_key'])
        ) {
            throw new DomainException('invalid_billing_request_shape');
        }

        return new self(
            $input['tenant_ref'],
            $input['subscription_ref'],
            $input['quote_ref'],
            $input['amount_minor'],
            $input['currency'],
            $input['idempotency_key'],
        );
    }

    /** @return array{tenant_ref:string,subscription_ref:string,quote_ref:string,amount_minor:int,currency:string,idempotency_key:string} */
    public function toArray(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'subscription_ref' => $this->subscriptionRef,
            'quote_ref' => $this->quoteRef,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    public function tenantRef(): string
    {
        return $this->tenantRef;
    }

    public function subscriptionRef(): string
    {
        return $this->subscriptionRef;
    }

    public function quoteRef(): string
    {
        return $this->quoteRef;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $input, array $expected): void
    {
        $actual = array_keys($input);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException('invalid_billing_request_shape');
        }
    }

    private static function assertOpaqueRef(string $value, string $field): void
    {
        if (preg_match(self::REF_PATTERN, $value) !== 1) {
            throw new DomainException('invalid_' . $field);
        }

        $normalized = strtolower($value);
        foreach (self::FORBIDDEN_REF_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                throw new DomainException('sensitive_or_provider_specific_' . $field);
            }
        }
    }
}
