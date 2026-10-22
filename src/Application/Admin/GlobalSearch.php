<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only, tenant-scoped global lookup. Never query an unauthorized module.
 *
 * A customer's records belong to a tenant; orders are further restricted to
 * inventory sources from branches where the user has orders.view permission.
 */
final readonly class GlobalSearch
{
    private const MAX_PER_TYPE = 5;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array{products: bool, customers: bool, orders: bool} $permissions
     * @param list<InventorySource> $orderSources
     * @return array<string, list<array<string, string>>>
     */
    public function find(
        Tenant $tenant,
        string $query,
        array $permissions,
        array $orderSources,
    ): array {
        // The HTTP boundary accepts only literal letters, numbers, spaces,
        // dots and dashes: '%' and '_' cannot turn this into a wildcard scan.
        $term = '%'.mb_strtolower($query, 'UTF-8').'%';
        $results = [
            'products' => [],
            'customers' => [],
            'orders' => [],
        ];

        if ($permissions['products']) {
            /** @var list<Product> $products */
            $products = $this->entityManager->getRepository(Product::class)
                ->createQueryBuilder('p')
                ->andWhere('p.tenant = :tenant')
                ->andWhere('p.active = true')
                ->andWhere('LOWER(p.name) LIKE :term')
                ->setParameter('tenant', $tenant)
                ->setParameter('term', $term)
                ->orderBy('p.name', 'ASC')
                ->addOrderBy('p.id', 'ASC')
                ->setMaxResults(self::MAX_PER_TYPE)
                ->getQuery()
                ->getResult();

            foreach ($products as $product) {
                $results['products'][] = [
                    'id' => $product->id(),
                    'title' => $product->name(),
                    'subtitle' => 'Producto',
                    'href' => '/admin#catalog',
                ];
            }
        }

        if ($permissions['customers']) {
            /** @var list<Customer> $customers */
            $customers = $this->entityManager->getRepository(Customer::class)
                ->createQueryBuilder('c')
                ->andWhere('c.tenant = :tenant')
                ->andWhere('c.active = true')
                ->andWhere('LOWER(c.name) LIKE :term')
                ->setParameter('tenant', $tenant)
                ->setParameter('term', $term)
                ->orderBy('c.name', 'ASC')
                ->addOrderBy('c.id', 'ASC')
                ->setMaxResults(self::MAX_PER_TYPE)
                ->getQuery()
                ->getResult();

            foreach ($customers as $customer) {
                $results['customers'][] = [
                    'id' => $customer->id(),
                    'title' => $customer->name(),
                    'subtitle' => 'Cliente',
                    'href' => '/admin#commerce',
                ];
            }
        }

        if ($permissions['orders'] && $orderSources !== []) {
            /** @var list<Order> $orders */
            $orders = $this->entityManager->getRepository(Order::class)
                ->createQueryBuilder('o')
                ->andWhere('o.tenant = :tenant')
                ->andWhere('o.inventorySource IN (:sources)')
                ->andWhere('LOWER(o.id) LIKE :term')
                ->setParameter('tenant', $tenant)
                ->setParameter('sources', $orderSources)
                ->setParameter('term', $term)
                ->orderBy('o.createdAt', 'DESC')
                ->addOrderBy('o.id', 'DESC')
                ->setMaxResults(self::MAX_PER_TYPE)
                ->getQuery()
                ->getResult();

            foreach ($orders as $order) {
                $results['orders'][] = [
                    'id' => $order->id(),
                    'title' => 'Pedido '.$order->id(),
                    'subtitle' => 'Pedido',
                    'href' => '/admin#orders',
                ];
            }
        }

        return $results;
    }
}
