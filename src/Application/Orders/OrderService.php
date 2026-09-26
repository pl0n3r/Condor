<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Application\Commerce\PricingService;
use App\Application\Inventory\InventoryService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventoryReservation;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderEvent;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class OrderService
{
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_ECOMMERCE = 'ecommerce';
    public const ECOMMERCE_RESERVATION_TTL = '+30 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PricingService $pricing,
        private InventoryService $inventory,
    ) {
    }

    public function cancel(
        Order $order,
        ?string $actorUserId,
    ): Order {
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use (
                $order,
                $actorUserId,
            ): Order {
                $entityManager->refresh(
                    $order,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (
                    $order->orderStatus() === Order::STATUS_CANCELLED
                    && $order->fulfillmentStatus()
                        === Order::FULFILLMENT_RELEASED
                ) {
                    return $order;
                }
                if (
                    $order->fulfillmentStatus()
                    === Order::FULFILLMENT_CONSUMED
                ) {
                    throw new DomainException(
                        'No se puede cancelar un pedido cuyo inventario ya fue consumido.',
                    );
                }

                $reservations = $this->reservationsForOrder($order);
                if ($reservations === []) {
                    throw new DomainException(
                        'El pedido reservado no tiene reservas asociadas.',
                    );
                }
                foreach ($reservations as $reservation) {
                    $this->inventory->releaseReservation(
                        $reservation,
                        'release:'.$order->id().':'.$reservation->id(),
                    );
                }

                if ($order->cancelAndRelease()) {
                    $entityManager->persist(new OrderEvent(
                        $order,
                        'cancelled',
                        $actorUserId,
                        ['inventory' => 'released'],
                    ));
                    $entityManager->persist(new AuditEvent(
                        $order->tenant(),
                        $actorUserId,
                        'order.cancelled',
                        Order::class,
                        $order->id(),
                        [
                            'legal_entity_id' => $order->legalEntity()->id(),
                            'inventory_source_id' => $order
                                ->inventorySource()->id(),
                        ],
                    ));
                }

                return $order;
            },
        );
    }

    public function release(
        Order $order,
        ?string $actorUserId,
    ): Order {
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use (
                $order,
                $actorUserId,
            ): Order {
                $entityManager->refresh(
                    $order,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (
                    $order->fulfillmentStatus()
                    === Order::FULFILLMENT_RELEASED
                ) {
                    return $order;
                }

                $reservations = $this->reservationsForOrder($order);
                if ($reservations === []) {
                    throw new DomainException(
                        'El pedido reservado no tiene reservas asociadas.',
                    );
                }
                foreach ($reservations as $reservation) {
                    $this->inventory->releaseReservation(
                        $reservation,
                        'release:'.$order->id().':'.$reservation->id(),
                    );
                }

                if ($order->markReleased()) {
                    $entityManager->persist(new OrderEvent(
                        $order,
                        'inventory_released',
                        $actorUserId,
                    ));
                    $entityManager->persist(new AuditEvent(
                        $order->tenant(),
                        $actorUserId,
                        'order.inventory_released',
                        Order::class,
                        $order->id(),
                        [
                            'legal_entity_id' => $order->legalEntity()->id(),
                            'inventory_source_id' => $order
                                ->inventorySource()->id(),
                        ],
                    ));
                }

                return $order;
            },
        );
    }

    public function consume(
        Order $order,
        ?string $actorUserId,
    ): Order {
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use (
                $order,
                $actorUserId,
            ): Order {
                $entityManager->refresh(
                    $order,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (
                    $order->fulfillmentStatus()
                    === Order::FULFILLMENT_CONSUMED
                ) {
                    return $order;
                }

                $reservations = $this->reservationsForOrder($order);
                if ($reservations === []) {
                    throw new DomainException(
                        'El pedido reservado no tiene reservas asociadas.',
                    );
                }
                foreach ($reservations as $reservation) {
                    $this->inventory->consumeReservation(
                        $reservation,
                        'consume:'.$order->id().':'.$reservation->id(),
                        $actorUserId,
                    );
                }

                if ($order->markConsumed()) {
                    $entityManager->persist(new OrderEvent(
                        $order,
                        'inventory_consumed',
                        $actorUserId,
                    ));
                    $entityManager->persist(new AuditEvent(
                        $order->tenant(),
                        $actorUserId,
                        'order.inventory_consumed',
                        Order::class,
                        $order->id(),
                        [
                            'legal_entity_id' => $order->legalEntity()->id(),
                            'inventory_source_id' => $order
                                ->inventorySource()->id(),
                        ],
                    ));
                }

                return $order;
            },
        );
    }

    /** @return list<InventoryReservation> */
    private function reservationsForOrder(Order $order): array
    {
        return $this->entityManager
            ->getRepository(InventoryReservation::class)
            ->findBy(['order' => $order], ['id' => 'ASC']);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param null|callable(Order): void $onCreated
     */
    public function create(
        Tenant $tenant,
        InventorySource $source,
        PriceList $priceList,
        ?SalesChannel $channel,
        ?Customer $customer,
        ?string $actorUserId,
        string $idempotencyKey,
        array $items,
        string $origin,
        ?callable $onCreated = null,
    ): Order {
        $origin = self::normalizeOrigin($origin);
        $normalizedItems = self::normalizeItems($tenant, $items);
        self::assertCommercialContext(
            $tenant,
            $source,
            $priceList,
            $channel,
            $customer,
            $origin,
        );

        try {
            return $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use (
                    $tenant,
                    $source,
                    $priceList,
                    $channel,
                    $customer,
                    $actorUserId,
                    $idempotencyKey,
                    $normalizedItems,
                    $origin,
                    $onCreated,
                ): Order {
                    $existing = $entityManager
                        ->getRepository(Order::class)
                        ->findOneBy([
                            'tenant' => $tenant,
                            'idempotencyKey' => trim($idempotencyKey),
                        ]);
                    if ($existing instanceof Order) {
                        $this->assertSameOrderRequest(
                            $existing,
                            $source,
                            $priceList,
                            $channel,
                            $customer,
                            $normalizedItems,
                        );

                        return $existing;
                    }

                    if ($channel instanceof SalesChannel) {
                        $entityManager->refresh(
                            $channel,
                            LockMode::PESSIMISTIC_READ,
                        );
                    }
                    $entityManager->refresh(
                        $priceList,
                        LockMode::PESSIMISTIC_READ,
                    );
                    if ($customer instanceof Customer) {
                        $entityManager->refresh(
                            $customer,
                            LockMode::PESSIMISTIC_READ,
                        );
                    }

                    self::assertCommercialContext(
                        $tenant,
                        $source,
                        $priceList,
                        $channel,
                        $customer,
                        $origin,
                    );

                    $resolved = [];
                    $total = 0;
                    foreach ($normalizedItems as $item) {
                        $variant = $item['variant'];
                        $quantity = $item['quantity'];
                        $price = $this->pricing->resolve(
                            $tenant,
                            $variant,
                            $priceList,
                            $customer,
                        );
                        $resolved[] = [
                            'variant' => $variant,
                            'quantity' => $quantity,
                            'price' => $price,
                        ];

                        if (
                            $price->effectiveAmountMinor !== 0
                            && $quantity > intdiv(
                                9007199254740991,
                                $price->effectiveAmountMinor,
                            )
                        ) {
                            throw new DomainException(
                                'El total del pedido está fuera del rango monetario permitido.',
                            );
                        }
                        $lineTotal = $price->effectiveAmountMinor * $quantity;
                        if ($total > 9007199254740991 - $lineTotal) {
                            throw new DomainException(
                                'El total del pedido está fuera del rango monetario permitido.',
                            );
                        }
                        $total += $lineTotal;
                    }

                    $order = new Order(
                        $tenant,
                        $source,
                        $channel,
                        $customer,
                        $actorUserId,
                        $idempotencyKey,
                        $priceList->currency(),
                        $total,
                        $origin === self::ORIGIN_ECOMMERCE
                            ? (new DateTimeImmutable(
                                'now',
                                new DateTimeZone('UTC'),
                            ))->modify(self::ECOMMERCE_RESERVATION_TTL)
                            : null,
                    );
                    $entityManager->persist($order);

                    foreach ($resolved as $item) {
                        $price = $item['price'];
                        $line = new OrderLine(
                            $order,
                            $item['variant'],
                            $priceList,
                            $item['quantity'],
                            $price->baseAmountMinor,
                            $price->effectiveAmountMinor,
                            $price->currency,
                            $price->ruleId,
                        );
                        $entityManager->persist($line);
                        $this->inventory->reserveOrderLine(
                            $line,
                            'reserve:'.$order->id().':'.$line->id(),
                        );
                    }

                    $entityManager->persist(new OrderEvent(
                        $order,
                        'created',
                        $actorUserId,
                        ['origin' => $origin],
                    ));
                    $entityManager->persist(new AuditEvent(
                        $tenant,
                        $actorUserId,
                        'order.created',
                        Order::class,
                        $order->id(),
                        [
                            'origin' => $origin,
                            'legal_entity_id' => $order->legalEntity()->id(),
                            'inventory_source_id' => $source->id(),
                            'sales_channel_id' => $channel?->id(),
                            'customer_id' => $customer?->id(),
                            'total_amount_minor' => $total,
                            'currency' => $priceList->currency(),
                        ],
                    ));

                    if ($onCreated !== null) {
                        $onCreated($order);
                    }

                    return $order;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new OrderConflictException(
                'La creación del pedido entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{variant: ProductVariant, quantity: int}>
     */
    private static function normalizeItems(
        Tenant $tenant,
        array $items,
    ): array {
        if ($items === []) {
            throw new DomainException(
                'El pedido debe contener al menos una variante.',
            );
        }

        /** @var array<string, array{variant: ProductVariant, quantity: int}> $normalized */
        $normalized = [];
        foreach ($items as $item) {
            $variant = $item['variant'] ?? null;
            $quantity = $item['quantity'] ?? null;
            if (
                !$variant instanceof ProductVariant
                || !is_int($quantity)
                || $quantity <= 0
                || $quantity > 2147483647
                || $variant->tenant()->id() !== $tenant->id()
            ) {
                throw new DomainException(
                    'Las líneas del pedido contienen una variante o cantidad inválida.',
                );
            }

            $id = $variant->id();
            $current = $normalized[$id]['quantity'] ?? 0;
            if ($current > 2147483647 - $quantity) {
                throw new DomainException(
                    'La cantidad consolidada de una variante excede el rango permitido.',
                );
            }
            $normalized[$id] = [
                'variant' => $variant,
                'quantity' => $current + $quantity,
            ];
        }

        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    private static function assertCommercialContext(
        Tenant $tenant,
        InventorySource $source,
        PriceList $priceList,
        ?SalesChannel $channel,
        ?Customer $customer,
        string $origin,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || !$source->isActive()
            || $priceList->tenant()->id() !== $tenant->id()
            || !$priceList->isActive()
        ) {
            throw new DomainException(
                'La fuente y la lista de precios deben estar activas en el tenant.',
            );
        }

        if (
            $customer instanceof Customer
            && (
                $customer->tenant()->id() !== $tenant->id()
                || !$customer->isActive()
            )
        ) {
            throw new DomainException(
                'El cliente no está disponible en el tenant activo.',
            );
        }

        if ($channel instanceof SalesChannel) {
            if (
                $channel->tenant()->id() !== $tenant->id()
                || $channel->legalEntity()->id() !== $source->legalEntity()->id()
                || $channel->inventorySource()->id() !== $source->id()
                || $channel->priceList()->id() !== $priceList->id()
                || !$channel->isPublishable()
            ) {
                throw new DomainException(
                    'El canal no coincide con la fuente y lista efectivas del pedido.',
                );
            }
        }

        if (
            $origin === self::ORIGIN_ECOMMERCE
            && !$channel instanceof SalesChannel
        ) {
            throw new DomainException(
                'Un pedido e-commerce requiere un canal efectivo.',
            );
        }
    }

    /**
     * @param list<array{variant: ProductVariant, quantity: int}> $items
     */
    private function assertSameOrderRequest(
        Order $order,
        InventorySource $source,
        PriceList $priceList,
        ?SalesChannel $channel,
        ?Customer $customer,
        array $items,
    ): void {
        if (
            $order->inventorySource()->id() !== $source->id()
            || $order->salesChannel()?->id() !== $channel?->id()
            || $order->customer()?->id() !== $customer?->id()
        ) {
            throw new DomainException(
                'La clave de idempotencia ya fue usada por otro pedido.',
            );
        }

        $existingLines = $this->entityManager
            ->getRepository(OrderLine::class)
            ->findBy(['order' => $order]);

        $expected = [];
        foreach ($items as $item) {
            $expected[$item['variant']->id()] = $item['quantity'];
        }

        $actual = [];
        foreach ($existingLines as $line) {
            if (
                $line->priceList()->id() !== $priceList->id()
            ) {
                throw new DomainException(
                    'La clave de idempotencia ya fue usada por otro pedido.',
                );
            }
            $actual[$line->variant()->id()] = $line->quantity();
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        if ($expected !== $actual) {
            throw new DomainException(
                'La clave de idempotencia ya fue usada por otro pedido.',
            );
        }
    }

    private static function normalizeOrigin(string $origin): string
    {
        $origin = strtolower(trim($origin));
        if (!in_array(
            $origin,
            [self::ORIGIN_MANUAL, self::ORIGIN_ECOMMERCE],
            true,
        )) {
            throw new DomainException('El origen del pedido no es válido.');
        }

        return $origin;
    }
}
