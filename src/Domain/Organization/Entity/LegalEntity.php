<?php

declare(strict_types=1);

namespace App\Domain\Organization\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_legal_entity')]
#[ORM\UniqueConstraint(name: 'uniq_legal_tenant_nit', columns: ['tenant_id', 'nit'])]
class LegalEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'legal_name', type: 'string', length: 180)]
    private string $legalName;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $nit;

    #[ORM\Column(name: 'is_primary', type: 'boolean')]
    private bool $primary;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, string $legalName, ?string $nit, bool $primary = false)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->legalName = trim($legalName);
        $this->nit = $nit !== null && trim($nit) !== '' ? trim($nit) : null;
        $this->primary = $primary;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function legalName(): string
    {
        return $this->legalName;
    }

    public function nit(): ?string
    {
        return $this->nit;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }
}
