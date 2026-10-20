<?php

declare(strict_types=1);

namespace App\Tests\Domain\Purchasing;

use App\Application\Purchasing\MaterialCostService;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MaterialCostServiceTest extends TestCase
{
    private static function material(Tenant $tenant, string $code = 'MAT-1'): Material
    {
        return new Material($tenant, $code, 'Material de prueba', UnitOfMeasure::from('m'));
    }

    private static function order(
        Tenant $tenant,
        Material $material,
        string $quantity,
        string $unitCost,
        string $receiptKey,
        bool $received = true,
    ): PurchaseOrder {
        $legal = new LegalEntity($tenant, 'Organización de prueba', null);
        $supplier = new Supplier($tenant, 'SUP-1', 'Proveedor sintético');
        $order = new PurchaseOrder($tenant, $legal, $supplier);
        $order->addLine($material, $quantity, $unitCost);
        if ($received) {
            $order->markReceived($receiptKey, str_repeat('A', 26));
        }
        return $order;
    }

    public function testWeightedAverageCostIsDeterministic(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $material = self::material($tenant);
        $first = self::order($tenant, $material, '1.5', '0.1000', 'cost:1');
        $second = self::order($tenant, $material, '0.5', '0.2000', 'cost:2');
        $draft = self::order($tenant, $material, '50', '999', 'cost:3', false);
        $service = new MaterialCostService();

        $expected = [
            'state' => 'KNOWN',
            'average_unit_cost' => '0.1250',
            'total_quantity' => '2.000000',
            'receipt_count' => 2,
        ];
        self::assertSame($expected, $service->calculate($tenant, $material, [$first, $second, $draft]));
        self::assertSame($expected, $service->calculate($tenant, $material, [$draft, $second, $first]));
        self::assertSame($expected, $service->calculate($tenant, $material, [$first, $first, $second]));

        $rounding = self::material($tenant, 'ROUND-1');
        $small = self::order($tenant, $rounding, '1', '1.0000', 'cost:4');
        $large = self::order($tenant, $rounding, '1', '1.0001', 'cost:5');
        self::assertSame('1.0001', $service->calculate($tenant, $rounding, [$small, $large])['average_unit_cost']);
    }

    public function testMaterialWithoutReceiptsIsUnknownNotZero(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $missing = self::material($tenant);
        $zero = self::material($tenant, 'MAT-0');
        $service = new MaterialCostService();

        self::assertSame([
            'state' => 'UNKNOWN',
            'average_unit_cost' => null,
            'total_quantity' => '0.000000',
            'receipt_count' => 0,
        ], $service->calculate($tenant, $missing, []));
        $receivedFree = self::order($tenant, $zero, '1', '0', 'cost:free');
        self::assertSame('KNOWN', $service->calculate($tenant, $zero, [$receivedFree])['state']);
        self::assertSame('0.0000', $service->calculate($tenant, $zero, [$receivedFree])['average_unit_cost']);
    }

    public function testCostIsTenantIsolated(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $foreign = new Tenant('Otro', 'otro');
        $material = self::material($tenant);
        $otherMaterial = self::material($foreign);
        $own = self::order($tenant, $material, '2', '10', 'own:1');
        $other = self::order($foreign, $otherMaterial, '100', '999', 'foreign:1');
        $service = new MaterialCostService();

        $baseline = $service->calculate($tenant, $material, [$own]);
        self::assertSame($baseline, $service->calculate($tenant, $material, [$other, $own]));
        self::assertSame('10.0000', $baseline['average_unit_cost']);

        $this->expectException(DomainException::class);
        $service->calculate($foreign, $material, [$own]);
    }

    public function testLargeValidHistoricalCostIsExactBeyond64Bit(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $material = self::material($tenant);
        $first = self::order($tenant, $material, '10000', '100000', 'large:1');
        $second = self::order($tenant, $material, '10000', '100001', 'large:2');
        $service = new MaterialCostService();

        self::assertSame([
            'state' => 'KNOWN',
            'average_unit_cost' => '100000.5000',
            'total_quantity' => '20000.000000',
            'receipt_count' => 2,
        ], $service->calculate($tenant, $material, [$first, $second]));
    }

    public function testExtremeValidHistoricalCostIsExactWithoutOverflow(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $material = self::material($tenant);
        $large = self::order($tenant, $material, '9000000000000', '999999999999999', 'huge:1');

        self::assertSame([
            'state' => 'KNOWN',
            'average_unit_cost' => '999999999999999.0000',
            'total_quantity' => '9000000000000.000000',
            'receipt_count' => 1,
        ], (new MaterialCostService())->calculate($tenant, $material, [$large]));
    }

    public function testQuantityOutsideInventoryDomainStillFailsClosed(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $material = self::material($tenant);
        $this->expectException(DomainException::class);
        self::order($tenant, $material, '9000000000001', '1', 'invalid:1');
    }
}
