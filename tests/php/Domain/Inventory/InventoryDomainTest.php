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
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class InventoryDomainTest extends TestCase
{
    public function testBranchSourceRequiresSameTenantBranch(): void
    {
        $tenant = new Tenant('Uno', 'uno-'.bin2hex(random_bytes(4)));
        $other = new Tenant('Dos', 'dos-'.bin2hex(random_bytes(4)));
        $branch = new Branch($other, 'Ajena', 'ajena');

        $this->expectException(DomainException::class);
        new InventorySource(
            $tenant,
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
        $branch = new Branch($tenant, 'Principal', 'principal');
        $source = new InventorySource(
            $tenant,
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
