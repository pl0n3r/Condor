<?php

declare(strict_types=1);

namespace App\Tests\Domain\Orders;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventoryReservation;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderEvent;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class OrderDomainTest extends TestCase
{
    public function testOrderKeepsIndependentStatusesAndCommercialScope(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->scope();
        $order = new Order(
            $tenant,
            $source,
            $channel,
            null,
            'actor-1',
            'order-1',
            'COP',
            25000,
        );
        $line = new OrderLine(
            $order,
            $variant,
            $list,
            2,
            15000,
            12500,
            'COP',
            null,
        );

        self::assertSame(Order::STATUS_OPEN, $order->orderStatus());
        self::assertSame(Order::PAYMENT_PENDING, $order->paymentStatus());
        self::assertSame(Order::FULFILLMENT_RESERVED, $order->fulfillmentStatus());
        self::assertSame($source->legalEntity()->id(), $order->legalEntity()->id());
        self::assertSame(25000, $line->lineTotalMinor());
    }

    public function testOrderRejectsChannelFromDifferentEffectiveSource(): void
    {
        [$tenant, $source, $list, , ] = $this->scope();
        $otherSource = new InventorySource(
            $tenant,
            $source->legalEntity(),
            'Otra fuente',
            'otra-fuente',
            InventorySource::TYPE_LOGICAL,
        );
        $channel = new SalesChannel(
            $tenant,
            'Web',
            'web',
            $otherSource,
            $list,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('canal y la fuente');

        new Order(
            $tenant,
            $source,
            $channel,
            null,
            null,
            'order-scope',
            'COP',
            0,
        );
    }

    public function testOrderRejectsCrossTenantCustomer(): void
    {
        [$tenant, $source, , $channel] = $this->scope();
        $otherTenant = new Tenant('Otra', 'otra-'.bin2hex(random_bytes(3)));
        $customer = new Customer($otherTenant, 'Cliente ajeno');

        $this->expectException(DomainException::class);
        new Order(
            $tenant,
            $source,
            $channel,
            $customer,
            null,
            'order-customer',
            'COP',
            0,
        );
    }

    public function testReservationTransitionsAreIdempotentButMutuallyExclusive(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->scope();
        $order = new Order(
            $tenant,
            $source,
            $channel,
            null,
            null,
            'order-reservation',
            'COP',
            1000,
        );
        $line = new OrderLine(
            $order,
            $variant,
            $list,
            1,
            1000,
            1000,
            'COP',
            null,
        );
        $reservation = new InventoryReservation(
            $line,
            $source,
            'reserve-1',
        );

        self::assertTrue($reservation->markReleased('release-1'));
        self::assertFalse($reservation->markReleased('release-1'));
        self::assertSame(InventoryReservation::STATUS_RELEASED, $reservation->status());

        $this->expectException(DomainException::class);
        $reservation->markConsumed('consume-1');
    }

    public function testOrderLineRejectsMonetaryMultiplicationOverflow(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->scope();
        $order = new Order(
            $tenant,
            $source,
            $channel,
            null,
            null,
            'order-overflow',
            'COP',
            0,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('total de la línea');

        new OrderLine(
            $order,
            $variant,
            $list,
            2,
            9007199254740991,
            9007199254740991,
            'COP',
            null,
        );
    }

    public function testOrderEventIsAppendOnlySnapshot(): void
    {
        [$tenant, $source, , $channel] = $this->scope();
        $order = new Order(
            $tenant,
            $source,
            $channel,
            null,
            null,
            'order-event',
            'COP',
            0,
        );
        $event = new OrderEvent($order, 'created', null, ['origin' => 'manual']);

        self::assertSame('created', $event->type());
        self::assertSame(['origin' => 'manual'], $event->context());
    }

    /** @return array{Tenant, InventorySource, PriceList, SalesChannel, ProductVariant} */
    private function scope(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = new Tenant('Empresa', 'empresa-'.$suffix);
        $legal = new LegalEntity($tenant, 'Empresa SAS', null, true);
        $source = new InventorySource(
            $tenant,
            $legal,
            'Bodega',
            'bodega',
            InventorySource::TYPE_LOGICAL,
        );
        $list = new PriceList($tenant, 'Lista', 'lista');
        $channel = new SalesChannel(
            $tenant,
            'Web',
            'web',
            $source,
            $list,
        );
        $product = new Product($tenant, 'Producto', 'producto-'.$suffix);
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-'.$suffix,
            'Variante',
        );

        return [$tenant, $source, $list, $channel, $variant];
    }
}
