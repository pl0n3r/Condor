<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/**
 * Immutable synthetic contract record. No real identity, approval or signature.
 * Actors and tenant/company scopes must be authorized by the trusted caller.
 */
#[ORM\Entity]
#[ORM\Table(name: 'condor_contract_real_record')]
#[ORM\UniqueConstraint(name: 'uniq_contract_scope_version', columns: ['tenant_id', 'company_id', 'record_ref', 'version'])]
#[ORM\Index(name: 'idx_contract_company_state', columns: ['tenant_id', 'company_id', 'state'])]
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

    // Separate persisted representation. The legacy synthetic fromInput()/toRow()
    // contract is deliberately preserved; real records never enter that store.
    public const REAL_DRAFT = 'REQUIERE_REVISION';
    public const REAL_REVIEW = 'EN_REVISION';

    // Explicit columns: ORM hydration remains independent of the synthetic array.
    #[ORM\Id, ORM\Column(type: 'string', length: 26)] private string $id;
    #[ORM\Column(name: 'actor_id', length: 26)] private string $actorId;
    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')] private DateTimeImmutable $occurredAt;
    #[ORM\Column(name: 'tenant_id', length: 26)] private string $tenantId;
    #[ORM\Column(name: 'company_id', length: 26)] private string $companyId;
    #[ORM\Column(name: 'record_ref', length: 64)] private string $recordRef;
    #[ORM\Column(name: 'kind', length: 16)] private string $kind;
    #[ORM\Column(name: 'template_ref', length: 64)] private string $templateRef;
    #[ORM\Column(name: 'worker_ref', length: 64)] private string $workerRef;
    #[ORM\Column(name: 'previous_id', length: 26, nullable: true)] private ?string $previousId;
    #[ORM\Column(type: 'integer')] private int $version;
    #[ORM\Column(length: 24)] private string $state;

    private static function validUlid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $value) === 1;
    }

    private static function validOpaqueRef(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $value) === 1;
    }

    private static function persisted(
        string $tenant, string $company, string $ref, string $worker,
        string $template, string $kind, string $state, int $version,
        ?string $previous, string $actor, string $id, DateTimeImmutable $time,
    ): self {
        // Validate all identity inputs in one pass; no free-form PII may enter an event.
        $identifiers = [$tenant, $company, $actor, $id];
        if ($previous !== null) {
            $identifiers[] = $previous;
        }
        if (count(array_filter($identifiers, self::validUlid(...))) !== count($identifiers)) {
            throw new DomainException('Identificador ULID de contratación inválido.');
        }
        if (!self::validOpaqueRef($ref) || !self::validOpaqueRef($worker)
            || !self::validOpaqueRef($template)) {
            throw new DomainException('Solo se admiten referencias opacas de contratación.');
        }
        if (!in_array($kind, ['template', 'contract', 'addendum'], true)
            || !in_array($state, [self::REAL_DRAFT, self::REAL_REVIEW], true)
            || $version < 1 || $version > 1000000
            || ($version === 1 && ($previous !== null || $state !== self::REAL_DRAFT))
            || ($version > 1 && $previous === null)) {
            throw new DomainException('Estado o historial de contratación inválido.');
        }
        $record = new self([]);
        [$record->tenantId, $record->companyId, $record->recordRef] = [$tenant, $company, $ref];
        [$record->workerRef, $record->templateRef, $record->kind] = [$worker, $template, $kind];
        [$record->state, $record->version, $record->previousId] = [$state, $version, $previous];
        [$record->actorId, $record->id, $record->occurredAt] = [$actor, $id, $time];
        return $record;
    }

    public static function openReal(
        string $tenant, string $company, string $ref, string $worker,
        string $template, string $kind, string $actor,
    ): self {
        return self::persisted($tenant, $company, $ref, $worker, $template, $kind,
            self::REAL_DRAFT, 1, null, $actor, UlidFactory::new(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @param array<string,mixed> $row */
    public static function fromRealRow(array $row, string $tenant, string $company): self
    {
        if (($row['tenant_id'] ?? null) !== $tenant || ($row['company_id'] ?? null) !== $company) {
            throw new DomainException('Lectura de contratación fuera de empresa.');
        }
        return self::persisted($tenant, $company,
            (string) $row['record_ref'], (string) $row['worker_ref'],
            (string) $row['template_ref'], (string) $row['kind'],
            (string) $row['state'], (int) $row['version'],
            ($row['previous_id'] ?? null) === null ? null : (string) $row['previous_id'],
            (string) $row['actor_id'], (string) $row['id'],
            new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')));
    }

    public function transitionReal(string $next, string $actor, int $expectedVersion): self
    {
        if ($this->version !== $expectedVersion
            || $this->state !== self::REAL_DRAFT
            || $next !== self::REAL_REVIEW) {
            throw new DomainException('Transición o versión de contratación inválida.');
        }
        return self::persisted(
            $this->tenantId, $this->companyId, $this->recordRef,
            $this->workerRef, $this->templateRef, $this->kind, $next,
            $this->version + 1, $this->id, $actor, UlidFactory::new(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @return array<string,mixed> */
    public function realSnapshot(): array
    {
        // Database column order does not determine the chronology of events.
        return [
            'id' => $this->id,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'kind' => $this->kind,
            'record_ref' => $this->recordRef,
            'template_ref' => $this->templateRef,
            'worker_ref' => $this->workerRef,
            'previous_id' => $this->previousId,
            'version' => $this->version,
            'state' => $this->state,
        ];
    }
}
