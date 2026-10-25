<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderEvent;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/** Delivery events are separate from the inventory fulfillment status of Order. */
final readonly class OrderFulfillmentService
{
    private const NEXT = [
        'shipping' => ['pending' => ['shipped'], 'shipped' => ['delivered'], 'delivered' => []],
        'pickup' => ['pending' => ['ready'], 'ready' => ['collected'], 'collected' => []],
        'none' => ['pending' => ['completed'], 'completed' => []],
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, mixed> */
    public static function initial(string $tenantId): array
    {
        if (trim($tenantId) === '') {
            throw new DomainException('DELIVERY_TENANT_REQUIRED');
        }

        return ['tenant_id' => $tenantId, 'leg_id' => 'primary',
            'method' => null, 'status' => null, 'tracking_number' => null, 'history' => []];
    }

    /**
     * Pure, auditable transition policy. A leg identifier allows later partial fulfilments.
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function apply(
        array $snapshot,
        string $authorizedTenantId,
        string $actorId,
        string $action,
        string $value,
        ?string $trackingNumber,
        DateTimeImmutable $at,
    ): array {
        $tenantId = $snapshot['tenant_id'] ?? null;
        if (!is_string($tenantId) || $tenantId === ''
            || !hash_equals($tenantId, $authorizedTenantId)
            || trim($actorId) === '' || !is_array($snapshot['history'] ?? null)
            || ($snapshot['leg_id'] ?? null) !== 'primary') {
            throw new DomainException('DELIVERY_TENANT_OR_ACTOR_DENIED');
        }

        $next = $snapshot;
        $from = $snapshot['status'] ?? null;
        if ($action === 'configure') {
            if (($snapshot['method'] ?? null) !== null || !isset(self::NEXT[$value])) {
                throw new DomainException('DELIVERY_METHOD_INVALID');
            }
            if ($trackingNumber !== null) {
                $trackingNumber = strtoupper(trim($trackingNumber));
                if ($value !== 'shipping'
                    || preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/D', $trackingNumber) !== 1) {
                    throw new DomainException('DELIVERY_TRACKING_INVALID');
                }
            }
            $next['method'] = $value;
            $next['status'] = 'pending';
            $next['tracking_number'] = $trackingNumber;
        } elseif ($action === 'transition') {
            $method = $snapshot['method'] ?? null;
            if (!is_string($method) || !isset(self::NEXT[$method])
                || !is_string($from) || !isset(self::NEXT[$method][$from])
                || !in_array($value, self::NEXT[$method][$from], true)
                || $trackingNumber !== null) {
                throw new DomainException('DELIVERY_TRANSITION_DENIED');
            }
            $next['status'] = $value;
        } else {
            throw new DomainException('DELIVERY_ACTION_INVALID');
        }

        $next['history'][] = [
            'leg_id' => 'primary', 'action' => $action,
            'from' => $from, 'to' => $next['status'], 'method' => $next['method'],
            'actor' => $actorId, 'at' => $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];

        return $next;
    }

    /** @return array<string, mixed> */
    public function current(Order $order): array
    {
        $tenantId = $order->tenant()->id();
        $state = self::initial($tenantId);
        $events = $this->entityManager->getRepository(OrderEvent::class)->findBy(
            ['order' => $order, 'type' => ['delivery.configured', 'delivery.transition']],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
        foreach ($events as $event) {
            $context = $event->context();
            $actor = $context['actor'] ?? null;
            $value = $context['value'] ?? null;
            $tracking = $context['tracking_number'] ?? null;
            if (!is_string($actor) || !is_string($value)
                || ($tracking !== null && !is_string($tracking))
                || ($context['leg_id'] ?? null) !== 'primary') {
                throw new DomainException('DELIVERY_HISTORY_INVALID');
            }
            $action = $event->type() === 'delivery.configured' ? 'configure' : 'transition';
            $state = self::apply($state, $tenantId, $actor, $action, $value, $tracking, $event->createdAt());
        }

        return $state;
    }

    /** @return array<string, mixed> */
    public function configure(Order $order, string $tenantId, string $actorId, string $method, ?string $trackingNumber): array
    {
        return $this->write($order, $tenantId, $actorId, 'configure', $method, $trackingNumber);
    }

    /** @return array<string, mixed> */
    public function transition(Order $order, string $tenantId, string $actorId, string $status): array
    {
        return $this->write($order, $tenantId, $actorId, 'transition', $status, null);
    }

    /** @return array<string, mixed> */
    private function write(
        Order $order,
        string $tenantId,
        string $actorId,
        string $action,
        string $value,
        ?string $trackingNumber,
    ): array {
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($order, $tenantId, $actorId, $action, $value, $trackingNumber): array {
                $em->refresh($order, LockMode::PESSIMISTIC_WRITE);
                if (!hash_equals($order->tenant()->id(), $tenantId)
                    || $order->orderStatus() !== Order::STATUS_OPEN
                    || $order->fulfillmentStatus() === Order::FULFILLMENT_RELEASED) {
                    throw new DomainException('DELIVERY_ORDER_NOT_ALLOWED');
                }
                $next = self::apply(
                    $this->current($order), $tenantId, $actorId, $action, $value, $trackingNumber,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
                $em->persist(new OrderEvent(
                    $order,
                    $action === 'configure' ? 'delivery.configured' : 'delivery.transition',
                    $actorId,
                    ['leg_id' => 'primary', 'actor' => $actorId, 'value' => $value,
                        'tracking_number' => $trackingNumber],
                ));

                return $next;
            },
        );
    }
}
