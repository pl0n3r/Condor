<?php
declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Catalog\ProductMediaService;
use App\Application\HumanResources\EmployeeService;
use App\Application\HumanResources\EmployeeStore;
use App\Application\Identifiers\ScanResolver;
use App\Application\Notification\StockAlertService;
use App\Application\Production\ProductionCostService;
use App\Application\Purchasing\MaterialCostService;
use App\Application\Purchasing\SupplierService;
use App\Domain\Attendance\AttendanceAdapter;
use App\Domain\Attendance\AttendanceEvent;
use App\Domain\Attendance\AttendanceEventStore;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\HumanResources\Employee;
use App\Domain\Identifiers\ScanIdentifier;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Notification\Entity\NotificationDelivery;
use App\Domain\Notification\NotificationCatalog;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * AC-01 #721. Ejercita servicios reales sobre dos tenants sintéticos.
 * No usa HTTP inexistente, base productiva, secretos, exports ni credenciales.
 * Un recurso cruzado se deniega o es indistinguible de uno inexistente;
 * el resultado nunca revela IDs ni atributos del recurso ajeno.
 */
final class NewModulesTenantIsolationTest extends TestCase
{
    private static function tenants(): array
    {
        return [
            new Tenant('Alfa sintética', 'tenant-alpha-test'),
            new Tenant('Beta sintética', 'tenant-beta-test'),
        ];
    }

    private static function denied(callable $action, string $hiddenId): void
    {
        try {
            $action();
            self::fail('Una operación con recurso de otro tenant debe rechazarse.');
        } catch (DomainException $error) {
            self::assertStringNotContainsString($hiddenId, $error->getMessage());
            self::assertLessThan(256, strlen($error->getMessage()));
        }
    }

    public function testPurchasingRejectsForeignSupplierAndPurchaseAssociations(): void
    {
        [$alpha, $beta] = self::tenants();
        $supplier = new Supplier($alpha, 'PROV-A', 'Proveedor secreto A');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new SupplierService($entityManager);
        self::denied(fn () => $service->deactivate($beta, $supplier), $supplier->id());
        self::assertTrue($supplier->isActive(), 'Denegación sin efectos de escritura.');

        $legalBeta = new LegalEntity($beta, 'Entidad Beta', null);
        self::denied(
            fn () => new PurchaseOrder($beta, $legalBeta, $supplier),
            $supplier->id(),
        );
        $legalAlpha = new LegalEntity($alpha, 'Entidad Alfa', null);
        $order = new PurchaseOrder($alpha, $legalAlpha, $supplier);
        $foreignMaterial = new Material(
            $beta, 'MAT-B', 'Material privado beta', UnitOfMeasure::from('m'),
        );
        self::denied(
            fn () => $order->addLine($foreignMaterial, '1', '7'),
            $foreignMaterial->id(),
        );
        self::assertCount(0, $order->lines(), 'La orden no asocia materiales externos.');
    }

    public function testEmployeeReadMutationAndForeignMembershipAreDenied(): void
    {
        [$alpha, $beta] = self::tenants();
        $owner = new User('owner@example.invalid', 'Responsable sintético');
        $foreign = new User('foreign@example.invalid', 'Solo Beta');
        $store = new class implements EmployeeStore {
            public array $owners = [];
            public array $employees = [];
            public int $writes = 0;

            public function membership(Tenant $tenant, User $user): ?Membership
            {
                return $this->owners[$tenant->id()][$user->id()] ?? null;
            }

            public function findById(Tenant $tenant, string $id): ?Employee
            {
                // Adaptador malicioso: devuelve incluso el registro de otro tenant.
                return $this->employees[$id] ?? null;
            }

            public function findByCode(Tenant $tenant, string $code): ?Employee
            {
                foreach ($this->employees as $employee) {
                    if ($employee->tenant()->id() === $tenant->id()
                        && $employee->code() === $code) {
                        return $employee;
                    }
                }
                return null;
            }

            public function findByUser(Tenant $tenant, User $user): ?Employee
            {
                return null;
            }

            public function commit(Employee $employee, AuditEvent $event): void
            {
                $this->employees[$employee->id()] = $employee;
                $this->writes++;
            }
        };
        $store->owners[$alpha->id()][$owner->id()] =
            new Membership($alpha, $owner, Membership::ROLE_OWNER);
        $store->owners[$beta->id()][$owner->id()] =
            new Membership($beta, $owner, Membership::ROLE_OWNER);
        $store->owners[$beta->id()][$foreign->id()] =
            new Membership($beta, $foreign, 'STAFF');

        $service = new EmployeeService($store);
        $employee = $service->create($alpha, 'EMP-A', $owner);
        self::assertNull($service->find($beta, $employee->id(), $owner));
        self::denied(
            fn () => $service->attachUser($beta, $employee->id(), $foreign, $owner),
            $employee->id(),
        );
        self::denied(
            fn () => $service->attachUser($alpha, $employee->id(), $foreign, $owner),
            $employee->id(),
        );
        self::assertSame(1, $store->writes, 'No hay cambios ni auditorías falsas.');
        self::assertNull($employee->user(), 'Sin asociaciones externas.');
    }

    public function testAttendanceRejectsForeignEmployeeAndPoisonedStore(): void
    {
        [$alpha, $beta] = self::tenants();
        $employeeA = new Employee($alpha, 'EMP-A');
        $employeeB = new Employee($beta, 'EMP-B');
        $reader = new class implements AttendanceAdapter {
            public function sourceKey(): string { return 'reader-synthetic'; }
            public function normalizedMarks(): iterable { return []; }
        };
        $store = new class implements AttendanceEventStore {
            public ?AttendanceEvent $poison = null;
            public int $writes = 0;

            public function recordOnce(AttendanceEvent $event, DateTimeImmutable $expireBefore): AttendanceEvent
            {
                $this->writes++;
                return $this->poison ?? $event;
            }
        };
        $service = new AttendanceService($store);
        $now = new DateTimeImmutable('2026-10-20T15:00:00Z');
        $mark = [
            'external_event_id' => 'evt-1001',
            'occurred_at' => '2026-10-20T12:00:00Z',
            'type' => 'check_in',
        ];
        self::denied(
            fn () => $service->recordPayload($beta, $employeeA, $reader, $mark, $now),
            $employeeA->id(),
        );
        self::assertSame(0, $store->writes);
        $store->poison = $service->recordPayload($beta, $employeeB, $reader, $mark, $now);
        self::denied(
            fn () => $service->recordPayload($alpha, $employeeA, $reader, $mark, $now),
            $store->poison->id(),
        );
    }

    public function testPhotoBankRejectsCrossTenantReadAndWriteBeforePersistence(): void
    {
        [$alpha, $beta] = self::tenants();
        $product = new Product($alpha, 'Catálogo Alpha', 'catalog-alpha');
        $variant = new ProductVariant($alpha, $product, 'SKU-ALPHA', 'Variante');
        $foreignProduct = new Product($beta, 'Catálogo Beta', 'catalog-beta');
        $foreignVariant = new ProductVariant($beta, $foreignProduct, 'SKU-BETA', 'Variante');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $photos = new ProductMediaService($entityManager);

        self::denied(fn () => $photos->byScope($beta, $product), $product->id());
        self::denied(
            fn () => $photos->add($beta, $product, null, '/tmp/demo.png', 'image/png', 7),
            $product->id(),
        );
        self::denied(
            fn () => $photos->reorder($alpha, $product, $foreignVariant, []),
            $foreignVariant->id(),
        );
        self::denied(
            fn () => $photos->byScope($alpha, $foreignProduct, $variant),
            $foreignProduct->id(),
        );
    }

    public function testScansCannotResolveOrAssociateForeignVariant(): void
    {
        [$alpha, $beta] = self::tenants();
        $product = new Product($alpha, 'Inventario A', 'stock-alpha');
        $variant = new ProductVariant($alpha, $product, 'STOCK-A', 'Variante');
        $resolver = new ScanResolver();
        $resolver->register(ScanIdentifier::forVariant($alpha, $variant, 'CODE-1234'));

        self::assertNull($resolver->resolve($beta, 'CODE-1234'));
        self::assertNull($resolver->resolve($beta, 'UNSEEN-2222'));
        self::denied(
            fn () => ScanIdentifier::forVariant($beta, $variant, 'CODE-1234'),
            $variant->id(),
        );
        $resolver->register(ScanIdentifier::forUnit($beta, 'UNIT-B', 'CODE-1234'));
        self::assertSame(
            ScanIdentifier::VARIANT, $resolver->resolve($alpha, 'CODE-1234')?->kind(),
        );
        self::assertSame(
            ScanIdentifier::UNIT, $resolver->resolve($beta, 'CODE-1234')?->kind(),
        );
    }

    public function testAlertInboxDeniesCrossTenantWithSharedUser(): void
    {
        [$alpha, $beta] = self::tenants();
        $shared = new User('shared@example.invalid', 'Cuenta sintética compartida');
        $viewerA = new Membership($alpha, $shared, Membership::ROLE_OWNER);
        $viewerB = new Membership($beta, $shared, Membership::ROLE_OWNER);
        $alerts = new StockAlertService(static fn (): bool => true);
        $delivery = new NotificationDelivery(
            $shared,
            NotificationCatalog::EVENT_STOCK_LOW,
            NotificationCatalog::CHANNEL_IN_APP,
            ['tenant_id' => $alpha->id(), 'quantity' => 3, 'source_id' => 'sensitive-a'],
        );
        self::assertSame([$delivery], $alerts->visibleInbox($viewerA, [$delivery]));
        self::assertSame([], $alerts->visibleInbox($viewerB, [$delivery]));
    }

    public function testProductionCostRejectsForeignOrderAndMaterialBeforeRead(): void
    {
        [$alpha, $beta] = self::tenants();
        $materialB = new Material($beta, 'MAT-B', 'Material Beta', UnitOfMeasure::from('m'));
        $weighted = new MaterialCostService();
        self::denied(
            fn () => $weighted->calculate($alpha, $materialB, []),
            $materialB->id(),
        );

        $product = new Product($beta, 'Producción Beta', 'production-beta');
        $variant = new ProductVariant($beta, $product, 'PROD-B', 'Variante');
        $bom = new BillOfMaterials($beta, $variant, 1, [[
            'material' => $materialB,
            'quantity' => '1',
            'unit' => UnitOfMeasure::from('m'),
        ]]);
        $legal = new LegalEntity($beta, 'Empresa Beta', null);
        $source = new InventorySource(
            $beta, $legal, 'Almacén Beta', 'almacen-beta', InventorySource::TYPE_LOGICAL,
        );
        $order = new ProductionOrder($beta, $bom, $variant, $source, 1);
        $cost = new ProductionCostService($weighted);
        self::denied(fn () => $cost->calculate($alpha, $order, []), $order->id());
        self::assertNull($order->costSnapshot(), 'No se expone ni crea snapshot ajeno.');
    }
}
