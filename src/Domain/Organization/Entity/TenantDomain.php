<?php

declare(strict_types=1);

namespace App\Domain\Organization\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_tenant_domain')]
class TenantDomain
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'string', length: 253, unique: true)]
    private string $hostname;

    #[ORM\Column(name: 'is_primary', type: 'boolean')]
    private bool $primary;

    #[ORM\Column(name: 'is_verified', type: 'boolean')]
    private bool $verified;

    #[ORM\Column(
        name: 'primary_verified_tenant_id',
        type: 'string',
        length: 26,
        nullable: true,
        unique: true,
    )]
    private ?string $primaryVerifiedTenantId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Tenant $tenant,
        string $hostname,
        bool $primary = false,
        bool $verified = false,
    ) {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->hostname = strtolower(rtrim(trim($hostname), '.'));
        $this->primary = $primary;
        $this->verified = $verified;
        $this->primaryVerifiedTenantId = $primary && $verified
            ? $tenant->id()
            : null;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }
}
