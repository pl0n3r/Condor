<?php

declare(strict_types=1);

namespace App\Domain\Organization\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_branch')]
#[ORM\UniqueConstraint(name: 'uniq_branch_tenant_slug', columns: ['tenant_id', 'slug'])]
class Branch
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(
        name: 'legal_entity_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?LegalEntity $legalEntity;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(name: 'is_default', type: 'boolean')]
    private bool $default;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        ?LegalEntity $legalEntity = null,
        bool $default = false,
    ) {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->name = trim($name);
        $this->slug = strtolower(trim($slug));
        $this->legalEntity = $legalEntity;
        $this->default = $default;
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

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }
}
