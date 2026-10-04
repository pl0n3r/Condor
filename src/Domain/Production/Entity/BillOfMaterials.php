<?php

declare(strict_types=1);

namespace App\Domain\Production\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_production_bom')]
#[ORM\UniqueConstraint(
    name: 'uniq_production_bom_tenant_variant_version',
    columns: ['tenant_id', 'variant_id', 'version'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_production_bom_tenant_id',
    columns: ['tenant_id', 'id'],
)]
class BillOfMaterials
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

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(
        name: 'variant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private ProductVariant $variant;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int,BillOfMaterialsLine> */
    #[ORM\OneToMany(
        mappedBy: 'billOfMaterials',
        targetEntity: BillOfMaterialsLine::class,
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    private Collection $lines;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param list<array{
     *     material: Material,
     *     quantity: string,
     *     unit: UnitOfMeasure
     * }> $components
     */
    public function __construct(
        Tenant $tenant,
        ProductVariant $variant,
        int $version,
        array $components,
    ) {
        if ($variant->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La variante de la BOM pertenece a otro tenant.',
            );
        }
        if (!$variant->isActive()) {
            throw new DomainException(
                'La variante de la BOM debe estar activa.',
            );
        }
        if ($version < 1) {
            throw new DomainException(
                'La versión de la BOM debe ser un entero positivo.',
            );
        }
        if ($components === []) {
            throw new DomainException(
                'La BOM requiere al menos un material.',
            );
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->variant = $variant;
        $this->version = $version;
        $this->lines = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $seen = [];
        foreach ($components as $component) {
            $material = $component['material'] ?? null;
            $quantity = $component['quantity'] ?? null;
            $unit = $component['unit'] ?? null;
            if (
                !$material instanceof Material
                || !is_string($quantity)
                || !$unit instanceof UnitOfMeasure
            ) {
                throw new DomainException(
                    'Cada línea de BOM requiere material, cantidad y unidad canónicas.',
                );
            }

            if (isset($seen[$material->id()])) {
                throw new DomainException(
                    'Un material no puede repetirse dentro de la misma versión de BOM.',
                );
            }
            $seen[$material->id()] = true;
            $this->lines->add(new BillOfMaterialsLine(
                $this,
                $tenant,
                $material,
                $quantity,
                $unit,
            ));
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function variant(): ProductVariant
    {
        return $this->variant;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return list<BillOfMaterialsLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function retire(): void
    {
        $this->active = false;
    }
}
