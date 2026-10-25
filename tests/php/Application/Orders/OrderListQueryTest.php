<?php

declare(strict_types=1);

namespace App\Tests\Application\Orders;

use App\Application\Orders\OrderListQuery;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderListQueryTest extends KernelTestCase
{
    public function testTenantFiltersAndStablePagination(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $service = new OrderListQuery();
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $a = new Tenant('Consultas A '.$suffix, 'consultas-a-'.$suffix);
        $legalA = new LegalEntity($a, 'Consultas A SAS', null, true);
        $branchA = new Branch($a, 'Principal', 'consulta-a-'.$suffix, $legalA, true);
        $sourceA = new InventorySource($a, $legalA, 'Bodega A', 'consulta-a-'.$suffix, InventorySource::TYPE_BRANCH, $branchA);
        $ana = new Customer($a, 'Camila Restrepo');
        $luis = new Customer($a, 'Luis Pérez');
        $a1 = new Order($a, $sourceA, null, $ana, null, 'qa-1-'.$suffix, 'COP', 100);
        $a2 = new Order($a, $sourceA, null, $luis, null, 'qa-2-'.$suffix, 'COP', 200);

        $b = new Tenant('Consultas B '.$suffix, 'consultas-b-'.$suffix);
        $legalB = new LegalEntity($b, 'Consultas B SAS', null, true);
        $branchB = new Branch($b, 'Principal', 'consulta-b-'.$suffix, $legalB, true);
        $sourceB = new InventorySource($b, $legalB, 'Bodega B', 'consulta-b-'.$suffix, InventorySource::TYPE_BRANCH, $branchB);
        $foreignCustomer = new Customer($b, 'Camila Restrepo');
        $foreign = new Order($b, $sourceB, null, $foreignCustomer, null, 'qa-foreign-'.$suffix, 'COP', 500);
        foreach ([$a, $legalA, $branchA, $sourceA, $ana, $luis, $a1, $a2,
            $b, $legalB, $branchB, $sourceB, $foreignCustomer, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $all = $service->fetch($em, $a, $legalA, [$sourceA], []);
        self::assertSame(2, $all['pagination']['total']);
        self::assertCount(2, $all['orders']);
        self::assertNotContains($foreign->id(), array_map(
            static fn (Order $order): string => $order->id(), $all['orders'],
        ));
        $first = $service->fetch($em, $a, $legalA, [$sourceA], ['page' => '1', 'per_page' => '1']);
        $second = $service->fetch($em, $a, $legalA, [$sourceA], ['page' => '2', 'per_page' => '1']);
        self::assertSame(2, $first['pagination']['total']);
        self::assertTrue($first['pagination']['has_more']);
        self::assertFalse($second['pagination']['has_more']);
        self::assertNotSame($first['orders'][0]->id(), $second['orders'][0]->id());
        self::assertSame($first['orders'][0]->id(),
            $service->fetch($em, $a, $legalA, [$sourceA], ['page' => '1', 'per_page' => '1'])['orders'][0]->id());

        $camila = $service->fetch($em, $a, $legalA, [$sourceA], ['q' => 'camila']);
        self::assertSame([$a1->id()], array_map(
            static fn (Order $order): string => $order->id(), $camila['orders'],
        ));
        self::assertSame(1, $service->fetch($em, $a, $legalA, [$sourceA],
            ['customer_id' => $luis->id()])['pagination']['total']);
        self::assertSame(2, $service->fetch($em, $a, $legalA, [$sourceA],
            ['status' => Order::STATUS_OPEN])['pagination']['total']);
        self::assertSame(0, $service->fetch($em, $a, $legalA, [$sourceA],
            ['payment_status' => Order::PAYMENT_PAID])['pagination']['total']);
        self::assertSame(0, $service->fetch($em, $a, $legalA, [$sourceA],
            ['from' => '2030-01-01'])['pagination']['total']);
        self::assertSame(0, $service->fetch($em, $a, $legalA, [], [])['pagination']['total']);

        foreach ([
            ['tenant_id' => $b->id()], ['page' => '0'], ['page' => '1001'],
            ['per_page' => '101'], ['status' => 'unknown'], ['q' => '%'],
            ['from' => '2026-02-30'], ['from' => '2026-08-09', 'to' => '2026-08-08'],
            ['to' => ['2026-01-01']],
        ] as $invalid) {
            try {
                $service->fetch($em, $a, $legalA, [$sourceA], $invalid);
                self::fail('El filtro inválido fue aceptado.');
            } catch (DomainException) {
                // Cada filtro inseguro debe fallar cerrado antes del acceso a datos.
            }
        }
        try {
            $service->fetch($em, $a, $legalA, [$sourceA, $sourceB], []);
            self::fail('Una fuente de otro tenant no debe aceptarse.');
        } catch (DomainException) {
            // El ámbito no depende de parámetros aportados por el solicitante.
        }
    }
}
