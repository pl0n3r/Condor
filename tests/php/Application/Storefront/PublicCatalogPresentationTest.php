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
use Doctrine\DBAL\Connection;
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

    public function testCatalogFailsClosedBeforeSalesChannelMigrationIsReady(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('information_schema.tables'),
                ['condor_sales_channel'],
            )
            ->willReturn(false);

        $presentation = new PublicCatalogPresentation(
            $entityManager,
            new PricingService(
                $entityManager,
                new EffectivePriceResolver(),
            ),
        );
        $tenant = new Tenant('Sin esquema', 'sin-esquema');

        $catalog = $presentation->catalog($tenant);

        self::assertNull($catalog['channel']);
        self::assertSame([], $catalog['products']);
        self::assertSame(1, $catalog['pagination']['page']);
        self::assertFalse($catalog['pagination']['has_next']);
    }

    public function testCatalogPaginatesProductsAndKeepsPagesDisjoint(): void
    {
        [$tenant, , , $list] = $this->fixture();

        for ($index = 1; $index <= 25; ++$index) {
            $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $product = new Product(
                $tenant,
                'Producto '.$suffix,
                'producto-'.$suffix.'-'.strtolower(bin2hex(random_bytes(3))),
                'Producto paginado',
            );
            $variant = new ProductVariant(
                $tenant,
                $product,
                'PAGE-'.$suffix.'-'.strtoupper(bin2hex(random_bytes(2))),
                'Única',
            );
            $price = new VariantPrice(
                $tenant,
                $list,
                $variant,
                100000 + $index,
            );

            $this->entityManager->persist($product);
            $this->entityManager->persist($variant);
            $this->entityManager->persist($price);
        }
        $this->entityManager->flush();

        $first = $this->presentation->catalog($tenant, 1);
        $second = $this->presentation->catalog($tenant, 2);

        self::assertCount(PublicCatalogPresentation::PAGE_SIZE, $first['products']);
        self::assertCount(2, $second['products']);
        self::assertFalse($first['pagination']['has_previous']);
        self::assertTrue($first['pagination']['has_next']);
        self::assertTrue($second['pagination']['has_previous']);
        self::assertFalse($second['pagination']['has_next']);

        $firstIds = array_column($first['products'], 'id');
        $secondIds = array_column($second['products'], 'id');
        self::assertSame([], array_values(array_intersect($firstIds, $secondIds)));
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
