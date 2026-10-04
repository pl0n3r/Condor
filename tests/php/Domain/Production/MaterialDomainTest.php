<?php

declare(strict_types=1);

namespace App\Tests\Domain\Production;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MaterialDomainTest extends TestCase
{
    public function testMaterialNormalizesCodeAndKeepsTenantScope(): void
    {
        $tenant = new Tenant(
            'Fábrica',
            'fabrica-'.bin2hex(random_bytes(4)),
        );
        $material = new Material(
            $tenant,
            ' tela-cruda_01 ',
            'Tela cruda',
            UnitOfMeasure::from('m'),
        );

        self::assertSame($tenant->id(), $material->organizationId());
        self::assertSame($tenant->id(), $material->tenant()->id());
        self::assertSame('TELA-CRUDA_01', $material->code());
        self::assertSame('m', $material->unitOfMeasure()->key());
        self::assertTrue($material->isActive());

        $material->deactivate();
        self::assertFalse($material->isActive());
    }

    public function testUnitConversionIsExactAcrossSameMagnitude(): void
    {
        self::assertSame(
            '1000',
            UnitOfMeasure::from('kg')->convert(
                '1',
                UnitOfMeasure::from('g'),
            ),
        );
        self::assertSame(
            '1.25',
            UnitOfMeasure::from('l')->convert(
                '1250',
                UnitOfMeasure::from('ml'),
            ),
        );
        self::assertSame(
            '0.001',
            UnitOfMeasure::from('g')->convert(
                '1',
                UnitOfMeasure::from('kg'),
            ),
        );
        self::assertSame(
            '12.345678',
            UnitOfMeasure::from('m')->normalizeQuantity('12.345678'),
        );
    }

    public function testUnitRejectsNegativeIncompatibleAndUnknownValues(): void
    {
        foreach (
            [
                static fn (): string => UnitOfMeasure::from('kg')->normalizeQuantity('-1'),
                static fn (): string => UnitOfMeasure::from('kg')->convert(
                    '1',
                    UnitOfMeasure::from('l'),
                ),
                static fn (): UnitOfMeasure => UnitOfMeasure::from('lb'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('La unidad/cantidad inválida debía fallar cerrado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMaterialRejectsInvalidCodeAndName(): void
    {
        $tenant = new Tenant(
            'Fábrica',
            'fabrica-'.bin2hex(random_bytes(4)),
        );

        foreach (
            [
                ['', 'Tela'],
                ['CODIGO CON ESPACIOS', 'Tela'],
                ['OK', ''],
            ] as [$code, $name]
        ) {
            try {
                new Material(
                    $tenant,
                    $code,
                    $name,
                    UnitOfMeasure::from('unit'),
                );
                self::fail('El material inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
