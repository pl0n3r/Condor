<?php

declare(strict_types=1);

namespace App\Tests\Domain\Production;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BillOfMaterialsTest extends TestCase
{
    public function testBomVersionNormalizesQuantitiesAndKeepsPreviousVersionAuditable(): void
    {
        $tenant = $this->tenant('fabrica');
        $variant = $this->variant($tenant, 'CAMISETA-NEGRA-M');
        $fabric = new Material(
            $tenant,
            'TELA-01',
            'Tela',
            UnitOfMeasure::from('g'),
        );
        $thread = new Material(
            $tenant,
            'HILO-01',
            'Hilo',
            UnitOfMeasure::from('m'),
        );

        $first = new BillOfMaterials(
            $tenant,
            $variant,
            1,
            [
                [
                    'material' => $fabric,
                    'quantity' => '0.5',
                    'unit' => UnitOfMeasure::from('kg'),
                ],
                [
                    'material' => $thread,
                    'quantity' => '2.250000',
                    'unit' => UnitOfMeasure::from('m'),
                ],
            ],
        );

        self::assertSame(1, $first->version());
        self::assertTrue($first->isActive());
        self::assertCount(2, $first->lines());
        self::assertSame('500', $first->lines()[0]->quantity());
        self::assertSame('g', $first->lines()[0]->unitOfMeasure()->key());
        self::assertSame('2.25', $first->lines()[1]->quantity());

        $first->retire();
        $second = new BillOfMaterials(
            $tenant,
            $variant,
            2,
            [[
                'material' => $fabric,
                'quantity' => '750',
                'unit' => UnitOfMeasure::from('g'),
            ]],
        );

        self::assertFalse($first->isActive());
        self::assertSame(1, $first->version());
        self::assertCount(2, $first->lines());
        self::assertTrue($second->isActive());
        self::assertSame(2, $second->version());
    }

    public function testBomRejectsCrossTenantInactiveDuplicateZeroAndIncompatibleMaterial(): void
    {
        $tenant = $this->tenant('tenant-a');
        $otherTenant = $this->tenant('tenant-b');
        $variant = $this->variant($tenant, 'SKU-A');
        $otherVariant = $this->variant($otherTenant, 'SKU-B');
        $material = new Material(
            $tenant,
            'MAT-A',
            'Material A',
            UnitOfMeasure::from('kg'),
        );
        $otherMaterial = new Material(
            $otherTenant,
            'MAT-B',
            'Material B',
            UnitOfMeasure::from('kg'),
        );
        $inactive = new Material(
            $tenant,
            'MAT-C',
            'Material C',
            UnitOfMeasure::from('kg'),
        );
        $inactive->deactivate();

        $cases = [
            static fn (): BillOfMaterials => new BillOfMaterials(
                $tenant,
                $otherVariant,
                1,
                [[
                    'material' => $material,
                    'quantity' => '1',
                    'unit' => UnitOfMeasure::from('kg'),
                ]],
            ),
            static fn (): BillOfMaterials => new BillOfMaterials(
                $tenant,
                $variant,
                1,
                [[
                    'material' => $otherMaterial,
                    'quantity' => '1',
                    'unit' => UnitOfMeasure::from('kg'),
                ]],
            ),
            static fn (): BillOfMaterials => new BillOfMaterials(
                $tenant,
                $variant,
                1,
                [[
                    'material' => $inactive,
                    'quantity' => '1',
                    'unit' => UnitOfMeasure::from('kg'),
                ]],
            ),
            static fn (): BillOfMaterials => new BillOfMaterials(
                $tenant,
                $variant,
                1,
                [
                    [
                        'material' => $material,
                        'quantity' => '1',
                        'unit' => UnitOfMeasure::from('kg'),
                    ],
                    [
                        'material' => $material,
                        'quantity' => '2',
                        'unit' => UnitOfMeasure::from('kg'),
                    ],
                ],
            ),
            static fn (): BillOfMaterials => new BillOfMaterials(
                $tenant,
                $variant,
                1,
                [[
                    'material' => $material,
                    'quantity' => '0',
                    'unit' => UnitOfMeasure::from('kg'),
                ]],
            ),
            static fn (): BillOfMaterials => new BillOfMaterials(
                $tenant,
                $variant,
                1,
                [[
                    'material' => $material,
                    'quantity' => '1',
                    'unit' => UnitOfMeasure::from('l'),
                ]],
            ),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('La BOM inválida debía fallar cerrado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    private function tenant(string $prefix): Tenant
    {
        return new Tenant(
            ucfirst($prefix),
            $prefix.'-'.bin2hex(random_bytes(4)),
        );
    }

    private function variant(Tenant $tenant, string $sku): ProductVariant
    {
        $product = new Product(
            $tenant,
            'Producto '.$sku,
            strtolower(str_replace('_', '-', $sku)).'-'.bin2hex(random_bytes(2)),
        );

        return new ProductVariant(
            $tenant,
            $product,
            $sku,
            'Variante '.$sku,
        );
    }
}
