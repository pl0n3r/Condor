<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Inventory\InventoryService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontCheckoutControllerTest extends WebTestCase
{
    public function testCheckoutRecalculatesServerStateAndIsIdempotent(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $source, $variant] = $this->fixture(
            $entityManager,
            3,
            125000,
        );
        $key = 'checkout-'.bin2hex(random_bytes(6));
        $payload = [
            'idempotency_key' => $key,
            'items' => [
                [
                    'variant_id' => $variant->id(),
                    'quantity' => 2,
                ],
            ],
        ];

        $client->jsonRequest(
            'POST',
            '/'.$tenant->slug().'/checkout',
            $payload,
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(250000, $created['order']['total_amount_minor']);
        self::assertIsString($created['order']['expires_at']);
        self::assertNotSame('', $created['order']['expires_at']);

        $client->jsonRequest(
            'POST',
            '/'.$tenant->slug().'/checkout',
            $payload,
        );
        self::assertResponseStatusCodeSame(200);
        $replayed = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            $created['order']['id'],
            $replayed['order']['id'],
        );

        self::assertSame(
            1,
            $entityManager->getRepository(Order::class)->count([
                'tenant' => $tenant,
                'idempotencyKey' => $key,
            ]),
        );
        $balance = $entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);
        self::assertInstanceOf(InventoryBalance::class, $balance);
        self::assertSame(3, $balance->quantity());
        self::assertSame(2, $balance->reservedQuantity());
    }

    public function testCheckoutRejectsClientSuppliedCommercialState(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, , $variant] = $this->fixture(
            $entityManager,
            3,
            125000,
        );

        $client->jsonRequest(
            'POST',
            '/'.$tenant->slug().'/checkout',
            [
                'idempotency_key' => 'tampered-'.bin2hex(random_bytes(6)),
                'price_list_id' => 'CLIENT-CONTROLLED',
                'items' => [[
                    'variant_id' => $variant->id(),
                    'quantity' => 1,
                ]],
            ],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsOversellWithoutBackorder(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $source, $variant] = $this->fixture(
            $entityManager,
            1,
            1000,
        );
        $key = 'oversell-'.bin2hex(random_bytes(6));

        $client->jsonRequest(
            'POST',
            '/'.$tenant->slug().'/checkout',
            [
                'idempotency_key' => $key,
                'items' => [[
                    'variant_id' => $variant->id(),
                    'quantity' => 2,
                ]],
            ],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            $entityManager->getRepository(Order::class)->findOneBy([
                'tenant' => $tenant,
                'idempotencyKey' => $key,
            ]),
        );
        $balance = $entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);
        self::assertInstanceOf(InventoryBalance::class, $balance);
        self::assertSame(0, $balance->reservedQuantity());
    }

    public function testCheckoutRateLimitsAnonymousClientPerTenant(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant] = $this->fixture($entityManager, 0, 1000);
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $client->jsonRequest(
                'POST',
                '/'.$tenant->slug().'/checkout',
                ['items' => []],
                server: ['REMOTE_ADDR' => '198.51.100.42'],
            );
            self::assertResponseStatusCodeSame(422);
        }

        $client->jsonRequest(
            'POST',
            '/'.$tenant->slug().'/checkout',
            ['items' => []],
            server: ['REMOTE_ADDR' => '198.51.100.42'],
        );
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @return array{Tenant, InventorySource, ProductVariant}
     */
    private function fixture(
        EntityManagerInterface $entityManager,
        int $stock,
        int $price,
    ): array {
        $suffix = strtolower(bin2hex(random_bytes(5)));
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
            'Body '.$suffix,
            'body-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'BODY-'.strtoupper($suffix),
            'Única',
        );
        $variantPrice = new VariantPrice(
            $tenant,
            $list,
            $variant,
            $price,
        );

        foreach (
            [
                $tenant,
                $legal,
                $source,
                $list,
                $channel,
                $product,
                $variant,
                $variantPrice,
            ] as $entity
        ) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        if ($stock > 0) {
            (new InventoryService($entityManager))->adjust(
                $tenant,
                $source,
                $variant,
                $stock,
                null,
                'seed-checkout-'.bin2hex(random_bytes(6)),
            );
        }

        return [$tenant, $source, $variant];
    }
}
