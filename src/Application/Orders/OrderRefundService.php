<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Application\Inventory\InventoryService;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderEvent;
use App\Domain\Orders\Entity\OrderLine;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/** Auditable, tenant-scoped refunds of recorded manual payments; no gateway I/O. */
final readonly class OrderRefundService
{
    private const PAYMENT = 'manual_payment_recorded';
    private const REFUND = 'manual_payment_refunded';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private InventoryService $inventory,
    ) {
    }

    /** @return array{payment_key:string,refunded_minor:int,refundable_minor:int,restocked:bool,idempotent:bool} */
    public function refund(
        Order $order,
        string $tenantId,
        string $actorId,
        string $paymentKey,
        int $amountMinor,
        string $reason,
        string $idempotencyKey,
        ?string $restockLineId = null,
        ?int $restockQuantity = null,
    ): array {
        self::assertTenant($order, $tenantId);
        $reason = trim($reason);
        if ($actorId === '' || !in_array($reason, ['customer_return', 'payment_correction', 'order_cancelled', 'other'], true)
            || $amountMinor < 1 || $amountMinor > 9007199254740991
            || !self::validKey($paymentKey) || !self::validKey($idempotencyKey)
            || (($restockLineId === null) !== ($restockQuantity === null))
            || ($restockLineId !== null && ($restockLineId === '' || strlen($restockLineId) > 120
                || $restockQuantity < 1 || $restockQuantity > 2147483647))) {
            throw new DomainException('REFUND_INVALID_INPUT');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use (
            $order, $tenantId, $actorId, $paymentKey, $amountMinor, $reason,
            $idempotencyKey, $restockLineId, $restockQuantity,
        ): array {
            $em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            self::assertTenant($order, $tenantId);
            /** @var list<OrderEvent> $events */
            $events = $em->getRepository(OrderEvent::class)->findBy([
                'tenant' => $order->tenant(), 'order' => $order,
            ]);
            $paid = null;
            $refunded = 0;
            $restocked = 0;
            $previous = null;
            foreach ($events as $event) {
                if ($event->order()->id() !== $order->id()) {
                    throw new DomainException('REFUND_LEDGER_CROSS_ORDER');
                }
                $ctx = $event->context();
                if ($event->type() === self::PAYMENT
                    && ($ctx['idempotency_key'] ?? null) === $paymentKey) {
                    if ($paid !== null || !is_int($ctx['amount_minor'] ?? null)
                        || $ctx['amount_minor'] < 1 || ($ctx['currency'] ?? null) !== $order->currency()) {
                        throw new DomainException('REFUND_PAYMENT_INVALID');
                    }
                    $paid = $ctx['amount_minor'];
                }
                if ($event->type() !== self::REFUND) {
                    continue;
                }
                $sum = $ctx['amount_minor'] ?? null;
                if (!is_int($sum) || $sum < 1 || !self::validKey($ctx['idempotency_key'] ?? null)
                    || !self::validKey($ctx['payment_key'] ?? null)
                    || ($ctx['currency'] ?? null) !== $order->currency()) {
                    throw new DomainException('REFUND_LEDGER_INVALID');
                }
                if ($ctx['idempotency_key'] === $idempotencyKey) {
                    if ($previous !== null) {
                        throw new DomainException('REFUND_LEDGER_DUPLICATE_KEY');
                    }
                    $previous = $ctx;
                }
                if ($ctx['payment_key'] === $paymentKey) {
                    $refunded += $sum;
                    if ($refunded > 9007199254740991) {
                        throw new DomainException('REFUND_LEDGER_INVALID');
                    }
                }
                if ($restockLineId !== null && ($ctx['restock_line_id'] ?? null) === $restockLineId) {
                    $qty = $ctx['restock_quantity'] ?? null;
                    if (!is_int($qty) || $qty < 1 || $qty > 2147483647 - $restocked) {
                        throw new DomainException('REFUND_RESTOCK_LEDGER_INVALID');
                    }
                    $restocked += $qty;
                }
            }
            if ($paid === null || $refunded > $paid) {
                throw new DomainException('REFUND_PAYMENT_NOT_FOUND_OR_INVALID');
            }
            if ($previous !== null) {
                if ($previous['payment_key'] !== $paymentKey || $previous['amount_minor'] !== $amountMinor
                    || ($previous['reason'] ?? null) !== $reason
                    || ($previous['restock_line_id'] ?? null) !== $restockLineId
                    || ($previous['restock_quantity'] ?? null) !== $restockQuantity) {
                    throw new DomainException('REFUND_IDEMPOTENCY_CONFLICT');
                }
                return self::response($paymentKey, $refunded, $paid - $refunded, $restockLineId !== null, true);
            }
            if ($order->orderStatus() !== Order::STATUS_OPEN || $amountMinor > $paid - $refunded) {
                throw new DomainException('REFUND_NOT_ALLOWED_OR_OVERPAID');
            }
            if ($restockLineId !== null) {
                if ($order->fulfillmentStatus() !== Order::FULFILLMENT_CONSUMED) {
                    throw new DomainException('REFUND_STOCK_NOT_CONSUMED');
                }
                $line = $em->getRepository(OrderLine::class)->findOneBy([
                    'tenant' => $order->tenant(), 'order' => $order, 'id' => $restockLineId,
                ]);
                if (!$line instanceof OrderLine || $restockQuantity > $line->quantity() - $restocked) {
                    throw new DomainException('REFUND_RESTOCK_EXCEEDS_SOLD');
                }
                $this->inventory->adjust(
                    $order->tenant(), $order->inventorySource(), $line->variant(),
                    $restockQuantity, $actorId,
                    'refund_'.hash('sha256', $tenantId.'|'.$order->id().'|'.$idempotencyKey.'|'.$restockLineId),
                    ['order_id' => $order->id(), 'order_line_id' => $restockLineId, 'refund_key' => $idempotencyKey],
                );
            }
            $em->persist(new OrderEvent($order, self::REFUND, $actorId, [
                'idempotency_key' => $idempotencyKey,
                'payment_key' => $paymentKey,
                'amount_minor' => $amountMinor,
                'currency' => $order->currency(),
                'reason' => $reason,
                'restock_line_id' => $restockLineId,
                'restock_quantity' => $restockQuantity,
            ]));
            $refunded += $amountMinor;
            return self::response($paymentKey, $refunded, $paid - $refunded, $restockLineId !== null, false);
        });
    }

    private static function assertTenant(Order $order, string $tenantId): void
    {
        if ($tenantId === '' || !hash_equals($order->tenant()->id(), $tenantId)) {
            throw new DomainException('REFUND_TENANT_DENIED');
        }
    }

    private static function validKey(mixed $key): bool
    {
        return is_string($key) && strlen($key) >= 1 && strlen($key) <= 120
            && preg_match('/^[A-Za-z0-9_-]+$/D', $key) === 1;
    }

    /** @return array{payment_key:string,refunded_minor:int,refundable_minor:int,restocked:bool,idempotent:bool} */
    private static function response(string $key, int $refunded, int $remaining, bool $restocked, bool $idempotent): array
    {
        return [
            'payment_key' => $key,
            'refunded_minor' => $refunded,
            'refundable_minor' => $remaining,
            'restocked' => $restocked,
            'idempotent' => $idempotent,
        ];
    }
}
