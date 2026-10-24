<?php

declare(strict_types=1);

namespace App\Tests\Application\Commerce;

use App\Application\Commerce\CustomerSearch;
use App\Domain\Commerce\Entity\CommercialRecord;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CustomerSearchTest extends TestCase
{
    public function testSearchIgnoresAccentsAndNeverReturnsAnotherTenantsCustomer(): void
    {
        $tenant = $this->tenant('local');
        $foreign = $this->tenant('foreign');
        $angela = new Customer(
            $tenant, 'Ángela Pérez', 'angela@example.test', '300 123 4567',
            documentNumber: 'CC-102.030',
        );
        $pablo = new Customer($tenant, 'Pablo Ñúñez', 'pablo@example.test', '310 999 1234');
        $decomposed = new Customer($tenant, "Jose\u{0301} García");
        $secret = new Customer(
            $foreign, 'Ángela extranjera', 'foreign@example.test', '300 123 4567',
            documentNumber: 'CC-102.030',
        );
        $inactive = new Customer($tenant, 'Ángela antigua');
        $inactive->deactivate();
        // Incluso si el repositorio subyacente devolviera un registro ajeno,
        // la proyección rechaza el cruce de tenants antes de responder.
        $service = $this->service([$angela, $pablo, $secret, $inactive, $decomposed]);

        self::assertSame([$angela->id()], $this->ids($service->search($tenant, 'ANGELA')));
        self::assertSame([$angela->id()], $this->ids($service->search($tenant, "A\u{0301}NGELA")));
        self::assertSame([$decomposed->id()], $this->ids($service->search($tenant, 'jose garcia')));
        self::assertSame([$angela->id()], $this->ids($service->search($tenant, 'perez')));
        self::assertSame([$angela->id()], $this->ids($service->search($tenant, '102.0')));
        self::assertSame([$angela->id()], $this->ids($service->search($tenant, 'cc-102')));
        self::assertSame([$pablo->id()], $this->ids($service->search($tenant, 'nunez')));
        self::assertSame([$pablo->id()], $this->ids($service->search($tenant, '999')));
        self::assertEqualsCanonicalizing(
            [$angela->id(), $pablo->id()],
            $this->ids($service->search($tenant, 'EXAMPLE.TEST')),
        );
        self::assertSame([], $service->search($tenant, 'extranjera'));
    }

    public function testNewAndWithoutRecentPurchasesAreDeterministicAndScoped(): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+1 minute');
        $tenant = $this->tenant('local');
        $other = $this->tenant('other');
        $recent = new Customer($tenant, 'Compra reciente');
        $oldOrder = new Customer($tenant, 'Compra histórica');
        $never = new Customer($tenant, 'Sin compras');
        $outside = new Customer($other, 'Ajeno');
        (new ReflectionProperty(CommercialRecord::class, 'createdAt'))
            ->setValue($oldOrder, $now->modify('-60 days'));

        $recentOrder = $this->order($tenant, $recent, 'recent');
        $historicOrder = $this->order($tenant, $oldOrder, 'historic');
        (new ReflectionProperty(Order::class, 'createdAt'))
            ->setValue($historicOrder, $now->modify('-95 days'));
        $foreignOrder = $this->order($other, $outside, 'foreign');
        $service = $this->service(
            [$recent, $oldOrder, $never, $outside],
            [$recentOrder, $historicOrder, $foreignOrder],
        );

        self::assertEqualsCanonicalizing(
            [$recent->id(), $never->id()],
            $this->ids($service->search($tenant, '', CustomerSearch::SEGMENT_NEW, $now)),
        );
        self::assertEqualsCanonicalizing(
            [$oldOrder->id(), $never->id()],
            $this->ids($service->search($tenant, '', CustomerSearch::SEGMENT_INACTIVE, $now)),
        );
        self::assertSame([], $service->search($other, 'Compra'));
    }

    public function testTopPurchasersAreCountedWithoutCancelledOrForeignOrders(): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+1 minute');
        $tenant = $this->tenant('top');
        $foreign = $this->tenant('foreign');
        $top = new Customer($tenant, 'Top de compras');
        $second = new Customer($tenant, 'Segunda');
        $empty = new Customer($tenant, 'Ninguna');
        $secret = new Customer($foreign, 'Ajena');
        $cancelled = $this->order($tenant, $empty, 'cancel');
        $cancelled->cancelAndRelease();
        $service = $this->service(
            [$empty, $second, $top, $secret],
            [
                $this->order($tenant, $top, 'a'),
                $this->order($tenant, $top, 'b'),
                $this->order($tenant, $second, 'c'),
                $this->order($foreign, $secret, 'foreign'),
                $cancelled,
            ],
        );

        self::assertSame(
            [$top->id(), $second->id()],
            $this->ids($service->search($tenant, '', CustomerSearch::SEGMENT_TOP, $now)),
        );
        self::assertSame(
            [$second->id()],
            $this->ids($service->search($tenant, 'SEGUNDA', CustomerSearch::SEGMENT_TOP, $now)),
        );
    }

    public function testInvalidFiltersFailClosedWithoutReadingRepositories(): void
    {
        $tenant = $this->tenant('invalid');
        $service = $this->service([]);
        foreach (['unknown', 'top_200', ''] as $segment) {
            try {
                $service->search($tenant, '', $segment);
                self::fail('Debe rechazar el segmento inválido.');
            } catch (DomainException) {
                // No se consulta a otro tenant ni se ignora un filtro desconocido.
            }
        }
        $this->expectException(DomainException::class);
        $service->search($tenant, str_repeat('x', 121));
    }

    /** @param list<Customer> $customers @param list<Order> $orders */
    private function service(array $customers, array $orders = []): CustomerSearch
    {
        $customerRepository = $this->createMock(EntityRepository::class);
        $customerRepository->method('findBy')->willReturn($customers);
        $ordersRepository = $this->createMock(EntityRepository::class);
        $ordersRepository->method('findBy')->willReturn($orders);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository =>
                $class === Customer::class ? $customerRepository : $ordersRepository,
        );

        return new CustomerSearch($manager);
    }

    private function tenant(string $label): Tenant
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        return new Tenant('Empresa '.$label.' '.$suffix, $label.'-'.$suffix);
    }

    private function order(Tenant $tenant, Customer $customer, string $key): Order
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $legal = new LegalEntity($tenant, 'Empresa SAS', null, true);
        $branch = new Branch($tenant, 'Principal', 'principal-'.$suffix, $legal, true);
        $source = new InventorySource(
            $tenant, $legal, 'Fuente', 'fuente-'.$suffix,
            InventorySource::TYPE_BRANCH, $branch,
        );

        return new Order($tenant, $source, null, $customer, null, $key, 'COP', 1000);
    }

    /** @param list<Customer> $customers @return list<string> */
    private function ids(array $customers): array
    {
        return array_values(array_map(
            static fn (Customer $customer): string => $customer->id(),
            $customers,
        ));
    }
}
