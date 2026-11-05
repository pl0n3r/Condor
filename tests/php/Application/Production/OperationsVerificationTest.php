<?php

declare(strict_types=1);

namespace App\Tests\Application\Production;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Synthetic, persistence-free assertions for the existing Production Lite slice. */
final class OperationsVerificationTest extends TestCase
{
    public function testWorkOrderCannotReferenceAnotherTenantsSource(): void
    {
        [$tenant, , $variant, $bom] = $this->fixture();
        [, $foreignSource] = $this->fixture();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mismo tenant');
        new ProductionOrder($tenant, $bom, $variant, $foreignSource, 1);
    }

    public function testCompletionTracksStateTimeAndSameKeyIdempotency(): void
    {
        [$tenant, $source, $variant, $bom] = $this->fixture();
        $order = new ProductionOrder($tenant, $bom, $variant, $source, 3);

        self::assertSame(ProductionOrder::STATUS_DRAFT, $order->status());
        self::assertFalse($order->isCompleted());
        self::assertSame(3, $order->targetQuantity());
        self::assertNull($order->completedQuantity());
        self::assertNull($order->completedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $order->createdAt());

        $order->complete(3, 'gest-m11-s04-s06');
        $completedAt = $order->completedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $completedAt);
        self::assertGreaterThanOrEqual(
            $order->createdAt()->getTimestamp(),
            $completedAt->getTimestamp(),
        );
        self::assertSame(ProductionOrder::STATUS_COMPLETED, $order->status());
        self::assertTrue($order->isCompleted());
        self::assertSame(3, $order->completedQuantity());
        self::assertSame('gest-m11-s04-s06', $order->completionIdempotencyKey());

        $order->complete(3, 'gest-m11-s04-s06');
        self::assertSame($completedAt, $order->completedAt());
    }

    public function testPartialOrChangedCompletionCannotAlterWorkOrder(): void
    {
        [$tenant, $source, $variant, $bom] = $this->fixture();
        $order = new ProductionOrder($tenant, $bom, $variant, $source, 2);
        try {
            $order->complete(1, 'partial');
            self::fail('Partial completion must fail closed.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('parciales', $exception->getMessage());
        }
        self::assertSame(ProductionOrder::STATUS_DRAFT, $order->status());
        self::assertNull($order->completedAt());

        $order->complete(2, 'first-completion');
        $timestamp = $order->completedAt();
        try {
            $order->complete(2, 'changed-completion');
            self::fail('Replaying a different completion must fail closed.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('parámetros distintos', $exception->getMessage());
        }
        self::assertSame('first-completion', $order->completionIdempotencyKey());
        self::assertSame($timestamp, $order->completedAt());
    }

    /** @return array{Tenant, InventorySource, ProductVariant, BillOfMaterials} */
    private function fixture(): array
    {
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Operaciones '.$suffix, 'ops-'.$suffix);
        $legal = new LegalEntity($tenant, 'Ops '.$suffix.' SAS', null, true);
        $branch = new Branch($tenant, 'Sede', 'sede-'.$suffix, $legal, true);
        $source = new InventorySource(
            $tenant,
            $legal,
            'Almacén',
            'almacen-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        $product = new Product($tenant, 'Producto '.$suffix, 'producto-'.$suffix);
        $variant = new ProductVariant($tenant, $product, 'SKU-'.$suffix, 'Única');
        $material = new Material(
            $tenant,
            'MAT-'.$suffix,
            'Materia '.$suffix,
            UnitOfMeasure::from('kg'),
        );
        $bom = new BillOfMaterials($tenant, $variant, 1, [[
            'material' => $material,
            'quantity' => '1',
            'unit' => UnitOfMeasure::from('kg'),
        ]]);

        return [$tenant, $source, $variant, $bom];
    }
}
