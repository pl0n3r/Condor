<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use DomainException;

/** Pure policy for the three existing Order status columns (D-021). */
final class OrderStateMachine
{
    public const ORDER = 'order';
    public const PAYMENT = 'payment';
    public const FULFILLMENT = 'fulfillment';

    /** @var array<string, array<string, list<string>>> */
    private const NEXT = [
        self::ORDER => ['open' => ['cancelled'], 'cancelled' => []],
        self::PAYMENT => ['pending' => ['paid'], 'paid' => []],
        self::FULFILLMENT => [
            'reserved' => ['released', 'consumed'],
            'released' => [],
            'consumed' => [],
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const DEFAULT_LABELS = [
        self::ORDER => ['open' => 'Abierto', 'cancelled' => 'Cancelado'],
        self::PAYMENT => ['pending' => 'Pendiente', 'paid' => 'Pagado'],
        self::FULFILLMENT => [
            'reserved' => 'Reservado', 'released' => 'Liberado', 'consumed' => 'Consumido',
        ],
    ];

    /** @return array{tenant_id:string,order:string,payment:string,fulfillment:string,total_minor:int,paid_minor:int,history:list<array<string,string|int>>} */
    public function initial(string $tenantId, int $totalMinor): array
    {
        if ($tenantId === '' || $totalMinor < 0 || $totalMinor > 9007199254740991) {
            throw new DomainException('ORDER_STATE_INVALID_TOTAL');
        }

        return [
            'tenant_id' => $tenantId,
            'order' => 'open',
            'payment' => $totalMinor === 0 ? 'paid' : 'pending',
            'fulfillment' => 'reserved',
            'total_minor' => $totalMinor,
            'paid_minor' => 0,
            'history' => [],
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public function assertSnapshot(array $snapshot): void
    {
        foreach (self::NEXT as $dimension => $states) {
            if (!isset($snapshot[$dimension]) || !is_string($snapshot[$dimension])
                || !array_key_exists($snapshot[$dimension], $states)) {
                throw new DomainException('ORDER_STATE_INVALID_SNAPSHOT');
            }
        }
        $tenant = $snapshot['tenant_id'] ?? null;
        $total = $snapshot['total_minor'] ?? null;
        $paid = $snapshot['paid_minor'] ?? null;
        if (!is_string($tenant) || $tenant === ''
            || !is_int($total) || !is_int($paid) || $total < 0
            || $total > 9007199254740991 || $paid < 0 || $paid > $total
            || !isset($snapshot['history']) || !is_array($snapshot['history'])
            || ($snapshot['payment'] === 'paid' && $paid !== $total)
            || ($snapshot['payment'] === 'pending' && $paid === $total)) {
            throw new DomainException('ORDER_STATE_INVALID_SNAPSHOT');
        }
    }

    /** @param array<string,mixed> $snapshot */
    public function assertTransition(array $snapshot, string $dimension, string $target): void
    {
        $this->assertSnapshot($snapshot);
        if (!isset(self::NEXT[$dimension]) || !in_array(
            $target, self::NEXT[$dimension][$snapshot[$dimension]], true,
        )) {
            throw new DomainException('ORDER_STATE_TRANSITION_DENIED');
        }
        if ($dimension === self::ORDER && $snapshot['fulfillment'] === 'consumed') {
            throw new DomainException('ORDER_STATE_CONSUMED_CANNOT_CANCEL');
        }
        if ($dimension === self::FULFILLMENT && $target === 'consumed'
            && $snapshot['order'] === 'cancelled') {
            throw new DomainException('ORDER_STATE_CANCELLED_CANNOT_CONSUME');
        }
        if ($dimension === self::PAYMENT && $snapshot['paid_minor'] !== $snapshot['total_minor']) {
            throw new DomainException('ORDER_STATE_BALANCE_PENDING');
        }
    }

    /**
     * Visible names belong to the specific tenant; keys must remain canonical.
     * Stages outside core states can be displayed as intermediates but may not
     * change allowed core transitions or bypass their invariants.
     *
     * @param array<string, array<string,string>> $tenantLabels
     */
    public function visibleName(string $dimension, string $state, array $tenantLabels = []): string
    {
        if (!isset(self::DEFAULT_LABELS[$dimension][$state])) {
            throw new DomainException('ORDER_STATE_INVALID_LABEL_STATE');
        }
        $label = $tenantLabels[$dimension][$state] ?? self::DEFAULT_LABELS[$dimension][$state];
        if (!is_string($label) || $label !== trim($label)
            || $label === '' || (preg_match_all('/./us', $label) ?: 0) > 80
            || preg_match('/[<>\x00-\x1F\x7F]/u', $label) !== 0) {
            throw new DomainException('ORDER_STATE_INVALID_LABEL');
        }

        return $label;
    }
}
