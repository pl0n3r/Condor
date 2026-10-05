<?php

declare(strict_types=1);

namespace App\Tests\Domain\Production;

use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Inventory\InventoryService;
use App\Application\Production\MaterialInventoryService;
use App\Application\Production\ProductionOrderService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\MaterialInventoryMovement;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductionOrderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MaterialInventoryService $materials;
    private ProductionOrderService $orders;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->materials = new MaterialInventoryService($entityManager);
        $this->orders = new ProductionOrderService(
            $entityManager,
            $this->materials,
            new InventoryService($entityManager),
        );
    }

    public function testCompletionConsumesBomMaterialsAndAddsFinishedVariantExactlyOnce(): void
    {
        [$tenant, $source, $variant, $material, $bom] = $this->fixture();
        $this->materials->adjust(
            $tenant,
            $source,
            $material,
            '5000',
            null,
            'seed-'.bin2hex(random_bytes(5)),
        );
        $order = $this->orders->create(
            $tenant,
            $this->entitlements($tenant, true),
            $bom,
            $variant,
            $source,
            2,
        );
        $key = 'complete-'.bin2hex(random_bytes(5));

        $first = $this->orders->complete(
            $tenant,
            $this->entitlements($tenant, true),
            $order,
            2,
            $key,
        );
        $replayed = $this->orders->complete(
            $tenant,
            $this->entitlements($tenant, true),
            $order,
            2,
            $key,
        );

        self::assertSame($first->id(), $replayed->id());
        self::assertSame(ProductionOrder::STATUS_COMPLETED, $order->status());
        self::assertSame(2, $order->completedQuantity());
        self::assertSame(
            '2000',
            $this->materialBalance($tenant, $source, $material)->quantity(),
        );
        self::assertSame(
            2,
            $this->productBalance($tenant, $source, $variant)->quantity(),
        );
        self::assertSame(
            1,
            $this->entityManager
                ->getRepository(MaterialInventoryMovement::class)
                ->count([
                    'tenant' => $tenant,
                    'type' => MaterialInventoryMovement::TYPE_PRODUCTION_CONSUMPTION,
                ]),
        );
        self::assertSame(
            1,
            $this->entityManager
                ->getRepository(InventoryMovement::class)
                ->count([
                    'tenant' => $tenant,
                    'idempotencyKey' => $key.':finished',
                ]),
        );
    }

    public function testMaterialLedgerRejectsNegativeCrossTenantInactiveOrReusedIdempotencyWithDifferentPayload(): void
    {
        [$tenant, $source, , $material] = $this->fixture();
        $seedKey = 'material-key-'.bin2hex(random_bytes(5));
        $first = $this->materials->adjust(
            $tenant,
            $source,
            $material,
            '5',
            null,
            $seedKey,
            ['reason' => 'seed'],
        );
        $replayed = $this->materials->adjust(
            $tenant,
            $source,
            $material,
            '5',
            null,
            $seedKey,
            ['reason' => 'seed'],
        );
        self::assertSame($first->id(), $replayed->id());

        foreach (
            [
                fn () => $this->materials->adjust(
                    $tenant,
                    $source,
                    $material,
                    '-6',
                    null,
                    'negative-'.bin2hex(random_bytes(4)),
                ),
                fn () => $this->materials->adjust(
                    $tenant,
                    $source,
                    $material,
                    '6',
                    null,
                    $seedKey,
                    ['reason' => 'seed'],
                ),
            ] as $case
        ) {
            try {
                $case();
                self::fail('El ledger inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $otherTenant = new Tenant(
            'Otro tenant',
            'otro-'.bin2hex(random_bytes(5)),
        );
        try {
            $this->materials->adjust(
                $otherTenant,
                $source,
                $material,
                '1',
            );
            self::fail('El cruce de tenant debía fallar cerrado.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $source->deactivate();
        $this->entityManager->flush();
        try {
            $this->materials->adjust(
                $tenant,
                $source,
                $material,
                '1',
                null,
                'inactive-'.bin2hex(random_bytes(4)),
            );
            self::fail('La fuente inactiva debía fallar cerrado.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        self::assertSame(
            '5',
            $this->materialBalance($tenant, $source, $material)->quantity(),
        );
    }

    public function testCompletionIsAtomicFailClosedAndRequiresProductionLite(): void
    {
        [$tenant, $source, $variant, $material, $bom] = $this->fixture();
        $this->materials->adjust(
            $tenant,
            $source,
            $material,
            '1000',
            null,
            'atomic-seed-'.bin2hex(random_bytes(5)),
        );
        $order = $this->orders->create(
            $tenant,
            $this->entitlements($tenant, true),
            $bom,
            $variant,
            $source,
            2,
        );

        try {
            $this->orders->complete(
                $tenant,
                $this->entitlements($tenant, false),
                $order,
                2,
                'no-entitlement-'.bin2hex(random_bytes(4)),
            );
            self::fail('Completion sin entitlement debía fallar.');
        } catch (DomainException) {
            self::assertSame(ProductionOrder::STATUS_DRAFT, $order->status());
        }

        try {
            $this->orders->complete(
                $tenant,
                $this->entitlements($tenant, true),
                $order,
                2,
                'insufficient-'.bin2hex(random_bytes(4)),
            );
            self::fail('Completion sin material suficiente debía fallar.');
        } catch (DomainException) {
            self::assertSame(ProductionOrder::STATUS_DRAFT, $order->status());
        }

        self::assertSame(
            '1000',
            $this->materialBalance($tenant, $source, $material)->quantity(),
        );
        self::assertSame(
            0,
            $this->entityManager
                ->getRepository(MaterialInventoryMovement::class)
                ->count([
                    'tenant' => $tenant,
                    'type' => MaterialInventoryMovement::TYPE_PRODUCTION_CONSUMPTION,
                ]),
        );
        self::assertSame(
            0,
            $this->entityManager
                ->getRepository(InventoryBalance::class)
                ->count([
                    'tenant' => $tenant,
                    'source' => $source,
                    'variant' => $variant,
                ]),
        );
    }

    /**
     * @return array{
     *   Tenant,
     *   InventorySource,
     *   ProductVariant,
     *   Material,
     *   BillOfMaterials
     * }
     */
    private function fixture(): array
    {
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Fábrica '.$suffix, 'fabrica-'.$suffix);
        $legalEntity = new LegalEntity(
            $tenant,
            'Fábrica '.$suffix.' SAS',
            null,
            true,
        );
        $branch = new Branch(
            $tenant,
            'Planta',
            'planta-'.$suffix,
            $legalEntity,
            true,
        );
        $source = new InventorySource(
            $tenant,
            $legalEntity,
            'Planta',
            'planta-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        $product = new Product(
            $tenant,
            'Producto terminado '.$suffix,
            'producto-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'PT-'.$suffix,
            'Terminado '.$suffix,
        );
        $material = new Material(
            $tenant,
            'TELA-'.$suffix,
            'Tela '.$suffix,
            UnitOfMeasure::from('g'),
        );
        $bom = new BillOfMaterials(
            $tenant,
            $variant,
            1,
            [[
                'material' => $material,
                'quantity' => '1.5',
                'unit' => UnitOfMeasure::from('kg'),
            ]],
        );

        foreach (
            [
                $tenant,
                $legalEntity,
                $branch,
                $source,
                $product,
                $variant,
                $material,
                $bom,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$tenant, $source, $variant, $material, $bom];
    }

    private function entitlements(
        Tenant $tenant,
        bool $enabled,
    ): EntitlementSnapshot {
        return new EntitlementSnapshot(
            $tenant->id(),
            'test-plan',
            1,
            'general',
            [],
            ['production-lite' => $enabled],
            [],
            [],
            new DateTimeImmutable(),
        );
    }

    private function materialBalance(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
    ): MaterialInventoryBalance {
        $balance = $this->entityManager
            ->getRepository(MaterialInventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'material' => $material,
            ]);
        self::assertInstanceOf(MaterialInventoryBalance::class, $balance);

        return $balance;
    }

    private function productBalance(
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
