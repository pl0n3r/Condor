<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/** Read-only, tenant/branch scoped order listing with deterministic pagination. */
final class OrderListQuery
{
    /**
     * @param list<InventorySource> $authorizedSources Resolved by branch permissions, never from query input.
     * @param array<string, mixed> $params Raw HTTP query values.
     * @return array{orders:list<Order>, pagination:array{page:int,per_page:int,total:int,has_more:bool}}
     */
    public function fetch(
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        LegalEntity $legalEntity,
        array $authorizedSources,
        array $params,
    ): array {
        $filters = $this->filters($params);
        $page = $filters['page'];
        $perPage = $filters['per_page'];
        $empty = ['orders' => [], 'pagination' => [
            'page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false,
        ]];

        if ($authorizedSources === []) {
            return $empty;
        }
        foreach ($authorizedSources as $source) {
            if ($source->tenant()->id() !== $tenant->id()
                || $source->legalEntity()->id() !== $legalEntity->id()) {
                throw new DomainException('ORDER_LIST_INVALID_SCOPE');
            }
        }
        if ($tenant->id() !== $legalEntity->tenant()->id()) {
            throw new DomainException('ORDER_LIST_INVALID_SCOPE');
        }

        $query = $entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->leftJoin('o.customer', 'c')
            ->where('o.tenant = :tenant')
            ->andWhere('o.legalEntity = :legalEntity')
            ->andWhere('o.inventorySource IN (:sources)')
            ->setParameter('tenant', $tenant)
            ->setParameter('legalEntity', $legalEntity)
            ->setParameter('sources', $authorizedSources);

        foreach ([
            'status' => 'orderStatus',
            'payment_status' => 'paymentStatus',
            'fulfillment_status' => 'fulfillmentStatus',
        ] as $parameter => $field) {
            if ($filters[$parameter] !== null) {
                $query->andWhere('o.'.$field.' = :'.$parameter)
                    ->setParameter($parameter, $filters[$parameter]);
            }
        }
        if ($filters['customer_id'] !== null) {
            $query->andWhere('c.id = :customerId')
                ->setParameter('customerId', $filters['customer_id']);
        }
        if ($filters['q'] !== null) {
            $query->andWhere('(LOWER(o.id) LIKE :term OR LOWER(c.name) LIKE :term)')
                ->setParameter('term', '%'.mb_strtolower($filters['q'], 'UTF-8').'%');
        }
        if ($filters['from'] !== null) {
            $query->andWhere('o.createdAt >= :start')
                ->setParameter('start', $filters['from']);
        }
        if ($filters['to'] !== null) {
            $query->andWhere('o.createdAt < :end')
                ->setParameter('end', $filters['to']);
        }

        $counter = clone $query;
        $total = (int) $counter->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->orderBy('o.createdAt', \SortDirection::Descending)
            ->addOrderBy('o.id', \SortDirection::Descending)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();

        /** @var list<Order> $orders */
        $orders = array_values($rows);
        return ['orders' => $orders, 'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $page * $perPage < $total,
        ]];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{page:int,per_page:int,status:?string,payment_status:?string,fulfillment_status:?string,customer_id:?string,q:?string,from:?DateTimeImmutable,to:?DateTimeImmutable}
     */
    private function filters(array $params): array
    {
        $allowed = [
            'page', 'per_page', 'status', 'payment_status',
            'fulfillment_status', 'customer_id', 'q', 'from', 'to',
        ];
        if (array_diff(array_keys($params), $allowed) !== []) {
            throw new DomainException('ORDER_LIST_UNKNOWN_FILTER');
        }
        foreach ($params as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new DomainException('ORDER_LIST_INVALID_FILTER');
            }
        }
        $page = $this->positiveNumber($params['page'] ?? '1', 1000);
        $perPage = $this->positiveNumber($params['per_page'] ?? '50', 100);
        $status = $this->oneOf($params['status'] ?? null, [
            Order::STATUS_OPEN, Order::STATUS_CANCELLED,
        ]);
        $payment = $this->oneOf($params['payment_status'] ?? null, [
            Order::PAYMENT_PENDING, Order::PAYMENT_PAID,
        ]);
        $fulfillment = $this->oneOf($params['fulfillment_status'] ?? null, [
            Order::FULFILLMENT_RESERVED, Order::FULFILLMENT_RELEASED, Order::FULFILLMENT_CONSUMED,
        ]);
        $customer = $params['customer_id'] ?? null;
        if ($customer !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $customer) !== 1) {
            throw new DomainException('ORDER_LIST_INVALID_CUSTOMER');
        }
        $term = $params['q'] ?? null;
        if ($term !== null
            && (mb_strlen($term, 'UTF-8') > 100
                || preg_match('/^[\pL\pN\s.@-]+$/uD', $term) !== 1)) {
            throw new DomainException('ORDER_LIST_INVALID_SEARCH');
        }
        $start = $this->day($params['from'] ?? null);
        $end = $this->day($params['to'] ?? null);
        if ($start !== null && $end !== null
            && ($start > $end || $start->diff($end)->days >= 366)) {
            throw new DomainException('ORDER_LIST_INVALID_RANGE');
        }
        return [
            'page' => $page,
            'per_page' => $perPage,
            'status' => $status,
            'payment_status' => $payment,
            'fulfillment_status' => $fulfillment,
            'customer_id' => $customer,
            'q' => $term,
            'from' => $start,
            'to' => $end?->modify('+1 day'),
        ];
    }

    private function positiveNumber(string $value, int $maximum): int
    {
        if (preg_match('/^[1-9]\d{0,3}$/D', $value) !== 1
            || (int) $value > $maximum) {
            throw new DomainException('ORDER_LIST_INVALID_PAGINATION');
        }
        return (int) $value;
    }

    /** @param list<string> $values */
    private function oneOf(?string $value, array $values): ?string
    {
        if ($value !== null && !in_array($value, $values, true)) {
            throw new DomainException('ORDER_LIST_INVALID_STATUS');
        }
        return $value;
    }

    private function day(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new DomainException('ORDER_LIST_INVALID_DATE');
        }
        return (new DateTimeImmutable($value.' 00:00:00', new DateTimeZone('America/Bogota')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
