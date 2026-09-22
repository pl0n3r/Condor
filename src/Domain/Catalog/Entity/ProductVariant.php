<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_product_variant')]
#[ORM\UniqueConstraint(name: 'uniq_variant_tenant_sku', columns: ['tenant_id', 'sku'])]
#[ORM\UniqueConstraint(name: 'uniq_variant_tenant_id', columns: ['tenant_id', 'id'])]
class ProductVariant extends CatalogItem
{
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(
        name: 'product_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Product $product;

    #[ORM\Column(type: 'string', length: 120)]
    private string $sku;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    public function __construct(
        Tenant $tenant,
        Product $product,
        string $sku,
        string $name,
    ) {
        if ($product->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La variante debe pertenecer al mismo tenant del producto.',
            );
        }

        parent::__construct($tenant);
        $this->product = $product;
        $this->sku = self::normalizeSku($sku);
        $this->name = self::normalizeName($name);
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function update(string $sku, string $name): void
    {
        $this->sku = self::normalizeSku($sku);
        $this->name = self::normalizeName($name);
        $this->touch();
    }

    private static function normalizeSku(string $sku): string
    {
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        if (
            $sku === ''
            || mb_strlen($sku, 'UTF-8') > 120
            || preg_match('/^[A-Z0-9][A-Z0-9._-]{0,119}$/D', $sku) !== 1
        ) {
            throw new DomainException(
                'El SKU debe usar letras, números, punto, guion o guion bajo, con máximo 120 caracteres.',
            );
        }

        return $sku;
    }
}
