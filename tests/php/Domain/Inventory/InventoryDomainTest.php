<?php

declare(strict_types=1);

namespace App\Tests\Domain\Inventory;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class InventoryDomainTest extends TestCase
{
    public function testBranchSourceRequiresSameTenantBranch(): void
    {
        $tenant = new Tenant('Uno', 'uno-'.bin2hex(random_bytes(4)));
        $other = new Tenant('Dos', 'dos-'.bin2hex(random_bytes(4)));
        $legalEntity = new LegalEntity($tenant, 'Uno SAS', null, true);
        $otherLegalEntity = new LegalEntity($other, 'Dos SAS', null, true);
        $branch = new Branch($other, 'Ajena', 'ajena', $otherLegalEntity);

        $this->expectException(DomainException::class);
        new InventorySource(
            $tenant,
            $legalEntity,
            'Principal',
            'principal',
            InventorySource::TYPE_BRANCH,
            $branch,
        );
    }

    public function testBalanceRejectsNegativeStockUntilBackorderIsEnabled(): void
    {
        [$tenant, $source, $variant] = $this->fixture();
        $balance = new InventoryBalance($tenant, $source, $variant);

        try {
            $balance->apply(-1);
            self::fail('El stock negativo debía rechazarse.');
        } catch (DomainException) {
            self::assertSame(0, $balance->quantity());
        }

        $variant->product()->setBackorderAllowed(true);
        $balance->apply(-1);

        self::assertSame(-1, $balance->quantity());
        self::assertSame(1, $balance->version());
    }

    public function testInboundReplenishmentCanReduceNegativeBalanceAfterBackorderIsDisabled(): void
    {
        [$tenant, $source, $variant] = $this->fixture();
        $variant->product()->setBackorderAllowed(true);
        $balance = new InventoryBalance($tenant, $source, $variant);

        $balance->apply(-5);
        $variant->product()->setBackorderAllowed(false);
        $balance->apply(3);

        self::assertSame(-2, $balance->quantity());
        self::assertSame(2, $balance->version());

        try {
            $balance->apply(-1);
            self::fail('Una salida adicional debía respetar el backorder desactivado.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'Stock insuficiente',
                $exception->getMessage(),
            );
            self::assertSame(-2, $balance->quantity());
            self::assertSame(2, $balance->version());
        }
    }

    public function testBalanceRejectsInitialQuantityOutsideDatabaseRange(): void
    {
        [$tenant, $source, $variant] = $this->fixture();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'El saldo inicial excede el rango permitido por inventario.',
        );

        new InventoryBalance($tenant, $source, $variant, 2147483648);
    }

    public function testTransferRejectsSameSourceAndZeroQuantity(): void
    {
        [$tenant, $source, $variant] = $this->fixture();

        try {
            new InventoryTransfer(
                $tenant,
                $variant,
                $source,
                $source,
                1,
                null,
                'transfer-1',
            );
            self::fail('Una transferencia a la misma fuente debía rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $destination = new InventorySource(
            $tenant,
            $source->legalEntity(),
            'Bodega',
            'bodega',
            InventorySource::TYPE_LOGICAL,
        );

        $this->expectException(DomainException::class);
        new InventoryTransfer(
            $tenant,
            $variant,
            $source,
            $destination,
            0,
            null,
            'transfer-2',
        );
    }


    public function testTransferRejectsQuantityOutsideDatabaseRange(): void
    {
        [$tenant, $source, $variant] = $this->fixture();
        $destination = new InventorySource(
            $tenant,
            $source->legalEntity(),
            'Bodega límite',
            'bodega-limite',
            InventorySource::TYPE_LOGICAL,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'La cantidad de la transferencia excede el rango permitido.',
        );

        new InventoryTransfer(
            $tenant,
            $variant,
            $source,
            $destination,
            2147483648,
            null,
            'transfer-overflow',
        );
    }

    public function testMovementRejectsValuesOutsideDatabaseRange(): void
    {
        [$tenant, $source, $variant] = $this->fixture();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'El movimiento excede el rango permitido por inventario.',
        );

        new InventoryMovement(
            $tenant,
            $source,
            $variant,
            InventoryMovement::TYPE_ADJUSTMENT_IN,
            2147483648,
            2147483648,
            null,
        );
    }

    public function testTransferRejectsDifferentLegalEntities(): void
    {
        [$tenant, $source, $variant] = $this->fixture();
        $otherLegalEntity = new LegalEntity(
            $tenant,
            'Otra razón social',
            null,
        );
        $otherBranch = new Branch(
            $tenant,
            'Otra sede',
            'otra-sede-'.bin2hex(random_bytes(3)),
            $otherLegalEntity,
        );
        $otherSource = new InventorySource(
            $tenant,
            $otherLegalEntity,
            'Otra fuente',
            'otra-fuente-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_BRANCH,
            $otherBranch,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Una transferencia interna no puede cruzar entidades legales.',
        );

        new InventoryTransfer(
            $tenant,
            $variant,
            $source,
            $otherSource,
            1,
            null,
            'cross-entity-transfer',
        );
    }

    public function testTransferMovementMustReferenceTransfer(): void
    {
        [$tenant, $source, $variant] = $this->fixture();

        $this->expectException(DomainException::class);
        new InventoryMovement(
            $tenant,
            $source,
            $variant,
            InventoryMovement::TYPE_TRANSFER_OUT,
            -1,
            0,
            null,
        );
    }

    /** @return array{Tenant, InventorySource, ProductVariant} */
    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = new Tenant('Empresa', 'empresa-'.$suffix);
        $legalEntity = new LegalEntity($tenant, 'Empresa SAS', null, true);
        $branch = new Branch($tenant, 'Principal', 'principal', $legalEntity);
        $source = new InventorySource(
            $tenant,
            $legalEntity,
            'Principal',
            'principal',
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        $product = new Product($tenant, 'Producto', 'producto-'.$suffix);
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-'.$suffix,
            'Variante',
        );

        return [$tenant, $source, $variant];
    }
}
