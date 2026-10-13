<?php

declare(strict_types=1);

namespace App\Tests\Domain\Purchasing;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Inventory\Entity\InventorySource;
use App\Application\Purchasing\PurchaseOrderService;
use App\Application\Production\MaterialInventoryService;
use Doctrine\ORM\EntityManagerInterface;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderDomainTest extends TestCase
{
    private static function legalEntity(Tenant $tenant): LegalEntity
    {
        return new LegalEntity($tenant, 'Organización sintética', null);
    }

    private static function material(Tenant $tenant, string $code = 'TELA'): Material
    {
        return new Material($tenant, $code, 'Tela', UnitOfMeasure::from('m'));
    }

    public function testPurchaseOrderRejectsCrossTenantSupplierAndMaterial(): void
    {
        $own = new Tenant('Principal', 'principal');
        $foreign = new Tenant('Ajeno', 'ajeno');
        $supplier = new Supplier($own, 'SUP-1', 'Proveedor');
        $foreignSupplier = new Supplier($foreign, 'SUP-2', 'Proveedor ajeno');
        try {
            new PurchaseOrder($own, self::legalEntity($own), $foreignSupplier);
            self::fail('Un proveedor ajeno no puede crear una orden.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        try {
            new PurchaseOrder($own, self::legalEntity($foreign), $supplier);
            self::fail('No debe aceptarse una entidad legal de otro tenant.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        $legal = self::legalEntity($own);
        $order = new PurchaseOrder($own, $legal, $supplier);
        self::assertSame($legal->id(), $order->legalEntity()->id());
        try {
            $order->addLine(self::material($foreign), '1', '20');
            self::fail('Un material de otro tenant no puede añadirse.');
        } catch (DomainException) {
            self::assertCount(0, $order->lines());
        }
        $line = $order->addLine(self::material($own), '1.25', '20.50');
        self::assertSame($own->id(), $line->tenant()->id());
        self::assertSame('1.25', $line->quantity());
    }

    public function testPurchaseOrderStateIsMonotonicAndIdempotent(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $order = new PurchaseOrder($tenant, self::legalEntity($tenant), new Supplier($tenant, 'SUP-1', 'Proveedor'));
        $order->addLine(self::material($tenant), '2', '35');
        $sourceId = str_repeat('A', 26);
        $order->markReceived('receive:1', $sourceId);
        $order->markReceived('receive:1', $sourceId);
        self::assertTrue($order->isReceived());
        self::assertSame('receive:1', $order->receiptKey());
        foreach ([
            static fn () => $order->markReceived('receive:2', $sourceId),
            static fn () => $order->markReceived('receive:1', str_repeat('B', 26)),
            static fn () => $order->addLine(self::material($tenant, 'OTRA'), '1', '1'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Una recepción no puede reabrirse ni recibir otra clave.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
        self::assertCount(1, $order->lines());
    }

    public function testQuantityCostAndDuplicateMaterialMustBeValid(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $order = new PurchaseOrder($tenant, self::legalEntity($tenant), new Supplier($tenant, 'SUP-1', 'Proveedor'));
        $material = self::material($tenant);
        foreach ([['0', '5'], ['-1', '5'], ['1', '-0.01'], ['1', '12.34567']] as [$quantity, $price]) {
            try {
                $order->addLine($material, $quantity, $price);
                self::fail('Cantidad o costo inválido debería rechazarse.');
            } catch (DomainException) {
                self::assertCount(0, $order->lines());
            }
        }
        $order->addLine($material, '1', '0');
        try {
            $order->addLine($material, '2', '10');
            self::fail('Material duplicado.');
        } catch (DomainException) {
            self::assertCount(1, $order->lines());
        }
    }
    public function testReceiptReplayAfterDeactivationAndLegalEntityIsolation(): void
    {
        $tenant = new Tenant('Principal', 'principal');
        $legal = self::legalEntity($tenant);
        $otherLegal = self::legalEntity($tenant);
        $source = new InventorySource($tenant, $legal, 'Almacén', 'almacen', InventorySource::TYPE_LOGICAL);
        $wrongSource = new InventorySource($tenant, $otherLegal, 'Almacén ajeno', 'almacen-ajeno', InventorySource::TYPE_LOGICAL);
        $material = self::material($tenant);
        $order = new PurchaseOrder($tenant, $legal, new Supplier($tenant, 'SUP-1', 'Proveedor'));
        $order->addLine($material, '3', '100');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use ($em): mixed {
                return $callback($em);
            },
        );
        $service = new PurchaseOrderService($em, new MaterialInventoryService($em));
        try {
            $service->receive($tenant, $wrongSource, $order, 'receive:1');
            self::fail('No recibir en otra entidad legal aunque sea el mismo tenant.');
        } catch (DomainException) {
            self::assertFalse($order->isReceived());
        }

        $order->markReceived('receive:1', $source->id());
        $material->deactivate();
        $source->deactivate();
        self::assertSame($order, $service->receive($tenant, $source, $order, 'receive:1'));
        self::assertTrue($order->isReceived());

        try {
            $service->receive($tenant, $source, $order, 'receive:otra');
            self::fail('Replay con otra clave debe rechazarse.');
        } catch (DomainException) {
            self::assertSame('receive:1', $order->receiptKey());
        }

        try {
            $service->receive($tenant, $wrongSource, $order, 'receive:1');
            self::fail('Replay entre entidades legales diferentes debe rechazarse.');
        } catch (DomainException) {
            self::assertSame($source->id(), $order->receiptSourceId());
        }
    }

}
