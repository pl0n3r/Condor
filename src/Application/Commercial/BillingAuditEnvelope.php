<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DomainException;
use JsonException;

final readonly class BillingAuditEnvelope
{
    private const OUTCOMES = ['prepared', 'accepted', 'rejected', 'unknown'];
    private const REF_PATTERN = '/^[a-z][a-z0-9:_-]{2,159}$/D';
    private const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/D';
    private const FORBIDDEN_SENSITIVE_PREFIXES = [
        'card:',
        'credential:',
        'cvv:',
        'email:',
        'name:',
        'pan:',
        'password:',
        'phone:',
        'secret:',
        'token:',
    ];

    public function __construct(
        private string $tenantRef,
        private string $idempotencyKey,
        private string $requestFingerprint,
        private string $outcome,
        private ?string $providerRef,
        private string $evidenceRef,
        private string $observedAt,
    ) {
        self::assertOpaqueRef($tenantRef, 'tenant_ref', true);
        self::assertOpaqueRef($idempotencyKey, 'idempotency_key', true);

        if (preg_match(self::FINGERPRINT_PATTERN, $requestFingerprint) !== 1) {
            throw new DomainException('invalid_billing_audit_request_fingerprint');
        }

        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException('invalid_billing_audit_outcome');
        }

        if ($providerRef !== null) {
            self::assertOpaqueRef($providerRef, 'provider_ref', false);
        }

        self::assertOpaqueRef($evidenceRef, 'evidence_ref', false);
        self::assertUtcTimestamp($observedAt);
    }

    /**
     * @throws JsonException
     */
    public static function fromContracts(
        BillingRequest $request,
        BillingResult $result,
        string $observedAt,
    ): self {
        $canonicalPayload = json_encode(
            $request->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return new self(
            $request->tenantRef(),
            $request->idempotencyKey(),
            hash('sha256', $canonicalPayload),
            $result->outcome(),
            $result->providerRef(),
            $result->evidenceRef(),
            $observedAt,
        );
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $actual = array_keys($input);
        sort($actual);
        $expected = [
            'evidence_ref',
            'idempotency_key',
            'observed_at',
            'outcome',
            'provider_ref',
            'request_fingerprint',
            'tenant_ref',
        ];

        if ($actual !== $expected) {
            throw new DomainException('invalid_billing_audit_shape');
        }

        if (
            !is_string($input['tenant_ref'])
            || !is_string($input['idempotency_key'])
            || !is_string($input['request_fingerprint'])
            || !is_string($input['outcome'])
            || ($input['provider_ref'] !== null && !is_string($input['provider_ref']))
            || !is_string($input['evidence_ref'])
            || !is_string($input['observed_at'])
        ) {
            throw new DomainException('invalid_billing_audit_shape');
        }

        return new self(
            $input['tenant_ref'],
            $input['idempotency_key'],
            $input['request_fingerprint'],
            $input['outcome'],
            $input['provider_ref'],
            $input['evidence_ref'],
            $input['observed_at'],
        );
    }

    /** @return array{tenant_ref:string,idempotency_key:string,request_fingerprint:string,outcome:string,provider_ref:string|null,evidence_ref:string,observed_at:string} */
    public function toArray(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'idempotency_key' => $this->idempotencyKey,
            'request_fingerprint' => $this->requestFingerprint,
            'outcome' => $this->outcome,
            'provider_ref' => $this->providerRef,
            'evidence_ref' => $this->evidenceRef,
            'observed_at' => $this->observedAt,
        ];
    }

    private static function assertOpaqueRef(
        string $value,
        string $field,
        bool $forbidProviderPrefix,
    ): void {
        if (preg_match(self::REF_PATTERN, $value) !== 1) {
            throw new DomainException('invalid_billing_audit_' . $field);
        }

        $normalized = strtolower($value);
        foreach (self::FORBIDDEN_SENSITIVE_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                throw new DomainException('sensitive_billing_audit_' . $field);
            }
        }

        if ($forbidProviderPrefix && str_starts_with($normalized, 'provider:')) {
            throw new DomainException('provider_specific_billing_audit_' . $field);
        }
    }

    private static function assertUtcTimestamp(string $value): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $value);
        if ($parsed === false || $parsed->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException('invalid_billing_audit_observed_at');
        }
    }
}
