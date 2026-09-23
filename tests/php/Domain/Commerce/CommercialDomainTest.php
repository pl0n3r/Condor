<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commerce;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\EffectivePriceResolver;
use App\Domain\Commerce\Entity\PriceRule;
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

    public function testMonetaryValuesRejectAmountsOutsideJsonSafeRange(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-SAFE',
            'Variante',
        );
        $list = new PriceList($tenant, 'Detal', 'detal');

        try {
            new VariantPrice(
                $tenant,
                $list,
                $variant,
                9007199254740992,
            );
            self::fail('El precio fuera del rango JSON seguro debía rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame(
                'El precio excede el rango monetario seguro permitido.',
                $exception->getMessage(),
            );
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'El valor del descuento está fuera del rango permitido.',
        );
        new PriceRule(
            $tenant,
            $list,
            'Descuento fuera de rango',
            1,
            PriceRule::TYPE_FIXED,
            9007199254740992,
        );
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

    public function testEffectivePriceUsesSingleDeterministicWinningRule(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant($tenant, $product, 'SKU-3', 'Variante');
        $list = new PriceList($tenant, 'Detal', 'detal');
        $category = new CommercialCategory($tenant, 'Mayorista', 'mayorista');
        $category->assignPreferredPriceList($list);
        $price = new VariantPrice($tenant, $list, $variant, 100000);

        $global = new PriceRule(
            $tenant,
            $list,
            'Global',
            10,
            PriceRule::TYPE_PERCENTAGE,
            1000,
        );
        $specific = new PriceRule(
            $tenant,
            $list,
            'Mayorista',
            20,
            PriceRule::TYPE_FIXED,
            15000,
            $category,
        );

        $result = (new EffectivePriceResolver())->resolve(
            $price,
            $category,
            [$global, $specific],
        );

        self::assertSame(100000, $result->baseAmountMinor);
        self::assertSame(85000, $result->effectiveAmountMinor);
        self::assertSame($specific->id(), $result->ruleId);
        self::assertSame($list->id(), $result->priceListId);
    }

    public function testCompetingRulesWithSamePriorityUseStableIdTieBreak(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant($tenant, $product, 'SKU-TIE', 'Variante');
        $list = new PriceList($tenant, 'Detal', 'detal');
        $category = new CommercialCategory($tenant, 'Mayorista', 'mayorista');
        $price = new VariantPrice($tenant, $list, $variant, 100000);

        $first = new PriceRule(
            $tenant,
            $list,
            'A',
            50,
            PriceRule::TYPE_FIXED,
            10000,
            $category,
        );
        $second = new PriceRule(
            $tenant,
            $list,
            'B',
            50,
            PriceRule::TYPE_FIXED,
            20000,
            $category,
        );

        $expected = strcmp($first->id(), $second->id()) <= 0
            ? $first
            : $second;
        $result = (new EffectivePriceResolver())->resolve(
            $price,
            $category,
            [$second, $first],
        );

        self::assertSame($expected->id(), $result->ruleId);
        self::assertSame(
            $expected->applyTo(100000),
            $result->effectiveAmountMinor,
        );
    }

    public function testPriceRuleHonorsValidityAndNeverStacksDiscounts(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant($tenant, $product, 'SKU-4', 'Variante');
        $list = new PriceList($tenant, 'Detal', 'detal');
        $price = new VariantPrice($tenant, $list, $variant, 100000);
        $at = new \DateTimeImmutable('2026-09-23T10:00:00+00:00');

        $expired = new PriceRule(
            $tenant,
            $list,
            'Expirada',
            999,
            PriceRule::TYPE_FIXED,
            90000,
            null,
            null,
            new \DateTimeImmutable('2026-09-22T23:59:59+00:00'),
        );
        $active = new PriceRule(
            $tenant,
            $list,
            'Activa',
            10,
            PriceRule::TYPE_PERCENTAGE,
            2500,
        );

        $result = (new EffectivePriceResolver())->resolve(
            $price,
            null,
            [$expired, $active],
            $at,
        );

        self::assertSame(75000, $result->effectiveAmountMinor);
        self::assertSame($active->id(), $result->ruleId);
    }

    public function testPriceRuleRejectsPriorityOutsideDatabaseIntegerRange(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $list = new PriceList($tenant, 'Detal', 'detal');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'La prioridad de la regla excede el rango permitido.',
        );

        new PriceRule(
            $tenant,
            $list,
            'Fuera de rango',
            2147483648,
            PriceRule::TYPE_FIXED,
            1000,
        );
    }

    public function testInvalidCommercialSlugsAndUnsupportedCurrencyAreRejected(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));

        try {
            new CommercialCategory($tenant, 'Mayorista', 'Slug inválido');
            self::fail('El slug inválido debía rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        self::assertSame(
            'COP',
            (new PriceList($tenant, 'Detal', 'detal-cop', ' cop '))->currency(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'La moneda no está soportada en esta versión.',
        );
        new PriceList($tenant, 'Detal', 'detal', 'USD');
    }
}
