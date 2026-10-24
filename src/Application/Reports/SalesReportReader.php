<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/** Read-only tenant/branch scoped order-line aggregates, in integer minor units. */
final readonly class SalesReportReader
{
    private const MAX_ROWS = 10000;
    private const MAX_MINOR = 9007199254740991;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} [start, exclusive end] */
    public static function window(string $from, string $to): array
    {
        foreach ([$from, $to] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new DomainException('REPORT_DATE_INVALID');
            }
        }
        $start = new DateTimeImmutable($from.' 00:00:00', new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($to.' 00:00:00', new DateTimeZone('UTC'));
        if ($last < $start || $start->diff($last)->days > 365) {
            throw new DomainException('REPORT_RANGE_INVALID');
        }
        return [$start, $last->modify('+1 day')];
    }

    /**
     * @param list<\App\Domain\Inventory\Entity\InventorySource> $sources
     * @return array<string, mixed>
     */
    public function read(
        Tenant $tenant,
        array $sources,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $dimension,
    ): array {
        if (!in_array($dimension, ['product', 'month'], true)) {
            throw new DomainException('REPORT_DIMENSION_INVALID');
        }
        if ($sources === []) {
            return ['items' => [], 'currency_totals' => [], 'rows_scanned' => 0];
        }

        $sourceIds = [];
        foreach ($sources as $source) {
            if ($source->tenant()->id() !== $tenant->id() || !$source->isActive()) {
                throw new DomainException('REPORT_SCOPE_INVALID');
            }
            $sourceIds[] = $source->id();
        }

        // Never accept a tenant or source selector from the HTTP request.
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(l.tenant) AS tenant_id',
                'IDENTITY(o.inventorySource) AS source_id',
                'o.orderStatus AS order_status',
                'o.createdAt AS occurred_at',
                'o.currency AS currency',
                'l.quantity AS quantity',
                'l.lineTotalMinor AS revenue_minor',
                'IDENTITY(l.variant) AS variant_id',
                'IDENTITY(v.product) AS product_id',
                'v.sku AS sku',
                'v.name AS variant_name',
                'p.name AS product_name',
            )
            ->from(OrderLine::class, 'l')
            ->innerJoin('l.order', 'o')
            ->innerJoin('l.variant', 'v')
            ->innerJoin('v.product', 'p')
            ->where('l.tenant = :tenant AND o.tenant = :tenant AND v.tenant = :tenant')
            ->andWhere('o.inventorySource IN (:sources)')
            ->andWhere('o.orderStatus = :open')
            ->andWhere('o.createdAt >= :start AND o.createdAt < :end')
            ->setParameter('tenant', $tenant)
            ->setParameter('sources', $sources)
            ->setParameter('open', 'open')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('o.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults(self::MAX_ROWS + 1)
            ->getQuery()->getArrayResult();

        if (count($rows) > self::MAX_ROWS) {
            throw new DomainException('REPORT_ROW_LIMIT');
        }
        return self::aggregate($tenant->id(), $sourceIds, $rows, $dimension);
    }

    /**
     * Deterministic reducer also used by offline acceptance tests.
     * @param list<string> $sourceIds
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function aggregate(
        string $tenantId,
        array $sourceIds,
        array $rows,
        string $dimension,
    ): array {
        if ($tenantId === '' || !in_array($dimension, ['product', 'month'], true)) {
            throw new DomainException('REPORT_SCOPE_INVALID');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new DomainException('REPORT_ROW_LIMIT');
        }
        $allowed = array_fill_keys($sourceIds, true);
        $totals = [];
        $groups = [];
        $scanned = 0;

        foreach ($rows as $row) {
            if (($row['tenant_id'] ?? null) !== $tenantId
                || !isset($allowed[$row['source_id'] ?? ''])) {
                throw new DomainException('REPORT_SCOPE_INVALID');
            }
            if (($row['order_status'] ?? null) === 'cancelled') {
                continue;
            }
            if (($row['order_status'] ?? null) !== 'open') {
                throw new DomainException('REPORT_STATUS_INVALID');
            }
            $currency = $row['currency'] ?? null;
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new DomainException('REPORT_CURRENCY_INVALID');
            }
            $qty = self::nonNegativeInt($row['quantity'] ?? null, 2147483647);
            if ($qty === 0) {
                throw new DomainException('REPORT_QUANTITY_INVALID');
            }
            $minor = self::nonNegativeInt($row['revenue_minor'] ?? null, self::MAX_MINOR);
            $date = $row['occurred_at'] ?? null;
            if ($date instanceof DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }
            if (!is_string($date)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new DomainException('REPORT_DATE_INVALID');
            }

            if ($dimension === 'product') {
                foreach (['product_id', 'variant_id', 'sku', 'variant_name', 'product_name'] as $field) {
                    if (!is_string($row[$field] ?? null) || $row[$field] === '') {
                        throw new DomainException('REPORT_PRODUCT_INVALID');
                    }
                }
                $key = $currency.'|'.$row['variant_id'];
                $shape = [
                    'product_id' => $row['product_id'],
                    'variant_id' => $row['variant_id'],
                    'sku' => $row['sku'],
                    'variant_name' => $row['variant_name'],
                    'product_name' => $row['product_name'],
                    'currency' => $currency,
                    'units' => 0,
                    'revenue_minor' => 0,
                ];
            } else {
                $month = substr($date, 0, 7);
                $key = $currency.'|'.$month;
                $shape = ['month' => $month, 'currency' => $currency, 'units' => 0, 'revenue_minor' => 0];
            }

            if (!isset($groups[$key])) {
                $groups[$key] = $shape;
            } elseif ($dimension === 'product'
                && ($groups[$key]['product_id'] !== $row['product_id']
                    || $groups[$key]['sku'] !== $row['sku'])) {
                throw new DomainException('REPORT_PRODUCT_INVALID');
            }
            $groups[$key]['units'] += $qty;
            $groups[$key]['revenue_minor'] = self::addMinor($groups[$key]['revenue_minor'], $minor);
            if (!isset($totals[$currency])) {
                $totals[$currency] = ['currency' => $currency, 'units' => 0, 'revenue_minor' => 0];
            }
            $totals[$currency]['units'] += $qty;
            $totals[$currency]['revenue_minor'] = self::addMinor($totals[$currency]['revenue_minor'], $minor);
            ++$scanned;
        }

        if ($dimension === 'product') {
            foreach ($groups as &$group) {
                $whole = $totals[$group['currency']]['revenue_minor'];
                // Only the non-monetary percentage uses a floating ratio.
                $group['participation_bps'] = $whole === 0 ? 0
                    : (int) round(($group['revenue_minor'] / $whole) * 10000);
            }
            unset($group);
        }
        ksort($groups, SORT_STRING);
        ksort($totals, SORT_STRING);
        return [
            'items' => array_values($groups),
            'currency_totals' => array_values($totals),
            'rows_scanned' => $scanned,
        ];
    }

    private static function nonNegativeInt(mixed $value, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) !== 1
            || strlen((string) $value) > strlen((string) $maximum)
            || (strlen((string) $value) === strlen((string) $maximum)
                && strcmp((string) $value, (string) $maximum) > 0)) {
            throw new DomainException('REPORT_AMOUNT_INVALID');
        }
        return (int) $value;
    }

    private static function addMinor(int $left, int $right): int
    {
        if ($right > self::MAX_MINOR - $left) {
            throw new DomainException('REPORT_AMOUNT_OVERFLOW');
        }
        return $left + $right;
    }
}
