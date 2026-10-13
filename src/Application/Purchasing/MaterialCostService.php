<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use DomainException;

/**
 * Calcula el costo histórico unitario desde órdenes efectivamente recibidas.
 * No consulta ni modifica stock, y no redondea usando floats.
 */
final readonly class MaterialCostService
{
    /**
     * @param iterable<PurchaseOrder> $orders Órdenes de histórico, sin necesidad de estar activas.
     * @return array{state: 'KNOWN'|'UNKNOWN', average_unit_cost: ?string, total_quantity: string, receipt_count: int}
     */
    public function calculate(Tenant $tenant, Material $material, iterable $orders): array
    {
        if ($material->tenant()->id() !== $tenant->id()) {
            throw new DomainException('El material no pertenece al tenant solicitado.');
        }

        $quantityTotal = 0;
        $weightedTotal = 0;
        $receiptCount = 0;
        $seen = [];

        foreach ($orders as $order) {
            // Nunca se consulta el contenido de un registro de otro tenant.
            if ($order->tenant()->id() !== $tenant->id() || !$order->isReceived()) {
                continue;
            }
            if (isset($seen[$order->id()])) {
                continue;
            }
            $seen[$order->id()] = true;

            foreach ($order->lines() as $line) {
                if ($line->tenant()->id() !== $tenant->id()
                    || $line->material()->tenant()->id() !== $tenant->id()
                ) {
                    throw new DomainException('El histórico contiene una línea fuera del tenant.');
                }
                if ($line->material()->id() !== $material->id()) {
                    continue;
                }

                $quantity = self::scaledInteger($line->quantity(), 6);
                $unitCost = self::scaledInteger($line->unitCost(), 4);
                if ($quantity === 0) {
                    throw new DomainException('La cantidad recibida debe ser positiva.');
                }
                if ($unitCost !== 0 && $quantity > intdiv(PHP_INT_MAX, $unitCost)) {
                    throw new DomainException('El costo histórico supera el rango permitido.');
                }
                $weighted = $quantity * $unitCost;
                if ($quantityTotal > PHP_INT_MAX - $quantity
                    || $weightedTotal > PHP_INT_MAX - $weighted
                ) {
                    throw new DomainException('El costo histórico acumulado supera el rango permitido.');
                }
                $quantityTotal += $quantity;
                $weightedTotal += $weighted;
                $receiptCount++;
            }
        }

        if ($quantityTotal === 0) {
            return [
                'state' => 'UNKNOWN',
                'average_unit_cost' => null,
                'total_quantity' => '0.000000',
                'receipt_count' => 0,
            ];
        }

        // Divide cantidades ponderadas en enteros, redondeando half-up a 4 decimales.
        $cost = intdiv($weightedTotal, $quantityTotal);
        $remainder = $weightedTotal % $quantityTotal;
        if ($remainder >= intdiv($quantityTotal, 2) + ($quantityTotal % 2)) {
            $cost++;
        }

        return [
            'state' => 'KNOWN',
            'average_unit_cost' => self::formatScaled($cost, 4),
            'total_quantity' => self::formatScaled($quantityTotal, 6),
            'receipt_count' => $receiptCount,
        ];
    }

    private static function scaledInteger(string $value, int $scale): int
    {
        $pattern = '/^(0|[1-9][0-9]*)(?:\\.[0-9]{1,'.$scale.'})?$/D';
        if (preg_match($pattern, $value) !== 1) {
            throw new DomainException('Cantidad o costo histórico decimal inválido.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $digits = ltrim($whole.str_pad($fraction, $scale, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)
        ) {
            throw new DomainException('Decimal histórico fuera del rango permitido.');
        }
        return (int) $digits;
    }

    private static function formatScaled(int $value, int $scale): string
    {
        $divisor = 10 ** $scale;
        return intdiv($value, $divisor).'.'.str_pad((string) ($value % $divisor), $scale, '0', STR_PAD_LEFT);
    }
}
