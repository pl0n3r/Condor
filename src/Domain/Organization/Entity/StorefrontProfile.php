<?php

declare(strict_types=1);

namespace App\Domain\Organization\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_storefront_profile')]
class StorefrontProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\OneToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\Column(type: 'string', length: 120)]
    private string $headline;

    #[ORM\Column(type: 'string', length: 500)]
    private string $description;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant, string $headline, string $description)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->update($headline, $description);
    }

    public function update(string $headline, string $description): void
    {
        $headline = trim($headline);
        $description = trim($description);

        if (mb_strlen($headline) > 120 || mb_strlen($description) > 500) {
            throw new DomainException('La identidad pública supera el tamaño permitido.');
        }

        $this->headline = $headline;
        $this->description = $description;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function headline(): string
    {
        return $this->headline;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
