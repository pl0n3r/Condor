<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Reads persisted report records under an authenticated tenant/legal-entity/source.
 * No tenant, entity or source selector is accepted from browser input.
 */
final readonly class TenantReportRecordsReader
{
    private const ROW_LIMIT = 10000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function read(
        string $kind,
        Tenant $tenant,
        LegalEntity $legalEntity,
        InventorySource $source,
        string $from,
        string $through,
    ): array {
        if ($source->tenant()->id() !== $tenant->id()
            || $source->legalEntity()->id() !== $legalEntity->id()
            || $legalEntity->tenant()->id() !== $tenant->id()
            || $source->branch() === null) {
            throw new DomainException('REPORT_SCOPE_INVALID');
        }
        $start = self::dayBoundary($from);
        $end = self::dayBoundary($through);
        if ($start > $end) {
            throw new DomainException('INVALID_REPORT_RANGE');
        }
        $end = $end->modify('+1 day');

        return match ($kind) {
            'inventory' => $this->inventory($tenant, $legalEntity, $source, $start, $end),
            'sales' => $this->sales($tenant, $legalEntity, $source, $start, $end),
            'purchases' => $this->purchases($tenant, $legalEntity, $source, $start, $end),
            default => throw new DomainException('REPORT_KIND_UNSUPPORTED'),
        };
    }

    private static function dayBoundary(string $day): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1
            || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            throw new DomainException('INVALID_REPORT_DATE');
        }
        return new DateTimeImmutable($day.' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function recordDate(mixed $value): string
    {
        if (!$value instanceof DateTimeInterface) {
            throw new DomainException('REPORT_DATE_INVALID');
        }
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** @param list<array<string, mixed>> $rows */
    private static function bounded(array $rows): array
    {
        if (count($rows) > self::ROW_LIMIT) {
            throw new DomainException('REPORT_ROW_LIMIT');
        }
        return $rows;
    }

    /** @return list<array<string, int|string>> */
    private function inventory(
        Tenant $tenant,
        LegalEntity $legalEntity,
        InventorySource $source,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'b.id AS recordId', 'b.updatedAt AS stamp',
                'IDENTITY(b.source) AS sourceId', 'IDENTITY(b.variant) AS variantId',
                'b.quantity AS quantity', 'b.reservedQuantity AS reservedQuantity',
            )
            ->from(InventoryBalance::class, 'b')
            ->where('b.tenant = :tenant AND b.legalEntity = :legal AND b.source = :source')
            ->andWhere('b.updatedAt >= :start AND b.updatedAt < :end')
            ->setParameters(['tenant' => $tenant, 'legal' => $legalEntity, 'source' => $source,
                'start' => $start, 'end' => $end])
            ->orderBy('b.updatedAt', 'ASC')->addOrderBy('b.id', 'ASC')
            ->setMaxResults(self::ROW_LIMIT + 1)->getQuery()->getArrayResult();
        self::bounded($rows);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'tenant_id' => $tenant->id(),
                'record_id' => (string) $row['recordId'],
                'occurred_at' => self::recordDate($row['stamp']),
                'source_id' => (string) $row['sourceId'],
                'variant_id' => (string) $row['variantId'],
                'quantity' => (int) $row['quantity'],
                'reserved_quantity' => (int) $row['reservedQuantity'],
            ];
        }
        return $result;
    }

    /** @return list<array<string, int|string>> */
    private function sales(
        Tenant $tenant,
        LegalEntity $legalEntity,
        InventorySource $source,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.id AS orderId', 'o.createdAt AS stamp',
                'o.currency AS currency', 'o.totalAmountMinor AS total',
                'o.paymentStatus AS payment')
            ->from(Order::class, 'o')
            ->where('o.tenant = :tenant AND o.legalEntity = :legal AND o.inventorySource = :source')
            ->andWhere('o.createdAt >= :start AND o.createdAt < :end')
            ->setParameters(['tenant' => $tenant, 'legal' => $legalEntity, 'source' => $source,
                'start' => $start, 'end' => $end])
            ->orderBy('o.createdAt', 'ASC')->addOrderBy('o.id', 'ASC')
            ->setMaxResults(self::ROW_LIMIT + 1)->getQuery()->getArrayResult();
        self::bounded($rows);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'tenant_id' => $tenant->id(),
                'order_id' => (string) $row['orderId'],
                'occurred_at' => self::recordDate($row['stamp']),
                'currency' => (string) $row['currency'],
                'total_amount_minor' => (int) $row['total'],
                'payment_status' => (string) $row['payment'],
            ];
        }
        return $result;
    }

    /** @return list<array<string, int|string>> */
    private function purchases(
        Tenant $tenant,
        LegalEntity $legalEntity,
        InventorySource $source,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        // Drafts do not belong to a branch. Only received purchases with a
        // persisted receipt source can be safely exported by branch.
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p', 'p.receivedAt AS stamp')
            ->from(PurchaseOrder::class, 'p')
            ->where('p.tenant = :tenant AND p.legalEntity = :legal')
            ->andWhere('p.status = :received AND p.receiptSourceId = :sourceId')
            ->andWhere('p.receivedAt >= :start AND p.receivedAt < :end')
            ->setParameters(['tenant' => $tenant, 'legal' => $legalEntity,
                'received' => PurchaseOrder::STATUS_RECEIVED, 'sourceId' => $source->id(),
                'start' => $start, 'end' => $end])
            ->orderBy('p.receivedAt', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setMaxResults(self::ROW_LIMIT + 1)->getQuery()->getResult();
        self::bounded($rows);
        $result = [];
        foreach ($rows as $row) {
            $order = $row[0] ?? null;
            if (!$order instanceof PurchaseOrder
                || $order->tenant()->id() !== $tenant->id()
                || $order->legalEntity()->id() !== $legalEntity->id()
                || $order->receiptSourceId() !== $source->id()) {
                throw new DomainException('REPORT_SCOPE_INVALID');
            }
            $result[] = [
                'tenant_id' => $tenant->id(),
                'purchase_order_id' => $order->id(),
                'occurred_at' => self::recordDate($row['stamp'] ?? null),
                'supplier_id' => $order->supplier()->id(),
                'status' => $order->status(),
                'total_cost_minor' => self::purchaseCostMinor($order),
            ];
        }
        return $result;
    }

    private static function purchaseCostMinor(PurchaseOrder $order): string
    {
        $total = '0';
        foreach ($order->lines() as $line) {
            if ($line->tenant()->id() !== $order->tenant()->id()) {
                throw new DomainException('REPORT_SCOPE_INVALID');
            }
            $total = self::addDigits($total, self::multiplyDigits(
                self::scaledDigits($line->quantity(), 6),
                self::scaledDigits($line->unitCost(), 4),
            ));
        }
        // Product has 10 fractional digits: round half-up to two decimals
        // with integer-string math, never floats or an unbounded SQL SUM.
        $rounded = str_pad(self::addDigits($total, '50000000'), 9, '0', STR_PAD_LEFT);
        $cents = ltrim(substr($rounded, 0, -8), '0');
        return $cents === '' ? '0' : $cents;
    }

    private static function scaledDigits(string $value, int $scale): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,14})(?:\.[0-9]{1,'.$scale.'})?$/D', $value) !== 1) {
            throw new DomainException('INVALID_REPORT_COST');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ltrim($whole.str_pad($fraction, $scale, '0'), '0') ?: '0';
    }

    private static function addDigits(string $left, string $right): string
    {
        $carry = 0;
        $out = '';
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry > 0; --$i, --$j) {
            $sum = $carry + ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0);
            $out = (string) ($sum % 10).$out;
            $carry = intdiv($sum, 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    private static function multiplyDigits(string $left, string $right): string
    {
        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; --$i) {
            $carry = 0;
            for ($j = strlen($right) - 1; $j >= 0; --$j) {
                $index = $i + $j + 1;
                $digit = (int) $left[$i] * (int) $right[$j] + $result[$index] + $carry;
                $result[$index] = $digit % 10;
                $carry = intdiv($digit, 10);
            }
            $result[$i] += $carry;
        }
        return ltrim(implode('', $result), '0') ?: '0';
    }
}
