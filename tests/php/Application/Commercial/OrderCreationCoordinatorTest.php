<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\ExecutionContext;
use App\Application\Commercial\OrderCreationCoordinator;
use App\Application\Commercial\UsageLimitExceeded;
use App\Application\Inventory\InventoryService;
use App\Application\Orders\OrderService;
use App\Tests\Application\Orders\Support\OrderFixtureTrait;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1486: orden + inventario + observación de uso en una sola transacción.
 * Las carreras usan procesos PHP reales con su propia conexión MariaDB.
 */
final class OrderCreationCoordinatorTest extends KernelTestCase
{
    use OrderFixtureTrait;

    private InventoryService $inventory;
    private EntityManagerInterface $entityManager;
    private OrderCreationCoordinator $coordinator;
    /** @var list<resource> */
    private array $procs = [];
    private string $dir = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $coordinator = static::getContainer()->get(OrderCreationCoordinator::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(OrderCreationCoordinator::class, $coordinator);
        $this->entityManager = $em;
        $this->inventory = new InventoryService($em);
        $this->coordinator = $coordinator;
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
        }
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testNewOrderCreatesExactlyOneObservationAndReplayAddsNone(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $context = $this->context($tenant, 10);
        $key = 'coord-'.bin2hex(random_bytes(6));

        $first = $this->place($context, $tenant, $source, $list, $channel, $variant, 1, $key);
        $replay = $this->place($context, $tenant, $source, $list, $channel, $variant, 1, $key);

        self::assertSame($first->id(), $replay->id());
        self::assertSame(1, $this->observations($tenant));
        self::assertSame(1, $this->reserved($tenant, $source, $variant));
    }

    public function testOnCreatedRunsOnlyForNewOrders(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $context = $this->context($tenant, 10);
        $key = 'coord-cb-'.bin2hex(random_bytes(6));
        $calls = 0;
        $hook = static function () use (&$calls): void {
            ++$calls;
        };

        foreach ([1, 2] as $_) {
            $this->coordinator->createWithin(
                $context, $tenant, $source, $list, $channel, null, null, $key,
                [['variant' => $variant, 'quantity' => 1]], OrderService::ORIGIN_ECOMMERCE, $hook,
            );
        }

        self::assertSame(1, $calls);
    }

    public function testLimitExceededRollsBackOrderInventoryAndObservation(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $context = $this->context($tenant, 1);
        $this->place($context, $tenant, $source, $list, $channel, $variant, 1, 'coord-a-'.bin2hex(random_bytes(4)));

        try {
            $this->place($context, $tenant, $source, $list, $channel, $variant, 2, 'coord-b-'.bin2hex(random_bytes(4)));
            self::fail('Se esperaba UsageLimitExceeded.');
        } catch (UsageLimitExceeded $e) {
            self::assertSame('orders', $e->metric);
            self::assertSame(1, $e->limit);
        }

        self::assertSame(1, $this->orderCount($tenant));
        self::assertSame(1, $this->observations($tenant));
        self::assertSame(1, $this->reserved($tenant, $source, $variant), 'La reserva del intento rechazado se revierte.');
    }

    public function testDifferentPayloadWithSameKeyRevertsAndKeepsOneObservation(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        $context = $this->context($tenant, 10);
        $key = 'coord-p-'.bin2hex(random_bytes(6));
        $this->place($context, $tenant, $source, $list, $channel, $variant, 1, $key);

        try {
            $this->place($context, $tenant, $source, $list, $channel, $variant, 2, $key);
            self::fail('Se esperaba DomainException por payload distinto.');
        } catch (DomainException) {
        }

        self::assertSame(1, $this->observations($tenant));
        self::assertSame(1, $this->reserved($tenant, $source, $variant));
    }

    public function testInventoryFailureRevertsTheObservation(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(1, 1000);
        $context = $this->context($tenant, 10);

        try {
            $this->place($context, $tenant, $source, $list, $channel, $variant, 5, 'coord-i-'.bin2hex(random_bytes(4)));
            self::fail('Se esperaba fallo de inventario.');
        } catch (DomainException) {
        }

        self::assertSame(0, $this->observations($tenant));
        self::assertSame(0, $this->reserved($tenant, $source, $variant));
    }

    public function testContextFromAnotherTenantIsRejected(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(5, 1000);
        [$other] = $this->fixture(0, 1);

        $this->expectException(DomainException::class);
        $this->place($this->context($other, 10), $tenant, $source, $list, $channel, $variant, 1, 'coord-t-'.bin2hex(random_bytes(4)));
    }

    public function testRaceOnLastSlotAdmitsExactlyTheLimit(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(20, 1000);
        $keys = array_map(static fn (int $i): string => 'race-'.$i.'-'.bin2hex(random_bytes(4)), range(1, 6));

        $results = $this->race($tenant, $source, $list, $channel, $variant, 3, $keys);

        self::assertSame(3, count(array_filter($results, static fn (array $r): bool => $r['status'] === 'created')));
        self::assertSame(3, count(array_filter($results, static fn (array $r): bool => $r['status'] === 'limit')));
        self::assertSame(3, $this->observations($tenant), 'Ni una observación de más ni de menos.');
        self::assertSame(3, $this->reserved($tenant, $source, $variant));
        self::assertSame(3, $this->orderCount($tenant));
    }

    public function testRaceWithSameKeyYieldsOneOrderAndOneObservation(): void
    {
        [$tenant, $source, $list, $channel, $variant] = $this->fixture(20, 1000);
        $key = 'race-same-'.bin2hex(random_bytes(4));

        $results = $this->race($tenant, $source, $list, $channel, $variant, 10, array_fill(0, 4, $key));

        $ids = array_unique(array_filter(array_column($results, 'order')));
        self::assertCount(1, $ids, 'Todos los reintentos ven la misma orden.');
        self::assertSame(1, count(array_filter($results, static fn (array $r): bool => $r['status'] === 'created')));
        self::assertSame(1, $this->observations($tenant));
        self::assertSame(1, $this->reserved($tenant, $source, $variant));
    }

    // ---- helpers -----------------------------------------------------------

    private function place(
        ExecutionContext $context,
        Tenant $tenant,
        InventorySource $source,
        PriceList $list,
        SalesChannel $channel,
        ProductVariant $variant,
        int $quantity,
        string $key,
    ): Order {
        return $this->coordinator->createWithin(
            $context, $tenant, $source, $list, $channel, null, null, $key,
            [['variant' => $variant, 'quantity' => $quantity]], OrderService::ORIGIN_ECOMMERCE,
        );
    }

    private function context(Tenant $tenant, int $orders): ExecutionContext
    {
        return new ExecutionContext($tenant->id(), $this->snapshot($tenant, $orders), 'test');
    }

    private function snapshot(Tenant $tenant, int $orders): EntitlementSnapshot
    {
        return new EntitlementSnapshot(
            $tenant->id(), 'test-plan', 1, 'general', [], [],
            ['companies' => 1, 'users' => 5, 'locations' => 5, 'orders' => $orders],
            [], new DateTimeImmutable('now'),
        );
    }

    private function observations(Tenant $tenant): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM condor_commercial_usage_observation WHERE tenant_id = ? AND metric = 'orders'",
            [$tenant->id()],
        );
    }

    private function reserved(Tenant $tenant, InventorySource $source, ProductVariant $variant): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT reserved_quantity FROM condor_inventory_balance WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
            [$tenant->id(), $source->id(), $variant->id()],
        );
    }

    private function orderCount(Tenant $tenant): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM condor_order WHERE tenant_id = ?',
            [$tenant->id()],
        );
    }
}
