<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductCategory;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ProductCategoryTest extends TestCase
{
    public function testAssignmentStaysInsideTenantAndCanBeRemoved(): void
    {
        $tenant = new Tenant('Empresa A', 'empresa-a');
        $other = new Tenant('Empresa B', 'empresa-b');
        $product = new Product($tenant, 'Camisa', 'camisa');
        $category = new ProductCategory($tenant, ' Ropa ', ' ROPA ');
        $foreign = new ProductCategory($other, 'Ropa', 'ropa');

        self::assertSame('Ropa', $category->name());
        self::assertSame('ropa', $category->slug());
        self::assertNull($product->category());
        $product->assignCategory($category);
        self::assertSame($category, $product->category());
        try {
            $product->assignCategory($foreign);
            self::fail('No se debe aceptar una categoría de otro tenant.');
        } catch (DomainException) {
            self::assertSame($category, $product->category());
        }
        $product->assignCategory(null);
        self::assertNull($product->category());
    }

    public function testInactiveCategoryCannotBeAssigned(): void
    {
        $tenant = new Tenant('Empresa A', 'empresa-a');
        $product = new Product($tenant, 'Zapato', 'zapato');
        $category = new ProductCategory($tenant, 'Calzado', 'calzado');
        $category->deactivate();
        $this->expectException(DomainException::class);
        $product->assignCategory($category);
    }

    public function testInvalidIdentityIsRejected(): void
    {
        $tenant = new Tenant('Empresa A', 'empresa-a');
        $this->expectException(DomainException::class);
        new ProductCategory($tenant, 'Ropa', 'precio externo');
    }

    public function testLegacyProductCategoryIsNull(): void
    {
        $tenant = new Tenant('Empresa A', 'empresa-a');
        $product = new Product($tenant, 'Producto antiguo', 'producto-antiguo');
        self::assertNull($product->category());
    }
}
