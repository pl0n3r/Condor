<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Application\Orders\OrderService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Creación de órdenes con reserva de uso atómica (#1486).
 *
 * Una única frontera transaccional (lock de escritura del tenant) cubre:
 * la orden, las reservas de inventario y la observación de uso `orders`.
 * Cualquier fallo (límite excedido, payload distinto con la misma llave,
 * inventario insuficiente) revierte las tres cosas. Un reintento con la misma
 * llave devuelve la orden existente y NO añade otra observación.
 *
 * El consumo se mide por ventana mensual UTC desde UsageObservation; no hay
 * contador persistido que pueda divergir. Un tenant sin suscripción o con un
 * snapshot sin el límite `orders` no tiene límite definido: no se inventa uno.
 */
final readonly class OrderCreationCoordinator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderService $orders,
        private TenantUsageReservation $reservation,
        private BillableUsage $usage,
        private SubscriptionEntitlementContextFactory $contexts,
        private EntitlementResolver $resolver,
    ) {
    }

    /**
     * Resuelve el snapshot comercial vigente del tenant y delega en
     * {@see createWithin()}. Sin suscripción no hay límite definido.
     *
     * @param list<array<string, mixed>> $items
     * @param null|callable(Order): void $onCreated Solo se invoca si la orden es nueva.
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $snapshot = $this->snapshotFor($tenant->id(), $now)
            ?? new EntitlementSnapshot($tenant->id(), 'sin-suscripcion', 0, 'general', [], [], [], [], $now);

        return $this->createWithin(
            new ExecutionContext($tenant->id(), $snapshot, $actorUserId ?? 'system'),
            $tenant,
            $source,
            $priceList,
            $channel,
            $customer,
            $actorUserId,
            $idempotencyKey,
            $items,
            $origin,
            $onCreated,
            $now,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param null|callable(Order): void $onCreated
     */
    public function createWithin(
        ExecutionContext $context,
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
        ?DateTimeImmutable $now = null,
    ): Order {
        if ($context->tenantId !== $tenant->id()) {
            throw new DomainException('El contexto comercial pertenece a otro tenant.');
        }
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $start = $now->modify('first day of this month')->setTime(0, 0);
        $end = $start->modify('+1 month');

        return $this->reservation->run(
            $context,
            fn (): Order => $this->orders->create(
                $tenant,
                $source,
                $priceList,
                $channel,
                $customer,
                $actorUserId,
                $idempotencyKey,
                $items,
                $origin,
                function (Order $order) use ($tenant, $context, $start, $end, $now, $onCreated): void {
                    if (array_key_exists(UsageMetric::Orders->value, $context->snapshot->limits())) {
                        $this->usage->assertCanAdd(
                            $context,
                            UsageMetric::Orders->value,
                            $this->ordersInWindow($tenant->id(), $start, $end),
                        );
                    }
                    $this->entityManager->persist(UsageObservation::fromRecord(new UsageRecord(
                        $tenant->id(),
                        UsageMetric::Orders,
                        1,
                        $start,
                        $end,
                        $now,
                    )));
                    $this->entityManager->flush();
                    if ($onCreated !== null) {
                        $onCreated($order);
                    }
                },
            ),
        );
    }

    private function snapshotFor(string $tenantId, DateTimeImmutable $now): ?EntitlementSnapshot
    {
        try {
            return $this->resolver->resolve($this->contexts->forTenant($tenantId, $now));
        } catch (DomainException $exception) {
            // Sin suscripción comercial no hay límite definido; cualquier otro
            // fallo del catálogo debe seguir fallando cerrado.
            if (str_starts_with($exception->getMessage(), 'Suscripción comercial no disponible')) {
                return null;
            }
            throw $exception;
        }
    }

    private function ordersInWindow(string $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $format = static fn (DateTimeImmutable $value): string => $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');

        return (int) $this->entityManager->createQuery(
            'SELECT COALESCE(SUM(o.quantity), 0) FROM '.UsageObservation::class.' o
             WHERE o.tenantId = :tenant AND o.metric = :metric
             AND o.windowStart = :start AND o.windowEnd = :end',
        )
            ->setParameter('tenant', $tenantId)
            ->setParameter('metric', UsageMetric::Orders->value)
            ->setParameter('start', $format($start))
            ->setParameter('end', $format($end))
            ->getSingleScalarResult();
    }
}
