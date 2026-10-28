<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Entity;

/**
 * Immutable synthetic contract record. No real identity, approval or signature.
 * Actors and tenant/company scopes must be authorized by the trusted caller.
 */
final class ContractRecord
{
    public const STATE = 'REQUIERE_REVISION';

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $keys = [
            'tenant_ref', 'company_ref', 'contract_ref', 'worker_ref',
            'template_ref', 'actor_ref', 'state', 'version', 'audit_action',
        ];
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException('invalid_synthetic_record');
        }

        if (!self::validTenant($input['tenant_ref'])
            || !self::validFixture($input['company_ref'], 'company')
            || !self::validFixture($input['contract_ref'], 'contract')
            || !self::validFixture($input['worker_ref'], 'worker')
            || !self::validFixture($input['template_ref'], 'template')
            || !self::validFixture($input['actor_ref'], 'actor')
            || $input['state'] !== self::STATE
            || !is_int($input['version'])
            || $input['version'] < 1
            || $input['version'] > 10000
            || !in_array($input['audit_action'], ['CREATED', 'REVISED'], true)
            || ($input['audit_action'] === 'CREATED' && $input['version'] !== 1)
            || ($input['audit_action'] === 'REVISED' && $input['version'] < 2)
        ) {
            throw new \InvalidArgumentException('invalid_synthetic_record');
        }
        return new self($input);
    }

    public static function validTenant(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    public static function validFixture(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    public function inScope(string $tenantRef, string $companyRef): bool
    {
        return $this->values['tenant_ref'] === $tenantRef
            && $this->values['company_ref'] === $companyRef;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        $fingerprint = hash('sha256', json_encode($this->values, JSON_THROW_ON_ERROR));
        return [
            'id' => substr($fingerprint, 0, 32),
            ...$this->values,
            'fingerprint' => $fingerprint,
        ];
    }
}
