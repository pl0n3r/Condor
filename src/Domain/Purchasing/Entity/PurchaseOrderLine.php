<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Shared\Id\UlidFactory;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_purchasing_order_line')]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_order_line_tenant_id', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_order_line_material', columns: ['tenant_id', 'order_id', 'material_id'])]
class PurchaseOrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Material $material;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 19, scale: 4)]
    private string $unitCost;

    public function __construct(PurchaseOrder $order, Material $material, string $quantity, string $unitCost)
    {
        if ($order->tenant()->id() !== $material->tenant()->id()) {
            throw new DomainException('La línea y el material deben pertenecer al mismo tenant.');
        }
        $quantity = MaterialInventoryBalance::normalizeQuantity($quantity);
        if ($quantity === '0') {
            throw new DomainException('La cantidad de compra debe ser positiva.');
        }
        $unitCost = trim($unitCost);
        if (preg_match('/^(0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?$/D', $unitCost) !== 1) {
            throw new DomainException('El costo unitario debe ser decimal no negativo con cuatro decimales como máximo.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $order->tenant();
        $this->purchaseOrder = $order;
        $this->material = $material;
        $this->quantity = $quantity;
        $this->unitCost = $unitCost;
    }

    public function id(): string { return $this->id; }
    public function tenant(): Tenant { return $this->tenant; }
    public function purchaseOrder(): PurchaseOrder { return $this->purchaseOrder; }
    public function material(): Material { return $this->material; }
    public function quantity(): string { return $this->quantity; }
    public function unitCost(): string { return $this->unitCost; }
}
