<?php

declare(strict_types=1);

namespace App\Tests\Application\Commerce;

use App\Application\Commerce\PricingService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\EffectivePriceResolver;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\PriceRule;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PricingServiceTest extends KernelTestCase
{
    public function testResolveBatchReusesOneRuleSetAndOmitsVariantsWithoutPrice(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $list = new PriceList($tenant, 'Lista web', 'lista-web-'.$suffix);
        $product = new Product(
            $tenant,
            'Producto batch',
            'producto-batch-'.$suffix,
            null,
        );
        $variantA = new ProductVariant(
            $tenant,
            $product,
            'BATCH-A-'.strtoupper($suffix),
            'A',
        );
        $variantB = new ProductVariant(
            $tenant,
            $product,
            'BATCH-B-'.strtoupper($suffix),
            'B',
        );
        $variantWithoutPrice = new ProductVariant(
            $tenant,
            $product,
            'BATCH-C-'.strtoupper($suffix),
            'C',
        );
        $priceA = new VariantPrice($tenant, $list, $variantA, 10000);
        $priceB = new VariantPrice($tenant, $list, $variantB, 20000);
        $rule = new PriceRule(
            $tenant,
            $list,
            'Descuento público',
            10,
            PriceRule::TYPE_FIXED,
            500,
        );

        foreach ([
            $tenant,
            $list,
            $product,
            $variantA,
            $variantB,
            $variantWithoutPrice,
            $priceA,
            $priceB,
            $rule,
        ] as $record) {
            $entityManager->persist($record);
        }
        $entityManager->flush();

        $service = new PricingService(
            $entityManager,
            new EffectivePriceResolver(),
        );
        $resolved = $service->resolveBatch(
            $tenant,
            [$variantA, $variantB, $variantWithoutPrice],
            $list,
        );

        self::assertSame(
            [$variantA->id(), $variantB->id()],
            array_keys($resolved),
        );
        self::assertSame(9500, $resolved[$variantA->id()]->effectiveAmountMinor);
        self::assertSame(19500, $resolved[$variantB->id()]->effectiveAmountMinor);
        self::assertArrayNotHasKey($variantWithoutPrice->id(), $resolved);
    }
}
