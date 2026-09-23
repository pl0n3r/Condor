<?php

declare(strict_types=1);

namespace App\Tests\Application\Storefront;

use App\Application\Commerce\PricingService;
use App\Application\Storefront\PublicCatalogPresentation;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\EffectivePriceResolver;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicCatalogPresentationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PublicCatalogPresentation $presentation;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->presentation = new PublicCatalogPresentation(
            $entityManager,
            new PricingService($entityManager, new EffectivePriceResolver()),
        );
    }

    public function testCatalogUsesConfiguredPriceAndOnlyConfiguredStockSource(): void
    {
        [$tenant, $source, $otherSource, $list, $variant] = $this->fixture();

        $this->entityManager->persist(new InventoryBalance(
            $tenant,
            $source,
            $variant,
            0,
        ));
        $this->entityManager->persist(new InventoryBalance(
            $tenant,
            $otherSource,
            $variant,
            9,
        ));
        $this->entityManager->flush();

        $catalog = $this->presentation->catalog($tenant);

        self::assertSame('ecommerce', $catalog['channel']['type'] ?? null);
        self::assertCount(1, $catalog['products']);
        self::assertSame(
            125000,
            $catalog['products'][0]['variants'][0]['price']['effective_amount_minor'],
        );
        self::assertSame(
            'out_of_stock',
            $catalog['products'][0]['variants'][0]['availability'],
        );
        self::assertFalse($catalog['products'][0]['variants'][0]['backorder']);
    }

    public function testBackorderMakesConfiguredVariantAvailableWithoutMutatingStock(): void
    {
        [$tenant, $source, , , $variant] = $this->fixture(true);
        $balance = new InventoryBalance($tenant, $source, $variant, 0);
        $this->entityManager->persist($balance);
        $this->entityManager->flush();

        $catalog = $this->presentation->catalog($tenant);

        self::assertSame(
            'available',
            $catalog['products'][0]['variants'][0]['availability'],
        );
        self::assertTrue($catalog['products'][0]['variants'][0]['backorder']);

        $this->entityManager->refresh($balance);
        self::assertSame(0, $balance->quantity());
    }

    public function testInactiveChannelSourceOrPriceListFailsClosed(): void
    {
        [$tenant, $source, , $list] = $this->fixture();
        $channel = $this->channel($tenant);

        $source->deactivate();
        $this->entityManager->flush();
        self::assertNull($this->presentation->catalog($tenant)['channel']);

        $source->reactivate($source->name(), $source->slug());
        $list->deactivate();
        $this->entityManager->flush();
        self::assertNull($this->presentation->catalog($tenant)['channel']);

        $list->activate();
        $channel->deactivate();
        $this->entityManager->flush();
        self::assertNull($this->presentation->catalog($tenant)['channel']);
    }

    public function testVariantWithoutConfiguredListPriceIsNotPublished(): void
    {
        [$tenant, , , , $variant] = $this->fixture();
        $price = $this->entityManager
            ->getRepository(VariantPrice::class)
            ->findOneBy(['tenant' => $tenant, 'variant' => $variant]);
        self::assertInstanceOf(VariantPrice::class, $price);

        $this->entityManager->remove($price);
        $this->entityManager->flush();

        $catalog = $this->presentation->catalog($tenant);

        self::assertNotNull($catalog['channel']);
        self::assertSame([], $catalog['products']);
    }

    /**
     * @return array{
     *   0:Tenant,
     *   1:InventorySource,
     *   2:InventorySource,
     *   3:PriceList,
     *   4:ProductVariant
     * }
     */
    private function fixture(bool $backorder = false): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant(
            'Empresa '.$suffix,
            'empresa-'.$suffix,
        );
        $legal = new LegalEntity(
            $tenant,
            'Empresa '.$suffix.' SAS',
            null,
            true,
        );
        $source = new InventorySource(
            $tenant,
            $legal,
            'Bodega web',
            'bodega-web-'.$suffix,
            InventorySource::TYPE_LOGICAL,
        );
        $otherSource = new InventorySource(
            $tenant,
            $legal,
            'Otra bodega',
            'otra-bodega-'.$suffix,
            InventorySource::TYPE_LOGICAL,
        );
        $list = new PriceList(
            $tenant,
            'Lista web',
            'lista-web-'.$suffix,
        );
        $channel = new SalesChannel(
            $tenant,
            'Tienda web',
            'tienda-web',
            $source,
            $list,
        );
        $product = new Product(
            $tenant,
            'Body',
            'body-'.$suffix,
            'Body público',
            $backorder,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'BODY-'.strtoupper($suffix),
            'Talla única',
        );
        $price = new VariantPrice(
            $tenant,
            $list,
            $variant,
            125000,
        );

        foreach ([
            $tenant,
            $legal,
            $source,
            $otherSource,
            $list,
            $channel,
            $product,
            $variant,
            $price,
        ] as $record) {
            $this->entityManager->persist($record);
        }
        $this->entityManager->flush();

        return [$tenant, $source, $otherSource, $list, $variant];
    }

    private function channel(Tenant $tenant): SalesChannel
    {
        $channel = $this->entityManager
            ->getRepository(SalesChannel::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(SalesChannel::class, $channel);

        return $channel;
    }
}
