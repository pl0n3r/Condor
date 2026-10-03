<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;

final readonly class BillingIdempotencyRecord
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9:_-]{2,159}$/D';
    private const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/D';
    private const FORBIDDEN_KEY_PREFIXES = [
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
        private string $idempotencyKey,
        private string $requestFingerprint,
        private BillingResult $result,
    ) {
        if (preg_match(self::KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new DomainException('invalid_idempotency_key');
        }

        $normalizedKey = strtolower($idempotencyKey);
        foreach (self::FORBIDDEN_KEY_PREFIXES as $prefix) {
            if (str_starts_with($normalizedKey, $prefix)) {
                throw new DomainException('sensitive_or_provider_specific_idempotency_key');
            }
        }

        if (preg_match(self::FINGERPRINT_PATTERN, $requestFingerprint) !== 1) {
            throw new DomainException('invalid_billing_request_fingerprint');
        }
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function requestFingerprint(): string
    {
        return $this->requestFingerprint;
    }

    public function result(): BillingResult
    {
        return $this->result;
    }
}
