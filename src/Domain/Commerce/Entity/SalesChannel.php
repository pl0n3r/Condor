<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_sales_channel')]
#[ORM\UniqueConstraint(
    name: 'uniq_sales_channel_tenant_slug',
    columns: ['tenant_id', 'slug'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_sales_channel_tenant_id',
    columns: ['tenant_id', 'id'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_sales_channel_tenant_type',
    columns: ['tenant_id', 'type'],
)]
class SalesChannel extends NamedCommercialItem
{
    public const TYPE_ECOMMERCE = 'ecommerce';

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(
        name: 'legal_entity_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private LegalEntity $legalEntity;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(
        name: 'inventory_source_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private InventorySource $inventorySource;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(
        name: 'price_list_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private PriceList $priceList;

    private const LABEL = 'el canal';

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    public function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        InventorySource $inventorySource,
        PriceList $priceList,
        string $type = self::TYPE_ECOMMERCE,
    ) {
        self::assertReferences($tenant, $inventorySource, $priceList);

        parent::__construct($tenant, $name, $slug, self::LABEL);
        $this->legalEntity = $inventorySource->legalEntity();
        $this->inventorySource = $inventorySource;
        $this->priceList = $priceList;
        $this->type = self::normalizeType($type);
    }

    public function legalEntity(): LegalEntity
    {
        return $this->legalEntity;
    }

    public function inventorySource(): InventorySource
    {
        return $this->inventorySource;
    }

    public function priceList(): PriceList
    {
        return $this->priceList;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isPublishable(): bool
    {
        return $this->isActive()
            && $this->inventorySource->isActive()
            && $this->priceList->isActive();
    }

    public function update(
        string $name,
        string $slug,
        InventorySource $inventorySource,
        PriceList $priceList,
    ): void {
        self::assertReferences($this->tenant(), $inventorySource, $priceList);

        $this->legalEntity = $inventorySource->legalEntity();
        $this->inventorySource = $inventorySource;
        $this->priceList = $priceList;
        $this->updateIdentity($name, $slug, self::LABEL);
    }

    private static function assertReferences(
        Tenant $tenant,
        InventorySource $inventorySource,
        PriceList $priceList,
    ): void {
        if (
            $inventorySource->tenant()->id() !== $tenant->id()
            || $priceList->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'El canal, la fuente de inventario y la lista de precios '
                .'deben pertenecer al mismo tenant.',
            );
        }
    }

    private static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type !== self::TYPE_ECOMMERCE) {
            throw new DomainException(
                'El tipo de canal no está soportado en esta versión.',
            );
        }

        return $type;
    }
}
