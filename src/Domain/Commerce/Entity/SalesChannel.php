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
class SalesChannel extends CommercialItem
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

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

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

        parent::__construct($tenant);
        $this->legalEntity = $inventorySource->legalEntity();
        $this->inventorySource = $inventorySource;
        $this->priceList = $priceList;
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
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
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->touch();
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

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre del canal es obligatorio y admite máximo 160 caracteres.',
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
                'El slug del canal debe usar letras minúsculas, números y guiones.',
            );
        }

        return $slug;
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
