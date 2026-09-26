<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Domain\Orders\Entity\Order;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExpiredOrderReleaseService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderService $orders,
    ) {
    }

    public function releaseExpired(
        DateTimeImmutable $now,
        int $limit = 100,
    ): int {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException(
                'El límite debe estar entre 1 y 1000.',
            );
        }

        $expired = $this->entityManager
            ->createQueryBuilder()
            ->select('orders')
            ->from(Order::class, 'orders')
            ->andWhere('orders.expiresAt IS NOT NULL')
            ->andWhere('orders.expiresAt <= :now')
            ->andWhere('orders.fulfillmentStatus = :reserved')
            ->setParameter('now', $now)
            ->setParameter('reserved', Order::FULFILLMENT_RESERVED)
            ->orderBy('orders.expiresAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $released = 0;
        foreach ($expired as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $this->orders->release($order, null);
            ++$released;
        }

        return $released;
    }
}
