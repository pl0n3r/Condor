<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderEvent;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Tenant-scoped manual payment ledger using existing OrderEvent persistence.
 * Policy is trusted server configuration, never request-supplied.
 */
final readonly class OrderPaymentService
{
    private const EVENT = 'manual_payment_recorded';

    /**
     * @param array<string, array{allow_partial?: bool, methods?: list<string>}> $tenantPolicies
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private array $tenantPolicies = [],
    ) {
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function record(
        Order $order,
        string $tenantId,
        string $actorId,
        int $amountMinor,
        string $method,
        string $idempotencyKey,
    ): array {
        self::assertTenant($order, $tenantId);
        if ($actorId === '' || $amountMinor <= 0 || $amountMinor > 9007199254740991) {
            throw new DomainException('PAYMENT_INVALID_AMOUNT_OR_ACTOR');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120
            || preg_match('/^[A-Za-z0-9_-]+$/D', $idempotencyKey) !== 1) {
            throw new DomainException('PAYMENT_INVALID_IDEMPOTENCY_KEY');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $method)) {
            throw new DomainException('PAYMENT_INVALID_METHOD');
        }
        $policy = $this->tenantPolicies[$tenantId] ?? [];
        $methods = $policy['methods'] ?? ['cash', 'transfer'];
        $allowPartial = $policy['allow_partial'] ?? false;
        if (!in_array($method, $methods, true)) {
            throw new DomainException('PAYMENT_METHOD_NOT_CONFIGURED');
        }

        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use (
                $order, $tenantId, $actorId, $amountMinor, $method,
                $idempotencyKey, $allowPartial
            ): array {
                $em->refresh($order, LockMode::PESSIMISTIC_WRITE);
                self::assertTenant($order, $tenantId);
                $events = $this->events($em, $order);
                $summary = self::summarize($order, $events);
                foreach ($events as $event) {
                    $context = $event->context();
                    if (($context['idempotency_key'] ?? null) !== $idempotencyKey) {
                        continue;
                    }
                    if ($context['amount_minor'] !== $amountMinor
                        || $context['method'] !== $method) {
                        throw new DomainException('PAYMENT_IDEMPOTENCY_CONFLICT');
                    }
                    return $summary + ['idempotent' => true];
                }

                $remaining = $summary['pending_minor'];
                if ($order->orderStatus() !== Order::STATUS_OPEN
                    || $remaining === 0 || $amountMinor > $remaining) {
                    throw new DomainException('PAYMENT_ORDER_NOT_PAYABLE');
                }
                if (!$allowPartial && $amountMinor !== $remaining) {
                    throw new DomainException('PAYMENT_PARTIAL_DISABLED');
                }
                $em->persist(new OrderEvent(
                    $order,
                    self::EVENT,
                    $actorId,
                    [
                        'idempotency_key' => $idempotencyKey,
                        'amount_minor' => $amountMinor,
                        'method' => $method,
                        'currency' => $order->currency(),
                    ],
                ));
                $paid = $summary['paid_minor'] + $amountMinor;
                return [
                    'paid_minor' => $paid,
                    'pending_minor' => $order->totalAmountMinor() - $paid,
                    'payment_status' => $paid === $order->totalAmountMinor() ? 'paid' : 'pending',
                    'idempotent' => false,
                ];
            },
        );
    }

    /** @return array{paid_minor:int,pending_minor:int,payment_status:string} */
    public function balance(Order $order, string $tenantId): array
    {
        self::assertTenant($order, $tenantId);
        return self::summarize($order, $this->events($this->entityManager, $order));
    }

    private static function assertTenant(Order $order, string $tenantId): void
    {
        if ($tenantId === '' || !hash_equals($order->tenant()->id(), $tenantId)) {
            throw new DomainException('PAYMENT_TENANT_DENIED');
        }
    }

    /** @return list<OrderEvent> */
    private function events(EntityManagerInterface $em, Order $order): array
    {
        return $em->getRepository(OrderEvent::class)->findBy([
            'tenant' => $order->tenant(),
            'order' => $order,
            'type' => self::EVENT,
        ]);
    }

    /**
     * Server-only derived ledger. An invalid event fails closed instead of
     * silently lowering the pending balance or accepting a second payment.
     *
     * @param list<OrderEvent> $events
     * @return array{paid_minor:int,pending_minor:int,payment_status:string}
     */
    private static function summarize(Order $order, array $events): array
    {
        $total = $order->totalAmountMinor();
        $paid = 0;
        $keys = [];
        foreach ($events as $event) {
            if ($event->order()->id() !== $order->id()) {
                throw new DomainException('PAYMENT_LEDGER_CROSS_ORDER');
            }
            $context = $event->context();
            $amount = $context['amount_minor'] ?? null;
            $key = $context['idempotency_key'] ?? null;
            $method = $context['method'] ?? null;
            if (!is_int($amount) || $amount <= 0 || $amount > $total - $paid
                || !is_string($key) || $key === '' || isset($keys[$key])
                || !is_string($method) || $method === ''
                || ($context['currency'] ?? null) !== $order->currency()) {
                throw new DomainException('PAYMENT_LEDGER_INVALID');
            }
            $keys[$key] = true;
            $paid += $amount;
        }
        return [
            'paid_minor' => $paid,
            'pending_minor' => $total - $paid,
            'payment_status' => $paid === $total ? 'paid' : 'pending',
        ];
    }
}
