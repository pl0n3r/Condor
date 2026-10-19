<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Purchasing\MaterialCostService;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\ProductionOrder;
use DomainException;

/**
 * Valora consumos de una orden terminada sin contabilizarlos como utilidad.
 * El resultado es un comprobante persistible por el llamador. Al recalcular
 * con ese comprobante no se consulta otra vez el precio ponderado corriente.
 */
final readonly class ProductionCostService
{
    public function __construct(private MaterialCostService $materialCosts)
    {
    }

    /**
     * @param iterable<\App\Domain\Purchasing\Entity\PurchaseOrder> $receivedOrders
     * @param array<string, mixed>|null $savedSnapshot Snapshot previamente registrado, no entrada pública.
     * @return array<string, mixed>
     */
    public function calculate(
        Tenant $tenant,
        ProductionOrder $order,
        iterable $receivedOrders,
        ?array $savedSnapshot = null,
    ): array {
        if (
            $order->tenant()->id() !== $tenant->id()
            || $order->billOfMaterials()->tenant()->id() !== $tenant->id()
            || $order->variant()->tenant()->id() !== $tenant->id()
            || $order->source()->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException('La orden y la BOM pertenecen a otro tenant.');
        }
        $units = $order->completedQuantity();
        if (!$order->isCompleted() || $units === null || $units <= 0) {
            throw new DomainException('El costo requiere una orden realmente terminada.');
        }
        $bom = $order->billOfMaterials();
        if ($bom->variant()->id() !== $order->variant()->id()) {
            throw new DomainException('La BOM no corresponde al producto terminado.');
        }

        $byMaterial = [];
        foreach ($bom->lines() as $line) {
            $material = $line->material();
            if (
                $line->tenant()->id() !== $tenant->id()
                || $material->tenant()->id() !== $tenant->id()
                || isset($byMaterial[$material->id()])
            ) {
                throw new DomainException('Consumos duplicados o de otro tenant.');
            }
            $byMaterial[$material->id()] = [
                'material' => $material,
                'quantity' => MaterialInventoryBalance::multiply($line->quantity(), $units),
            ];
        }
        if ($byMaterial === []) {
            throw new DomainException('La BOM debe contener consumos de materiales.');
        }
        ksort($byMaterial, SORT_STRING);

        $savedRows = null;
        if ($savedSnapshot !== null) {
            if (
                ($savedSnapshot['tenant_id'] ?? null) !== $tenant->id()
                || ($savedSnapshot['order_id'] ?? null) !== $order->id()
                || ($savedSnapshot['bom_id'] ?? null) !== $bom->id()
                || ($savedSnapshot['bom_version'] ?? null) !== $bom->version()
                || ($savedSnapshot['completed_quantity'] ?? null) !== $units
                || ($savedSnapshot['state'] ?? null) !== 'ESTIMATED_COST'
                || !isset($savedSnapshot['consumptions'])
                || !is_array($savedSnapshot['consumptions'])
                || !array_is_list($savedSnapshot['consumptions'])
            ) {
                throw new DomainException('El comprobante no corresponde a esta orden terminada.');
            }
            $savedRows = [];
            foreach ($savedSnapshot['consumptions'] as $row) {
                if (
                    !is_array($row)
                    || !is_string($row['material_id'] ?? null)
                    || isset($savedRows[$row['material_id']])
                ) {
                    throw new DomainException('Snapshot de consumo inválido o duplicado.');
                }
                $savedRows[$row['material_id']] = $row;
            }
            if (count($savedRows) !== count($byMaterial)) {
                throw new DomainException('El comprobante omite consumos de la BOM.');
            }
        }

        $history = [];
        if ($savedRows === null) {
            // Costos de una entidad legal no pueden afectar otra del mismo tenant.
            $legalEntityId = $order->source()->legalEntity()->id();
            foreach ($receivedOrders as $purchase) {
                if (
                    $purchase->tenant()->id() !== $tenant->id()
                    || $purchase->legalEntity()->id() !== $legalEntityId
                ) {
                    continue;
                }
                $history[] = $purchase;
            }
        }
        $lines = [];
        $grandTotal = 0;
        foreach ($byMaterial as $id => $entry) {
            $quantity = $entry['quantity'];
            if ($savedRows !== null) {
                $row = $savedRows[$id] ?? null;
                if (!is_array($row) || ($row['quantity'] ?? null) !== $quantity) {
                    throw new DomainException('El consumo difiere del registrado en la BOM.');
                }
                $unitCost = $row['unit_cost'] ?? null;
                if (!is_string($unitCost)) {
                    throw new DomainException('Falta el costo unitario aplicado al material.');
                }
            } else {
                $weighted = $this->materialCosts->calculate($tenant, $entry['material'], $history);
                if (
                    $weighted['state'] !== 'KNOWN'
                    || !is_string($weighted['average_unit_cost'])
                ) {
                    throw new DomainException('Costo ponderado desconocido: no suponer costo cero.');
                }
                $unitCost = $weighted['average_unit_cost'];
            }
            $lineScaled = self::lineCost($quantity, $unitCost);
            $lineCost = self::decimal($lineScaled);
            if ($savedRows !== null && ($row['line_cost'] ?? null) !== $lineCost) {
                throw new DomainException('El total registrado no coincide con el costo unitario.');
            }
            if ($grandTotal > PHP_INT_MAX - $lineScaled) {
                throw new DomainException('El costo total excede el rango permitido.');
            }
            $grandTotal += $lineScaled;
            $lines[] = [
                'material_id' => $id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ];
        }
        $unitTotal = intdiv($grandTotal, $units);
        if ($grandTotal % $units >= intdiv($units, 2) + ($units % 2)) {
            $unitTotal++;
        }
        $result = [
            'state' => 'ESTIMATED_COST',
            'order_id' => $order->id(),
            'tenant_id' => $tenant->id(),
            'bom_id' => $bom->id(),
            'bom_version' => $bom->version(),
            'completed_quantity' => $units,
            'total_cost' => self::decimal($grandTotal),
            'unit_cost' => self::decimal($unitTotal),
            'consumptions' => $lines,
        ];
        if (
            $savedSnapshot !== null
            && (
                ($savedSnapshot['total_cost'] ?? null) !== $result['total_cost']
                || ($savedSnapshot['unit_cost'] ?? null) !== $result['unit_cost']
            )
        ) {
            throw new DomainException('El total guardado difiere de los consumos.');
        }

        return $result;
    }

    private static function lineCost(string $quantity, string $price): int
    {
        $micros = self::parse($quantity, 6);
        $unitCost = self::parse($price, 4);
        if ($micros <= 0 || $micros > intdiv(PHP_INT_MAX, max(1, $unitCost))) {
            throw new DomainException('Cantidad o precio fuera del rango permitido.');
        }
        $weighted = $micros * $unitCost;

        return intdiv($weighted, 1_000_000)
            + (($weighted % 1_000_000) >= 500_000 ? 1 : 0);
    }

    private static function parse(string $value, int $scale): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,'.$scale.'}))?$/D', $value, $parts) !== 1) {
            throw new DomainException('Decimal no canónico para el cálculo de costo.');
        }
        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', $scale, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = (string) PHP_INT_MAX;
        if (
            strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
        ) {
            throw new DomainException('Decimal fuera del rango seguro.');
        }

        return (int) $digits;
    }

    private static function decimal(int $scaled): string
    {
        return intdiv($scaled, 10_000).'.'.str_pad((string) ($scaled % 10_000), 4, '0', STR_PAD_LEFT);
    }
}
