<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_role')]
#[ORM\UniqueConstraint(name: 'uniq_role_tenant_name', columns: ['tenant_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_role_tenant_id', columns: ['tenant_id', 'id'])]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $permissions;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @param array<mixed> $permissions */
    public function __construct(Tenant $tenant, string $name, array $permissions)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->name = self::normalizeName($name);
        $this->permissions = PermissionCatalog::normalize($permissions);
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
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

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @param array<mixed> $permissions */
    public function update(string $name, array $permissions): void
    {
        $this->name = self::normalizeName($name);
        $this->permissions = PermissionCatalog::normalize($permissions);
        $this->touch();
    }

    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            throw new DomainException('El nombre del rol es obligatorio y admite máximo 120 caracteres.');
        }

        return $name;
    }
}
