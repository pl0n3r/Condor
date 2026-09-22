<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\MappedSuperclass]
abstract class CatalogItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(
        name: 'tenant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Tenant $tenant;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    protected function __construct(Tenant $tenant)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    final public function id(): string
    {
        return $this->id;
    }

    final public function tenant(): Tenant
    {
        return $this->tenant;
    }

    final public function isActive(): bool
    {
        return $this->active;
    }

    final public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    final public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    final public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch();
    }

    final protected function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    final protected static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre es obligatorio y admite máximo 160 caracteres.',
            );
        }

        return $name;
    }
}
