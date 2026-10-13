<?php

declare(strict_types=1);

namespace App\Tests\Domain\Purchasing;

use App\Application\Purchasing\SupplierService;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Purchasing\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\UnitOfWork;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SupplierDomainTest extends TestCase
{
    private static function tenant(string $name): Tenant
    {
        return new Tenant($name, strtolower($name).'-'.bin2hex(random_bytes(4)));
    }

    public function testSupplierNormalizesCodeAndKeepsTenantScope(): void
    {
        $tenant = self::tenant('Propio');
        $supplier = new Supplier($tenant, '  acero-01  ', ' Materiales sintéticos ', 30);
        self::assertSame($tenant->id(), $supplier->tenant()->id());
        self::assertSame('ACERO-01', $supplier->code());
        self::assertSame('Materiales sintéticos', $supplier->name());
        self::assertSame(30, $supplier->paymentTermsDays());
        self::assertTrue($supplier->isActive());

        $supplier->update('  NUEVO_02  ', 'Suministros', 365);
        self::assertSame('NUEVO_02', $supplier->code());
        self::assertSame(365, $supplier->paymentTermsDays());
        $supplier->deactivate();
        $supplier->deactivate();
        self::assertFalse($supplier->isActive());
    }

    public function testSupplierRejectsInvalidCodeNameAndPaymentTerms(): void
    {
        $tenant = self::tenant('Propio');
        foreach ([
            ['', 'Correcto', 0],
            ['NO SPACE', 'Correcto', 0],
            ['OK', ' ', 0],
            ['OK', 'Correcto', -1],
            ['OK', 'Correcto', 366],
        ] as [$code, $name, $days]) {
            try {
                new Supplier($tenant, $code, $name, $days);
                self::fail('Un proveedor inválido debe fallar cerrado.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSupplierRejectsInvisibleAndMalformedUnicodeNames(): void
    {
        $tenant = self::tenant('Propio');
        foreach ([
            "\u{00A0}", // Espacio no separable.
            "\u{200B}", // Carácter sin anchura.
            "\u{00A0}\u{200B}\u{00A0}",
            "\x1B", // ESC, no visible pero no retirado por trim().
            "\x7F", // DEL, no visible.
            "\u{FE0F}", // Selector de variación sin carácter base.
            "\u{034F}", // Combining Grapheme Joiner sin carácter base.
            "\xC3\x28", // UTF-8 incompleto.
            str_repeat('ñ', 161),
        ] as $name) {
            try {
                new Supplier($tenant, 'UNICODE', $name);
                self::fail('Un nombre inválido debe rechazarse.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSupplierTrimsUnicodeWithoutMbstringAndPreservesVisibleNames(): void
    {
        $tenant = self::tenant('Propio');
        $supplier = new Supplier($tenant, 'UNICODE', "\u{00A0}Muñecas Ñandú\u{00A0}");
        self::assertSame('Muñecas Ñandú', $supplier->name());

        $supplier->update('UNICODE', "\u{200B}Árbol,\u{00A0}S.A.\u{200B}", 15);
        self::assertSame("Árbol,\u{00A0}S.A.", $supplier->name());
        self::assertSame(15, $supplier->paymentTermsDays());

        // El filtro de invisibilidad no debe borrar marcas de acento combinadas válidas.
        $supplier->update('UNICODE', "Cafe\u{0301}", 15);
        self::assertSame("Cafe\u{0301}", $supplier->name());

        $maxLength = str_repeat('ñ', 160);
        $supplier->update('UNICODE', $maxLength, 15);
        self::assertSame($maxLength, $supplier->name());
    }

    public function testServiceRejectsDuplicateCodeWithinTenantAndAllowsOtherTenant(): void
    {
        $own = self::tenant('Propio');
        $foreign = self::tenant('Ajeno');
        $existing = new Supplier($own, 'SUP-01', 'Proveedor existente');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($own, $existing): ?Supplier {
                if (($criteria['tenant'] ?? null) === $own && ($criteria['code'] ?? null) === 'SUP-01') {
                    return $existing;
                }

                return null;
            },
        );
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(Supplier::class)->willReturn($repository);
        $manager->method('getUnitOfWork')->willReturn($this->createMock(UnitOfWork::class));
        $manager->expects(self::once())->method('persist')->with(self::isInstanceOf(Supplier::class));
        $service = new SupplierService($manager);

        try {
            $service->create($own, ' sup-01 ', 'Duplicado');
            self::fail('No puede existir un código duplicado dentro del tenant.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        $created = $service->create($foreign, ' sup-01 ', 'Válido en otro tenant', 15);
        self::assertSame($foreign->id(), $created->tenant()->id());
        self::assertSame('SUP-01', $created->code());
    }

    public function testServiceRejectsPendingDuplicateBeforeFlushAndAllowsOtherTenant(): void
    {
        $own = self::tenant('Propio');
        $foreign = self::tenant('Ajeno');
        $scheduled = [];

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturnCallback(
            static function () use (&$scheduled): array {
                return $scheduled;
            },
        );
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(Supplier::class)->willReturn($repository);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$scheduled): void {
                $scheduled[] = $entity;
            },
        );
        $service = new SupplierService($manager);

        $first = $service->create($own, 'SUP-01', 'Primero');
        self::assertCount(1, $scheduled);
        try {
            $service->create($own, ' sup-01 ', 'Duplicado antes del flush');
            self::fail('Dos persist sin flush deben rechazar el código repetido.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Ya existe', $error->getMessage());
        }
        self::assertCount(1, $scheduled);
        self::assertSame($first, $scheduled[0]);

        $other = $service->create($foreign, 'sup-01', 'Permitido en otro tenant');
        self::assertSame('SUP-01', $other->code());
        self::assertSame($foreign->id(), $other->tenant()->id());
        self::assertCount(2, $scheduled);
    }

    public function testServiceRejectsDuplicateManagedUpdateBeforeFlush(): void
    {
        $own = self::tenant('Propio');
        $foreign = self::tenant('Ajeno');
        $first = new Supplier($own, 'SUP-01', 'Primero');
        $second = new Supplier($own, 'SUP-02', 'Segundo');
        $otherTenant = new Supplier($foreign, 'SUP-02', 'Ajeno');

        // Se simula un EntityManager que ya administra las tres entidades.
        // La base de datos conserva los códigos anteriores hasta flush().
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getIdentityMap')->willReturn([
            Supplier::class => [
                $first->id() => $first,
                $second->id() => $second,
                $otherTenant->id() => $otherTenant,
            ],
        ]);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('getRepository')->with(Supplier::class)->willReturn($repository);
        $service = new SupplierService($manager);

        $service->update($own, $first, 'PENDIENTE', 'Actualizado', 10);
        self::assertSame('PENDIENTE', $first->code());

        try {
            $service->update($own, $second, ' pendiente ', 'Duplicado', 20);
            self::fail('Debe rechazar códigos duplicados entre updates antes de flush.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Ya existe', $error->getMessage());
        }
        self::assertSame('SUP-02', $second->code());
        self::assertSame('Segundo', $second->name());

        $service->update($foreign, $otherTenant, 'PENDIENTE', 'Ajeno permitido', 0);
        self::assertSame('PENDIENTE', $otherTenant->code());
    }

    public function testServiceRejectsCrossTenantMutationAndAllowsOwnedDeactivate(): void
    {
        $own = self::tenant('Propio');
        $foreign = self::tenant('Ajeno');
        $supplier = new Supplier($own, 'SUP-01', 'Proveedor');
        $service = new SupplierService($this->createMock(EntityManagerInterface::class));
        try {
            $service->update($foreign, $supplier, 'SUP-02', 'Otro', 40);
            self::fail('No se permite modificar un proveedor ajeno.');
        } catch (DomainException) {
            self::assertSame('SUP-01', $supplier->code());
        }
        try {
            $service->deactivate($foreign, $supplier);
            self::fail('No se permite desactivar un proveedor ajeno.');
        } catch (DomainException) {
            self::assertTrue($supplier->isActive());
        }
        self::assertFalse($service->deactivate($own, $supplier)->isActive());
    }
}
