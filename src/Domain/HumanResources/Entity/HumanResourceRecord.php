<?php

declare(strict_types=1);

namespace App\Domain\HumanResources\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Event-sourced HR entry: company scope, immutable audit, no automatic legal approval. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_hr_real_record')]
#[ORM\UniqueConstraint(name: 'uniq_hr_scope_version', columns: ['tenant_id', 'company_id', 'record_ref', 'version'])]
final class HumanResourceRecord
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
    #[ORM\Column(length: 24)]
    private string $kind;
    #[ORM\Column(name: 'collaborator_ref', length: 64)]
    private string $collaborator;
    #[ORM\Column(name: 'position_ref', length: 64)]
    private string $position;
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
        string $tenant, string $company, string $ref, string $kind,
        string $collaborator, string $position, string $state, int $version,
        ?string $previous, string $actor, string $id, DateTimeImmutable $time,
    ) {
        foreach ([$tenant, $company, $actor, $id] as $ulid) {
            if (!preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $ulid)) {
                throw new DomainException('Identificador de RR. HH. inválido.');
            }
        }
        foreach ([$ref, $collaborator, $position] as $alias) {
            if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $alias)) {
                throw new DomainException('Usar únicamente referencias opacas en RR. HH.');
            }
        }
        if (!in_array($kind, ['vinculacion', 'novedad', 'movimiento_interno'], true)
            || !in_array($state, [self::DRAFT, self::REVIEW], true)
            || $version < 1 || $version > 1000000
            || ($previous !== null && !preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $previous))) {
            throw new DomainException('Estado, tipo o historial laboral inválido.');
        }
        [$this->tenantId, $this->companyId, $this->ref, $this->kind] = [$tenant, $company, $ref, $kind];
        [$this->collaborator, $this->position, $this->state] = [$collaborator, $position, $state];
        [$this->version, $this->previousId, $this->actorId, $this->id, $this->occurredAt] =
            [$version, $previous, $actor, $id, $time];
    }

    public static function open(
        string $tenant, string $company, string $ref, string $kind,
        string $collaborator, string $position, string $actor,
    ): self {
        return new self($tenant, $company, $ref, $kind, $collaborator, $position,
            self::DRAFT, 1, null, $actor, UlidFactory::new(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row, string $tenant, string $company): self
    {
        if (($row['tenant_id'] ?? null) !== $tenant || ($row['company_id'] ?? null) !== $company) {
            throw new DomainException('Lectura laboral fuera de empresa.');
        }
        return new self($tenant, $company, (string) $row['record_ref'], (string) $row['kind'],
            (string) $row['collaborator_ref'], (string) $row['position_ref'],
            (string) $row['state'], (int) $row['version'],
            $row['previous_id'] === null ? null : (string) $row['previous_id'],
            (string) $row['actor_id'], (string) $row['id'],
            new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')));
    }

    public function transition(string $state, string $actor, int $expected): self
    {
        if ($this->version !== $expected || $state === $this->state
            || !in_array($state, [self::DRAFT, self::REVIEW], true)) {
            throw new DomainException('Transición o versión laboral inválida.');
        }
        return new self($this->tenantId, $this->companyId, $this->ref, $this->kind,
            $this->collaborator, $this->position, $state, $this->version + 1,
            $this->id, $actor, UlidFactory::new(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id, 'tenant_id' => $this->tenantId, 'company_id' => $this->companyId,
            'record_ref' => $this->ref, 'kind' => $this->kind,
            'collaborator_ref' => $this->collaborator, 'position_ref' => $this->position,
            'state' => $this->state, 'version' => $this->version,
            'previous_id' => $this->previousId, 'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
