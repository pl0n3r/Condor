<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class BillingReconciliationEvidence
{
    private const OUTCOMES = ['accepted', 'rejected'];
    private const REF_PATTERN = '/^[a-z][a-z0-9:_-]{2,159}$/D';
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
        private string $idempotencyKey,
        private string $outcome,
        private string $evidenceRef,
        private string $observedAt,
    ) {
        self::assertOpaqueRef($idempotencyKey, 'idempotency_key');

        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException('invalid_billing_reconciliation_outcome');
        }

        self::assertOpaqueRef($evidenceRef, 'evidence_ref');
        self::assertUtcTimestamp($observedAt);
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $actual = array_keys($input);
        sort($actual);
        $expected = ['evidence_ref', 'idempotency_key', 'observed_at', 'outcome'];

        if ($actual !== $expected) {
            throw new DomainException('invalid_billing_reconciliation_shape');
        }

        if (
            !is_string($input['idempotency_key'])
            || !is_string($input['outcome'])
            || !is_string($input['evidence_ref'])
            || !is_string($input['observed_at'])
        ) {
            throw new DomainException('invalid_billing_reconciliation_shape');
        }

        return new self(
            $input['idempotency_key'],
            $input['outcome'],
            $input['evidence_ref'],
            $input['observed_at'],
        );
    }

    /** @return array{idempotency_key:string,outcome:string,evidence_ref:string,observed_at:string} */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'outcome' => $this->outcome,
            'evidence_ref' => $this->evidenceRef,
            'observed_at' => $this->observedAt,
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function evidenceRef(): string
    {
        return $this->evidenceRef;
    }

    public function observedAt(): string
    {
        return $this->observedAt;
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

    private static function assertUtcTimestamp(string $value): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $value);
        if ($parsed === false || $parsed->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException('invalid_billing_reconciliation_observed_at');
        }
    }
}
