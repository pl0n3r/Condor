<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Domain\Orders\OrderStateMachine;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Pure preparation of atomic transitions and audit entries.
 *
 * Order already persists independent order/payment/fulfillment columns and
 * OrderEvent already persists actor/time/context. This prepares the complete
 * delta for a transactionally-authorized caller; it never writes or sends
 * messages and must not be mistaken for a committed Order state transition.
 */
final readonly class OrderStateTransitions
{
    public function __construct(private OrderStateMachine $machine)
    {
    }

    /**
     * @param array{tenant_id:string,order:string,payment:string,fulfillment:string,total_minor:int,paid_minor:int,history:list<array<string,string|int>>} $snapshot
     * @return array<string,mixed>
     */
    public function change(
        array $snapshot,
        string $dimension,
        string $target,
        string $tenantId,
        string $authorizedTenantId,
        string $actorId,
        DateTimeImmutable $at,
    ): array {
        $this->assertAuthority($tenantId, $authorizedTenantId, $actorId);
        $this->machine->assertSnapshot($snapshot);
        if ($snapshot['tenant_id'] !== $tenantId) {
            throw new DomainException('ORDER_STATE_TENANT_OR_ACTOR_DENIED');
        }
        $this->machine->assertTransition($snapshot, $dimension, $target);
        $next = $snapshot;
        $from = $next[$dimension];
        $next[$dimension] = $target;
        $this->append($next, $dimension, $from, $target, $actorId, $at);

        // Order::cancelAndRelease() already makes both changes atomically.
        if ($dimension === OrderStateMachine::ORDER && $target === 'cancelled'
            && $snapshot['fulfillment'] === 'reserved') {
            $next['fulfillment'] = 'released';
            $this->append($next, 'fulfillment', 'reserved', 'released', $actorId, $at);
        }

        return $next;
    }

    /**
     * @param array{tenant_id:string,order:string,payment:string,fulfillment:string,total_minor:int,paid_minor:int,history:list<array<string,string|int>>} $snapshot
     * @return array<string,mixed>
     */
    public function recordPayment(
        array $snapshot,
        int $amountMinor,
        bool $allowPartialPayments,
        string $tenantId,
        string $authorizedTenantId,
        string $actorId,
        DateTimeImmutable $at,
    ): array {
        $this->assertAuthority($tenantId, $authorizedTenantId, $actorId);
        $this->machine->assertSnapshot($snapshot);
        if ($snapshot['tenant_id'] !== $tenantId) {
            throw new DomainException('ORDER_STATE_TENANT_OR_ACTOR_DENIED');
        }
        $remaining = $snapshot['total_minor'] - $snapshot['paid_minor'];
        if ($snapshot['order'] !== 'open' || $remaining === 0
            || $amountMinor <= 0 || $amountMinor > $remaining) {
            throw new DomainException('ORDER_PAYMENT_INVALID_AMOUNT');
        }
        if (!$allowPartialPayments && $amountMinor !== $remaining) {
            throw new DomainException('ORDER_PAYMENT_PARTIAL_DISABLED');
        }
        $next = $snapshot;
        $next['paid_minor'] += $amountMinor;
        $from = $next['payment'];
        if ($next['paid_minor'] === $next['total_minor']) {
            $next['payment'] = 'paid';
        }
        $this->append($next, 'payment', $from, $next['payment'], $actorId, $at, $amountMinor);

        return $next;
    }

    /** @param array{tenant_id:string,order:string,payment:string,fulfillment:string,total_minor:int,paid_minor:int,history:list<array<string,string|int>>} $snapshot */
    public function pendingBalance(array $snapshot): int
    {
        $this->machine->assertSnapshot($snapshot);
        return $snapshot['total_minor'] - $snapshot['paid_minor'];
    }

    private function assertAuthority(string $tenantId, string $authorizedTenantId, string $actorId): void
    {
        if ($tenantId === '' || !hash_equals($tenantId, $authorizedTenantId)
            || trim($actorId) === '') {
            throw new DomainException('ORDER_STATE_TENANT_OR_ACTOR_DENIED');
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function append(
        array &$snapshot, string $dimension, string $from, string $to,
        string $actorId, DateTimeImmutable $at, int $amountMinor = 0,
    ): void {
        $snapshot['history'][] = [
            'dimension' => $dimension,
            'from' => $from,
            'to' => $to,
            'actor' => $actorId,
            'at' => $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'amount_minor' => $amountMinor,
        ];
    }
}
