<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commerce;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CommercialDomainTest extends TestCase
{
    public function testCustomerNormalizesDataAndKeepsSingleEffectiveCategory(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $category = new CommercialCategory($tenant, 'Mayorista', 'mayorista');
        $customer = new Customer(
            $tenant,
            '  Cliente Uno  ',
            '  CLIENTE@EXAMPLE.COM ',
            ' 300 000 0000 ',
            '  Observación  ',
            $category,
        );

        self::assertSame('Cliente Uno', $customer->name());
        self::assertSame('cliente@example.com', $customer->email());
        self::assertSame('300 000 0000', $customer->phone());
        self::assertSame('Observación', $customer->notes());
        self::assertSame($category->id(), $customer->commercialCategory()?->id());

        $customer->assignCommercialCategory(null);
        self::assertNull($customer->commercialCategory());
    }

    public function testCustomerRejectsCategoryFromAnotherTenant(): void
    {
        $tenant = new Tenant('Uno', 'uno-'.bin2hex(random_bytes(4)));
        $other = new Tenant('Dos', 'dos-'.bin2hex(random_bytes(4)));
        $category = new CommercialCategory($other, 'Mayorista', 'mayorista');

        $this->expectException(DomainException::class);
        new Customer($tenant, 'Cliente', commercialCategory: $category);
    }

    public function testVariantPriceRequiresSingleTenantAndNonNegativeAmount(): void
    {
        $tenant = new Tenant('Uno', 'uno-'.bin2hex(random_bytes(4)));
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant($tenant, $product, 'SKU-1', 'Variante');
        $list = new PriceList($tenant, 'Detal', 'detal');
        $price = new VariantPrice($tenant, $list, $variant, 1250000);

        self::assertSame('COP', $list->currency());
        self::assertSame(1250000, $price->amountMinor());

        $price->updateAmount(990000);
        self::assertSame(990000, $price->amountMinor());

        $this->expectException(DomainException::class);
        $price->updateAmount(-1);
    }

    public function testVariantPriceRejectsCrossTenantReferences(): void
    {
        $tenant = new Tenant('Uno', 'uno-'.bin2hex(random_bytes(4)));
        $other = new Tenant('Dos', 'dos-'.bin2hex(random_bytes(4)));
        $product = new Product($other, 'Producto', 'producto');
        $variant = new ProductVariant($other, $product, 'SKU-2', 'Variante');
        $list = new PriceList($tenant, 'Detal', 'detal');

        $this->expectException(DomainException::class);
        new VariantPrice($tenant, $list, $variant, 1000);
    }

    public function testInvalidCommercialSlugsAndCurrencyAreRejected(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));

        try {
            new CommercialCategory($tenant, 'Mayorista', 'Slug inválido');
            self::fail('El slug inválido debía rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        new PriceList($tenant, 'Detal', 'detal', 'PESO');
    }
}
