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

        $quantityTotal = '0';
        $weightedTotal = '0';
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

                $quantity = self::scaledDigits($line->quantity(), 6);
                $unitCost = self::scaledDigits($line->unitCost(), 4);
                if ($quantity === '0') {
                    throw new DomainException('La cantidad recibida debe ser positiva.');
                }
                $quantityTotal = self::addDigits($quantityTotal, $quantity);
                $weightedTotal = self::addDigits(
                    $weightedTotal,
                    self::multiplyDigits($quantity, $unitCost),
                );
                $receiptCount++;
            }
        }

        if ($quantityTotal === '0') {
            return [
                'state' => 'UNKNOWN',
                'average_unit_cost' => null,
                'total_quantity' => '0.000000',
                'receipt_count' => 0,
            ];
        }

        // El producto tiene escala 10; la cantidad escala 6.
        // División entera decimal con redondeo half-up: costo escala 4.
        $cost = self::divideHalfUp($weightedTotal, $quantityTotal);

        return [
            'state' => 'KNOWN',
            'average_unit_cost' => self::formatScaled($cost, 4),
            'total_quantity' => self::formatScaled($quantityTotal, 6),
            'receipt_count' => $receiptCount,
        ];
    }

    private static function scaledDigits(string $value, int $scale): string
    {
        $pattern = '/^(0|[1-9][0-9]*)(?:\.[0-9]{1,'.$scale.'})?$/D';
        if (preg_match($pattern, $value) !== 1) {
            throw new DomainException('Cantidad o costo histórico decimal inválido.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return self::trimDigits($whole.str_pad($fraction, $scale, '0'));
    }

    private static function trimDigits(string $digits): string
    {
        $trimmed = ltrim($digits, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = self::trimDigits($left);
        $right = self::trimDigits($right);
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function addDigits(string $left, string $right): string
    {
        $a = strlen($left) - 1;
        $b = strlen($right) - 1;
        $carry = 0;
        $result = '';
        while ($a >= 0 || $b >= 0 || $carry > 0) {
            $sum = $carry;
            if ($a >= 0) {
                $sum += (int) $left[$a--];
            }
            if ($b >= 0) {
                $sum += (int) $right[$b--];
            }
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }
        return self::trimDigits($result);
    }

    private static function subtractDigits(string $left, string $right): string
    {
        $a = strlen($left) - 1;
        $b = strlen($right) - 1;
        $borrow = 0;
        $result = '';
        while ($a >= 0) {
            $digit = (int) $left[$a--] - $borrow - ($b >= 0 ? (int) $right[$b--] : 0);
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit + ($borrow === 1 ? 10 : 0)).$result;
        }
        return self::trimDigits($result);
    }

    private static function multiplyDigits(string $left, string $right): string
    {
        if ($left === '0' || $right === '0') {
            return '0';
        }
        $aLength = strlen($left);
        $bLength = strlen($right);
        $result = array_fill(0, $aLength + $bLength, 0);
        for ($a = $aLength - 1; $a >= 0; $a--) {
            $carry = 0;
            for ($b = $bLength - 1; $b >= 0; $b--) {
                $index = $a + $b + 1;
                $product = (int) $left[$a] * (int) $right[$b] + $result[$index] + $carry;
                $result[$index] = $product % 10;
                $carry = intdiv($product, 10);
            }
            $result[$a] += $carry;
        }
        return self::trimDigits(implode('', $result));
    }

    private static function divideHalfUp(string $numerator, string $denominator): string
    {
        if ($denominator === '0') {
            throw new DomainException('Cantidad histórica total inválida.');
        }
        $quotient = '';
        $remainder = '0';
        foreach (str_split($numerator) as $digit) {
            $remainder = self::trimDigits($remainder.$digit);
            $nextDigit = 0;
            while (self::compareDigits($remainder, $denominator) >= 0) {
                $remainder = self::subtractDigits($remainder, $denominator);
                $nextDigit++;
            }
            $quotient .= (string) $nextDigit;
        }
        $quotient = self::trimDigits($quotient);
        if (self::compareDigits(self::addDigits($remainder, $remainder), $denominator) >= 0) {
            $quotient = self::addDigits($quotient, '1');
        }
        return $quotient;
    }

    private static function formatScaled(string $value, int $scale): string
    {
        $padded = str_pad($value, $scale + 1, '0', STR_PAD_LEFT);
        return substr($padded, 0, -$scale).'.'.substr($padded, -$scale);
    }
}
