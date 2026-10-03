<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;

final readonly class BillingResult
{
    private const OUTCOMES = ['prepared', 'accepted', 'rejected', 'unknown'];
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
        'secret:',
        'token:',
    ];

    public function __construct(
        private string $outcome,
        private ?string $providerRef,
        private string $evidenceRef,
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException('invalid_billing_outcome');
        }
        if ($providerRef !== null) {
            self::assertOpaqueRef($providerRef, 'provider_ref');
        }
        self::assertOpaqueRef($evidenceRef, 'evidence_ref');
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $actual = array_keys($input);
        sort($actual);
        $expected = ['evidence_ref', 'outcome', 'provider_ref'];
        if ($actual !== $expected) {
            throw new DomainException('invalid_billing_result_shape');
        }

        if (
            !is_string($input['outcome'])
            || ($input['provider_ref'] !== null && !is_string($input['provider_ref']))
            || !is_string($input['evidence_ref'])
        ) {
            throw new DomainException('invalid_billing_result_shape');
        }

        return new self(
            $input['outcome'],
            $input['provider_ref'],
            $input['evidence_ref'],
        );
    }

    /** @return array{outcome:string,provider_ref:string|null,evidence_ref:string} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'provider_ref' => $this->providerRef,
            'evidence_ref' => $this->evidenceRef,
        ];
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function providerRef(): ?string
    {
        return $this->providerRef;
    }

    public function evidenceRef(): string
    {
        return $this->evidenceRef;
    }

    private static function assertOpaqueRef(string $value, string $field): void
    {
        if (preg_match(self::REF_PATTERN, $value) !== 1) {
            throw new DomainException('invalid_' . $field);
        }

        $normalized = strtolower($value);
        foreach (self::FORBIDDEN_REF_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                throw new DomainException('sensitive_' . $field);
            }
        }
    }
}
