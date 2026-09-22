<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testProductNormalizesEditableFields(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $product = new Product(
            $tenant,
            '  Camiseta negra  ',
            'camiseta-negra',
            '  Algodón pesado.  ',
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            '  tee-black-m  ',
            '  Talla M  ',
        );

        self::assertSame('Camiseta negra', $product->name());
        self::assertSame('camiseta-negra', $product->slug());
        self::assertSame('Algodón pesado.', $product->description());
        self::assertFalse($product->allowsBackorder());
        self::assertSame('TEE-BLACK-M', $variant->sku());
        self::assertSame('Talla M', $variant->name());
        self::assertSame($tenant->id(), $variant->tenant()->id());
        self::assertSame($product->id(), $variant->product()->id());

        $product->setBackorderAllowed(true);
        self::assertTrue($product->allowsBackorder());
    }

    public function testVariantRejectsProductFromAnotherTenant(): void
    {
        $first = new Tenant('Uno', 'uno-'.bin2hex(random_bytes(4)));
        $second = new Tenant('Dos', 'dos-'.bin2hex(random_bytes(4)));
        $product = new Product($first, 'Producto', 'producto');

        $this->expectException(DomainException::class);
        new ProductVariant($second, $product, 'SKU-1', 'Variante');
    }

    public function testInvalidSlugAndSkuAreRejected(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));

        try {
            new Product($tenant, 'Producto', 'Slug Con Espacios');
            self::fail('El slug inválido debía rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $product = new Product($tenant, 'Producto', 'producto');

        $this->expectException(DomainException::class);
        new ProductVariant($tenant, $product, 'SKU CON ESPACIOS', 'Variante');
    }

    public function testDeactivateIsIdempotent(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant($tenant, $product, 'SKU-1', 'Variante');

        $product->deactivate();
        $product->deactivate();
        $variant->deactivate();
        $variant->deactivate();

        self::assertFalse($product->isActive());
        self::assertFalse($variant->isActive());
    }
}
