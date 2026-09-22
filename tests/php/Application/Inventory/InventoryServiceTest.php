<?php

declare(strict_types=1);

namespace App\Tests\Application\Inventory;

use App\Application\Inventory\InventoryService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventoryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InventoryService $service;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        $service = static::getContainer()->get(InventoryService::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(InventoryService::class, $service);

        $this->entityManager = $entityManager;
        $this->service = $service;
    }

    public function testAdjustmentAndTransferAreIdempotent(): void
    {
        [$tenant, $sourceA, $sourceB, $variant] = $this->fixture();

        $firstAdjustment = $this->service->adjust(
            $tenant,
            $sourceA,
            $variant,
            10,
            null,
            'adjust-'.bin2hex(random_bytes(6)),
            ['reason' => 'initial_stock'],
        );
        $replayedAdjustment = $this->service->adjust(
            $tenant,
            $sourceA,
            $variant,
            10,
            null,
            $firstAdjustment->idempotencyKey() ?? '',
            ['reason' => 'ignored_on_replay'],
        );

        self::assertSame(
            $firstAdjustment->id(),
            $replayedAdjustment->id(),
        );
        self::assertSame(
            10,
            $this->balance($tenant, $sourceA, $variant)->quantity(),
        );

        $transferKey = 'transfer-'.bin2hex(random_bytes(6));
        $firstTransfer = $this->service->transfer(
            $tenant,
            $variant,
            $sourceA,
            $sourceB,
            4,
            null,
            $transferKey,
        );
        $replayedTransfer = $this->service->transfer(
            $tenant,
            $variant,
            $sourceA,
            $sourceB,
            4,
            null,
            $transferKey,
        );

        self::assertSame($firstTransfer->id(), $replayedTransfer->id());
        self::assertSame(
            6,
            $this->balance($tenant, $sourceA, $variant)->quantity(),
        );
        self::assertSame(
            4,
            $this->balance($tenant, $sourceB, $variant)->quantity(),
        );

        self::assertSame(
            3,
            $this->entityManager
                ->getRepository(InventoryMovement::class)
                ->count(['tenant' => $tenant]),
        );
        self::assertSame(
            2,
            $this->entityManager
                ->getRepository(InventoryMovement::class)
                ->count(['transfer' => $firstTransfer]),
        );
    }

    public function testIdempotencyKeyRejectsDifferentAdjustmentPayload(): void
    {
        [$tenant, $source, , $variant] = $this->fixture();
        $key = 'adjust-conflict-'.bin2hex(random_bytes(6));

        $this->service->adjust(
            $tenant,
            $source,
            $variant,
            5,
            null,
            $key,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'La clave de idempotencia ya fue usada por otro ajuste.',
        );

        $this->service->adjust(
            $tenant,
            $source,
            $variant,
            6,
            null,
            $key,
        );
    }

    public function testFailedTransferRollsBackWithoutPartialMovement(): void
    {
        [$tenant, $sourceA, $sourceB, $variant] = $this->fixture();
        $this->service->adjust(
            $tenant,
            $sourceA,
            $variant,
            3,
            null,
            'seed-'.bin2hex(random_bytes(6)),
        );

        $connection = $this->entityManager->getConnection();
        $tenantId = $tenant->id();
        $sourceAId = $sourceA->id();
        $sourceBId = $sourceB->id();
        $variantId = $variant->id();
        $transferKey = 'too-large-'.bin2hex(random_bytes(6));

        try {
            $this->service->transfer(
                $tenant,
                $variant,
                $sourceA,
                $sourceB,
                4,
                null,
                $transferKey,
            );
            self::fail('La transferencia sin stock debía fallar.');
        } catch (DomainException $exception) {
            self::assertStringContainsString(
                'Stock insuficiente',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            3,
            (int) $connection->fetchOne(
                'SELECT quantity FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
                [$tenantId, $sourceAId, $variantId],
            ),
        );
        self::assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
                [$tenantId, $sourceBId, $variantId],
            ),
        );
        self::assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM condor_inventory_transfer '
                .'WHERE tenant_id = ? AND idempotency_key = ?',
                [$tenantId, $transferKey],
            ),
        );
        self::assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM condor_inventory_movement '
                .'WHERE tenant_id = ? AND transfer_id IS NOT NULL',
                [$tenantId],
            ),
        );
    }

    /**
     * @return array{
     *   Tenant,
     *   InventorySource,
     *   InventorySource,
     *   ProductVariant
     * }
     */
    private function fixture(): array
    {
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $branchA = new Branch(
            $tenant,
            'Principal',
            'principal-'.$suffix,
            null,
            true,
        );
        $branchB = new Branch(
            $tenant,
            'Secundaria',
            'secundaria-'.$suffix,
        );
        $sourceA = new InventorySource(
            $tenant,
            'Principal',
            'principal-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branchA,
        );
        $sourceB = new InventorySource(
            $tenant,
            'Secundaria',
            'secundaria-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branchB,
        );
        $product = new Product(
            $tenant,
            'Producto '.$suffix,
            'producto-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-'.$suffix,
            'Variante '.$suffix,
        );

        foreach (
            [
                $tenant,
                $branchA,
                $branchB,
                $sourceA,
                $sourceB,
                $product,
                $variant,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$tenant, $sourceA, $sourceB, $variant];
    }

    private function balance(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): InventoryBalance {
        $balance = $this->entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);
        self::assertInstanceOf(InventoryBalance::class, $balance);

        return $balance;
    }
}
