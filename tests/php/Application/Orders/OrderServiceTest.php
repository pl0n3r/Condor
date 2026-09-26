<?php

declare(strict_types=1);

namespace App\Tests\Application\Orders;

use App\Application\Commerce\PricingService;
use App\Application\Inventory\InventoryService;
use App\Application\Orders\ExpiredOrderReleaseService;
use App\Application\Orders\OrderService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventoryReservation;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderEvent;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InventoryService $inventory;
    private OrderService $orders;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        $pricing = static::getContainer()->get(PricingService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(PricingService::class, $pricing);

        $this->entityManager = $entityManager;
        $this->inventory = new InventoryService($entityManager);
        $this->orders = new OrderService(
            $entityManager,
            $pricing,
            $this->inventory,
        );
    }

    public function testCreateSnapshotsPriceAndReservesStockAtomically(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 12500);
        $key = 'order-'.bin2hex(random_bytes(6));

        $order = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            $key,
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );

        self::assertSame(25000, $order->totalAmountMinor());
        self::assertNotNull($order->expiresAt());
        $line = $this->entityManager
            ->getRepository(OrderLine::class)
            ->findOneBy(['order' => $order]);
        self::assertInstanceOf(OrderLine::class, $line);
        self::assertSame(12500, $line->effectiveAmountMinor());
        self::assertSame(25000, $line->lineTotalMinor());

        $reservation = $this->entityManager
            ->getRepository(InventoryReservation::class)
            ->findOneBy(['order' => $order]);
        self::assertInstanceOf(InventoryReservation::class, $reservation);
        self::assertSame(InventoryReservation::STATUS_ACTIVE, $reservation->status());

        $balance = $this->balance($tenant, $source, $variant);
        self::assertSame(5, $balance->quantity());
        self::assertSame(2, $balance->reservedQuantity());
        self::assertSame(3, $balance->sellableQuantity());

        self::assertSame(
            1,
            $this->entityManager->getRepository(OrderEvent::class)
                ->count(['order' => $order]),
        );
        self::assertSame(
            1,
            $this->entityManager->getRepository(AuditEvent::class)
                ->count([
                    'tenant' => $tenant,
                    'action' => 'order.created',
                    'entityId' => $order->id(),
                ]),
        );

        $replayed = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            $key,
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );
        self::assertSame($order->id(), $replayed->id());
        self::assertSame(
            2,
            $this->balance($tenant, $source, $variant)->reservedQuantity(),
        );
        self::assertSame(
            1,
            $this->entityManager->getRepository(Order::class)
                ->count(['tenant' => $tenant, 'idempotencyKey' => $key]),
        );
    }

    public function testExpiredEcommerceOrderReleasesReservation(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $order = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            'expiry-'.bin2hex(random_bytes(6)),
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );
        self::assertNotNull($order->expiresAt());
        self::assertSame(
            2,
            $this->balance($tenant, $source, $variant)->reservedQuantity(),
        );

        $releaser = static::getContainer()->get(
            ExpiredOrderReleaseService::class,
        );
        self::assertInstanceOf(ExpiredOrderReleaseService::class, $releaser);
        $cutoff = $order->expiresAt()->modify('+1 second');
        $expectedReleased = min(
            100,
            (int) $this->entityManager
                ->createQueryBuilder()
                ->select('COUNT(expired.id)')
                ->from(Order::class, 'expired')
                ->andWhere('expired.expiresAt IS NOT NULL')
                ->andWhere('expired.expiresAt <= :cutoff')
                ->andWhere('expired.fulfillmentStatus = :reserved')
                ->setParameter('cutoff', $cutoff)
                ->setParameter(
                    'reserved',
                    Order::FULFILLMENT_RESERVED,
                )
                ->getQuery()
                ->getSingleScalarResult(),
        );
        $released = $releaser->releaseExpired($cutoff);

        self::assertSame($expectedReleased, $released);
        self::assertSame(
            Order::FULFILLMENT_RELEASED,
            $order->fulfillmentStatus(),
        );
        self::assertSame(
            0,
            $this->balance($tenant, $source, $variant)->reservedQuantity(),
        );
    }

    public function testIdempotencyKeyRejectsDifferentOrderPayload(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $key = 'order-conflict-'.bin2hex(random_bytes(6));

        $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            $key,
            [['variant' => $variant, 'quantity' => 1]],
            OrderService::ORIGIN_ECOMMERCE,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'La clave de idempotencia ya fue usada por otro pedido.',
        );

        $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            $key,
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );
    }

    public function testInsufficientStockRollsBackWholeOrder(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(1, 1000);
        $key = 'order-stock-'.bin2hex(random_bytes(6));

        try {
            $this->orders->create(
                $tenant,
                $source,
                $list,
                $channel,
                null,
                null,
                $key,
                [['variant' => $variant, 'quantity' => 2]],
                OrderService::ORIGIN_ECOMMERCE,
            );
            self::fail('El pedido sin disponibilidad debía rechazarse.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'disponibilidad vendible',
                $exception->getMessage(),
            );
        }

        self::assertNull(
            $this->entityManager->getRepository(Order::class)
                ->findOneBy(['tenant' => $tenant, 'idempotencyKey' => $key]),
        );
        self::assertSame(
            0,
            $this->balance($tenant, $source, $variant)->reservedQuantity(),
        );
    }

    public function testReleaseAndConsumeAreExactlyOnce(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);

        $releaseOrder = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            'release-order-'.bin2hex(random_bytes(6)),
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );
        $releaseReservation = $this->reservation($releaseOrder);
        $releaseKey = 'release-'.bin2hex(random_bytes(6));
        $this->inventory->releaseReservation($releaseReservation, $releaseKey);
        $this->inventory->releaseReservation($releaseReservation, $releaseKey);

        self::assertSame(
            0,
            $this->balance($tenant, $source, $variant)->reservedQuantity(),
        );

        $consumeOrder = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            'consume-order-'.bin2hex(random_bytes(6)),
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );
        $consumeReservation = $this->reservation($consumeOrder);
        $consumeKey = 'consume-'.bin2hex(random_bytes(6));
        $this->inventory->consumeReservation(
            $consumeReservation,
            $consumeKey,
        );
        $this->inventory->consumeReservation(
            $consumeReservation,
            $consumeKey,
        );

        $balance = $this->balance($tenant, $source, $variant);
        self::assertSame(3, $balance->quantity());
        self::assertSame(0, $balance->reservedQuantity());
        self::assertSame(
            1,
            $this->entityManager->getRepository(InventoryMovement::class)
                ->count([
                    'tenant' => $tenant,
                    'type' => InventoryMovement::TYPE_ORDER_CONSUMPTION,
                    'idempotencyKey' => $consumeKey,
                ]),
        );
    }

    public function testCancelReleasesReservationExactlyOnce(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $order = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            'cancel-order-'.bin2hex(random_bytes(6)),
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );

        $this->orders->cancel($order, null);
        $this->orders->cancel($order, null);

        self::assertSame(Order::STATUS_CANCELLED, $order->orderStatus());
        self::assertSame(
            Order::FULFILLMENT_RELEASED,
            $order->fulfillmentStatus(),
        );
        $balance = $this->balance($tenant, $source, $variant);
        self::assertSame(5, $balance->quantity());
        self::assertSame(0, $balance->reservedQuantity());
        self::assertSame(
            1,
            $this->entityManager->getRepository(OrderEvent::class)
                ->count(['order' => $order, 'type' => 'cancelled']),
        );
    }

    public function testConsumeDecrementsStockExactlyOnceAndCannotThenCancel(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $order = $this->orders->create(
            $tenant,
            $source,
            $list,
            $channel,
            null,
            null,
            'consume-transition-'.bin2hex(random_bytes(6)),
            [['variant' => $variant, 'quantity' => 2]],
            OrderService::ORIGIN_ECOMMERCE,
        );

        $this->orders->consume($order, null);
        $this->orders->consume($order, null);

        self::assertSame(
            Order::FULFILLMENT_CONSUMED,
            $order->fulfillmentStatus(),
        );
        $balance = $this->balance($tenant, $source, $variant);
        self::assertSame(3, $balance->quantity());
        self::assertSame(0, $balance->reservedQuantity());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'No se puede cancelar un pedido cuyo inventario ya fue consumido.',
        );
        $this->orders->cancel($order, null);
    }

    /**
     * @return array{
     *   Tenant,
     *   InventorySource,
     *   PriceList,
     *   SalesChannel,
     *   ProductVariant
     * }
     */
    private function fixture(int $stock, int $price): array
    {
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $legal = new LegalEntity(
            $tenant,
            'Empresa '.$suffix.' SAS',
            null,
            true,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal-'.$suffix,
            $legal,
            true,
        );
        $source = new InventorySource(
            $tenant,
            $legal,
            'Principal',
            'principal-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        $product = new Product(
            $tenant,
            'Producto '.$suffix,
            'producto-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-'.$suffix,
            'Variante '.$suffix,
        );
        $list = new PriceList(
            $tenant,
            'Lista '.$suffix,
            'lista-'.$suffix,
        );
        $variantPrice = new VariantPrice(
            $tenant,
            $list,
            $variant,
            $price,
        );
        $channel = new SalesChannel(
            $tenant,
            'Web '.$suffix,
            'web-'.$suffix,
            $source,
            $list,
        );

        foreach (
            [
                $tenant,
                $legal,
                $branch,
                $source,
                $product,
                $variant,
                $list,
                $variantPrice,
                $channel,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        if ($stock > 0) {
            $this->inventory->adjust(
                $tenant,
                $source,
                $variant,
                $stock,
                null,
                'seed-'.bin2hex(random_bytes(6)),
            );
        }

        return [$tenant, $source, $list, $channel, $variant];
    }

    private function balance(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): InventoryBalance {
        $balance = $this->entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);
        self::assertInstanceOf(InventoryBalance::class, $balance);

        return $balance;
    }

    private function reservation(Order $order): InventoryReservation
    {
        $reservation = $this->entityManager
            ->getRepository(InventoryReservation::class)
            ->findOneBy(['order' => $order]);
        self::assertInstanceOf(InventoryReservation::class, $reservation);

        return $reservation;
    }
}
