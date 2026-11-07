<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/**
 * Append-only payroll record. Only opaque references; no employee identity,
 * amounts, payments or authorization to process live payroll.
 */
#[ORM\Entity]
#[ORM\Table(name: 'condor_payroll_real_run')]
#[ORM\UniqueConstraint(name: 'uniq_payroll_scope_version', columns: ['tenant_id', 'company_id', 'record_ref', 'version'])]
#[ORM\Index(name: 'idx_payroll_company_state', columns: ['tenant_id', 'company_id', 'state'])]
final class PayrollRun
{
    public const DRAFT = 'BORRADOR';
    public const REVIEW = 'EN_REVISION';

    #[ORM\Id, ORM\Column(type: 'string', length: 26)]
    private string $id;
    #[ORM\Column(name: 'tenant_id', length: 26)]
    private string $tenantId;
    #[ORM\Column(name: 'company_id', length: 26)]
    private string $companyId;
    #[ORM\Column(name: 'record_ref', length: 64)]
    private string $ref;
    #[ORM\Column(name: 'employee_ref', length: 64)]
    private string $employee;
    #[ORM\Column(name: 'period_ref', length: 64)]
    private string $period;
    #[ORM\Column(length: 20)]
    private string $state;
    #[ORM\Column(type: 'integer')]
    private int $version;
    #[ORM\Column(name: 'previous_id', length: 26, nullable: true)]
    private ?string $previousId;
    #[ORM\Column(name: 'actor_id', length: 26)]
    private string $actorId;
    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    private function __construct(
        string $tenant, string $company, string $ref, string $employee, string $period,
        string $state, int $version, ?string $previous, string $actor,
        string $id, DateTimeImmutable $time,
    ) {
        foreach ([$tenant, $company, $actor, $id] as $ulid) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $ulid) !== 1) {
                throw new DomainException('Identificador de nómina inválido.');
            }
        }
        if ($previous !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $previous) !== 1) {
            throw new DomainException('Identificador de historial inválido.');
        }
        foreach ([$ref, $employee, $period] as $alias) {
            if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $alias) !== 1) {
                throw new DomainException('Usar solo referencias opacas de nómina.');
            }
        }
        if (!in_array($state, [self::DRAFT, self::REVIEW], true)
            || $version < 1 || $version > 1000000
            || ($version === 1 && ($previous !== null || $state !== self::DRAFT))
            || ($version > 1 && $previous === null)) {
            throw new DomainException('Estado o historial de nómina inválido.');
        }
        [$this->tenantId, $this->companyId, $this->ref] = [$tenant, $company, $ref];
        [$this->employee, $this->period, $this->state] = [$employee, $period, $state];
        [$this->version, $this->previousId, $this->actorId, $this->id, $this->occurredAt] =
            [$version, $previous, $actor, $id, $time];
    }

    public static function open(
        string $tenant, string $company, string $ref, string $employee,
        string $period, string $actor,
    ): self {
        return new self($tenant, $company, $ref, $employee, $period,
            self::DRAFT, 1, null, $actor, UlidFactory::new(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row, string $tenant, string $company): self
    {
        if (($row['tenant_id'] ?? null) !== $tenant || ($row['company_id'] ?? null) !== $company) {
            throw new DomainException('Lectura de nómina fuera de empresa.');
        }
        return new self($tenant, $company, (string) $row['record_ref'],
            (string) $row['employee_ref'], (string) $row['period_ref'],
            (string) $row['state'], (int) $row['version'],
            ($row['previous_id'] ?? null) === null ? null : (string) $row['previous_id'],
            (string) $row['actor_id'], (string) $row['id'],
            new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')));
    }

    public function transition(string $next, string $actor, int $expectedVersion): self
    {
        if ($this->version !== $expectedVersion || $next === $this->state
            || !in_array($next, [self::DRAFT, self::REVIEW], true)) {
            throw new DomainException('Transición o versión de nómina inválida.');
        }
        return new self($this->tenantId, $this->companyId, $this->ref,
            $this->employee, $this->period, $next, $this->version + 1,
            $this->id, $actor, UlidFactory::new(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id, 'tenant_id' => $this->tenantId, 'company_id' => $this->companyId,
            'record_ref' => $this->ref, 'employee_ref' => $this->employee,
            'period_ref' => $this->period, 'state' => $this->state,
            'version' => $this->version, 'previous_id' => $this->previousId,
            'actor_id' => $this->actorId, 'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
