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
#[ORM\Table(name: 'condor_platform_staff_grant')]
#[ORM\UniqueConstraint(
    name: 'uniq_platform_staff_scope_module',
    columns: ['staff_user_id', 'scope_key', 'module_key'],
)]
class PlatformStaffGrant
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'staff_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $staff;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant;

    #[ORM\Column(name: 'scope_key', type: 'string', length: 26)]
    private string $scopeKey;

    #[ORM\Column(name: 'module_key', type: 'string', length: 64)]
    private string $moduleKey;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $actions;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @param array<mixed> $actions */
    public function __construct(User $staff, ?Tenant $tenant, string $moduleKey, array $actions)
    {
        if (!$staff->hasRole(User::ROLE_PLATFORM_STAFF)) {
            throw new DomainException('Los permisos globales solo pueden asignarse a staff de plataforma.');
        }

        $this->id = UlidFactory::new();
        $this->staff = $staff;
        $this->tenant = $tenant;
        $this->scopeKey = $tenant?->id() ?? '*';
        $this->moduleKey = PermissionCatalog::normalizeModule($moduleKey);
        $this->actions = PermissionCatalog::normalizeActions($actions);

        if ($this->actions === []) {
            throw new DomainException('Un permiso de staff debe incluir al menos una acción.');
        }

        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function staff(): User { return $this->staff; }
    public function tenant(): ?Tenant { return $this->tenant; }
    public function scopeKey(): string { return $this->scopeKey; }
    public function moduleKey(): string { return $this->moduleKey; }

    /** @return list<string> */
    public function actions(): array { return $this->actions; }

    public function covers(Tenant $tenant): bool
    {
        return $this->tenant === null || $this->tenant->id() === $tenant->id();
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /** @param array<mixed> $actions */
    public function replaceActions(array $actions): void
    {
        $normalized = PermissionCatalog::normalizeActions($actions);
        if ($normalized === []) {
            throw new DomainException('Un permiso de staff debe incluir al menos una acción.');
        }

        $this->actions = $normalized;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
