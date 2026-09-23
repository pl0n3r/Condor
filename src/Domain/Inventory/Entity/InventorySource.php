<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Entity;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_inventory_source')]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_source_tenant_legal_slug',
    columns: ['tenant_id', 'legal_entity_id', 'slug'],
)]
#[ORM\UniqueConstraint(name: 'uniq_inventory_source_tenant_id', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_source_tenant_legal_id', columns: ['tenant_id', 'legal_entity_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_source_tenant_branch', columns: ['tenant_id', 'branch_id'])]
class InventorySource
{
    public const TYPE_BRANCH = 'branch';
    public const TYPE_LOGICAL = 'logical';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(name: 'legal_entity_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private LegalEntity $legalEntity;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Branch $branch;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Tenant $tenant,
        LegalEntity $legalEntity,
        string $name,
        string $slug,
        string $type,
        ?Branch $branch = null,
    ) {
        $type = self::normalizeType($type);
        self::assertLegalEntity($tenant, $legalEntity);
        self::assertBranchContract($tenant, $legalEntity, $type, $branch);

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->legalEntity = $legalEntity;
        $this->branch = $branch;
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->type = $type;
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

    public function legalEntity(): LegalEntity
    {
        return $this->legalEntity;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function update(string $name, string $slug): void
    {
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
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
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre de la fuente es obligatorio y admite máximo 160 caracteres.',
            );
        }

        return $name;
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (
            $slug === ''
            || mb_strlen($slug, 'UTF-8') > 120
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new DomainException(
                'El slug de la fuente debe usar letras minúsculas, números y guiones.',
            );
        }

        return $slug;
    }

    private static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, [self::TYPE_BRANCH, self::TYPE_LOGICAL], true)) {
            throw new DomainException('El tipo de fuente de inventario no es válido.');
        }

        return $type;
    }

    private static function assertLegalEntity(
        Tenant $tenant,
        LegalEntity $legalEntity,
    ): void {
        if ($legalEntity->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La fuente y la entidad legal deben pertenecer al mismo tenant.',
            );
        }
    }

    private static function assertBranchContract(
        Tenant $tenant,
        LegalEntity $legalEntity,
        string $type,
        ?Branch $branch,
    ): void {
        if ($type === self::TYPE_BRANCH && !$branch instanceof Branch) {
            throw new DomainException(
                'Una fuente de tipo sede requiere una sede asociada.',
            );
        }

        if ($type === self::TYPE_LOGICAL && $branch instanceof Branch) {
            throw new DomainException(
                'Una fuente lógica no puede quedar asociada a una sede.',
            );
        }

        if ($branch instanceof Branch && $branch->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La fuente y la sede deben pertenecer al mismo tenant.',
            );
        }

        if ($branch instanceof Branch) {
            $branchLegalEntity = $branch->legalEntity();
            if (!$branchLegalEntity instanceof LegalEntity) {
                throw new DomainException(
                    'Una sede usada como fuente de inventario debe tener entidad legal.',
                );
            }
            if ($branchLegalEntity->id() !== $legalEntity->id()) {
                throw new DomainException(
                    'La fuente y la sede deben pertenecer a la misma entidad legal.',
                );
            }
        }
    }
}
