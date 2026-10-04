<?php

declare(strict_types=1);

namespace App\Domain\Production\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Shared\Id\UlidFactory;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_production_bom_line')]
#[ORM\UniqueConstraint(
    name: 'uniq_production_bom_line_bom_material',
    columns: ['bom_id', 'material_id'],
)]
class BillOfMaterialsLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: BillOfMaterials::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(
        name: 'bom_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private BillOfMaterials $billOfMaterials;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(
        name: 'tenant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(
        name: 'material_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Material $material;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'unit_of_measure', type: 'string', length: 16)]
    private string $unitOfMeasure;

    public function __construct(
        BillOfMaterials $billOfMaterials,
        Tenant $tenant,
        Material $material,
        string $quantity,
        UnitOfMeasure $sourceUnit,
    ) {
        if ($billOfMaterials->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La línea pertenece a un tenant distinto de la BOM.',
            );
        }
        if ($material->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'El material de la BOM pertenece a otro tenant.',
            );
        }
        if (!$material->isActive()) {
            throw new DomainException(
                'La BOM no puede usar materiales inactivos.',
            );
        }

        $targetUnit = $material->unitOfMeasure();
        $normalized = $sourceUnit->convert($quantity, $targetUnit);
        if ($normalized === '0') {
            throw new DomainException(
                'La cantidad de una línea de BOM debe ser mayor que cero.',
            );
        }

        $this->id = UlidFactory::new();
        $this->billOfMaterials = $billOfMaterials;
        $this->tenant = $tenant;
        $this->material = $material;
        $this->quantity = $normalized;
        $this->unitOfMeasure = $targetUnit->key();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function billOfMaterials(): BillOfMaterials
    {
        return $this->billOfMaterials;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function material(): Material
    {
        return $this->material;
    }

    public function quantity(): string
    {
        return $this->quantity;
    }

    public function unitOfMeasure(): UnitOfMeasure
    {
        return UnitOfMeasure::from($this->unitOfMeasure);
    }
}
