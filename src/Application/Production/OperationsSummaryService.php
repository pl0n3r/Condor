<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\ProductionOrder;
use DomainException;

/** Proyección GET-only; ni responsable ni jornada se infieren del ciclo de la orden. */
final readonly class OperationsSummaryService
{
    /**
     * @param list<ProductionOrder> $orders
     * @return array<string, mixed>
     */
    public function summarize(Tenant $tenant, InventorySource $source, array $orders): array
    {
        if (count($orders) > 50) {
            throw new DomainException('El resumen admite máximo 50 órdenes.');
        }

        $rows = [];
        $target = 0;
        $completed = 0;
        $finished = 0;
        foreach ($orders as $order) {
            if ($order->tenant()->id() !== $tenant->id()
                || $order->source()->id() !== $source->id()) {
                throw new DomainException('Orden ajena al tenant o sede consultada.');
            }
            if (!in_array($order->status(), [
                ProductionOrder::STATUS_DRAFT,
                ProductionOrder::STATUS_COMPLETED,
            ], true)) {
                throw new DomainException('Estado de orden no verificable.');
            }
            $quantity = $order->targetQuantity();
            $done = $order->isCompleted();
            $units = $order->completedQuantity() ?? 0;
            if ($quantity <= 0 || ($done && ($units <= 0 || $units > $quantity))
                || $target > PHP_INT_MAX - $quantity
                || $completed > PHP_INT_MAX - $units) {
                throw new DomainException('Cantidades de producción no verificables.');
            }
            $target += $quantity;
            if ($done) {
                $finished++;
                $completed += $units;
            }

            $elapsed = null;
            if ($done && $order->completedAt() !== null) {
                $seconds = $order->completedAt()->getTimestamp()
                    - $order->createdAt()->getTimestamp();
                if ($seconds >= 0) {
                    $elapsed = intdiv($seconds, 60);
                }
            }
            $rows[] = [
                'id' => $order->id(),
                'status' => $order->status(),
                'target_quantity' => $quantity,
                'completed_quantity' => $done ? $units : null,
                'created_at' => $order->createdAt()->format(DATE_ATOM),
                'completed_at' => $order->completedAt()?->format(DATE_ATOM),
                'elapsed_lifecycle_minutes' => $elapsed,
                'responsible_alias' => null,
                'work_minutes' => null,
                'productivity_units_per_hour' => null,
            ];
        }

        return [
            'orders' => $rows,
            'sample_size' => count($rows),
            'completed_orders' => $finished,
            'target_units' => $target,
            'completed_units' => $completed,
            'completion_percent' => $target === 0 ? null
                : round($completed * 100 / $target, 2),
            'responsible_state' => 'UNKNOWN',
            'worktime_state' => 'UNKNOWN',
            'productivity_state' => 'UNKNOWN',
            'provenance' => 'production_lite_lifecycle',
        ];
    }
}
