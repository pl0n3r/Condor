<?php

declare(strict_types=1);

namespace App\Application\Orders;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use RuntimeException;

/** Pure CSV projection. All repository reads and access decisions belong to the controller. */
final class OrderCsvExporter
{
    public const ROW_LIMIT = 10000;

    private const COLUMNS = [
        'Pedido', 'Fecha (Colombia)', 'Estado pedido', 'Estado pago',
        'Cumplimiento', 'Cliente', 'Moneda', 'Total (unidad menor)',
    ];

    /** @return array{DateTimeImmutable, DateTimeImmutable} Inclusive Bogota days, exclusive UTC end. */
    public function window(string $from, string $to): array
    {
        foreach ([$from, $to] as $day) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1
                || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
                throw new DomainException('INVALID_ORDER_CSV_DATE');
            }
        }

        $bogota = new DateTimeZone('America/Bogota');
        $start = new DateTimeImmutable($from.' 00:00:00', $bogota);
        $last = new DateTimeImmutable($to.' 00:00:00', $bogota);
        if ($start > $last || $start->diff($last)->days > 366) {
            throw new DomainException('INVALID_ORDER_CSV_RANGE');
        }
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc), $last->modify('+1 day')->setTimezone($utc)];
    }

    /**
     * @param list<string> $authorizedSourceIds Exact source IDs resolved from session permissions.
     * @param iterable<array<string, mixed>> $records Rows from a tenant-filtered, bounded query.
     */
    public function build(
        string $authenticatedTenantId,
        array $authorizedSourceIds,
        string $from,
        string $to,
        iterable $records,
    ): string {
        [$start, $end] = $this->window($from, $to);
        if ($authenticatedTenantId === '' || $authorizedSourceIds === []) {
            throw new DomainException('ORDER_CSV_SCOPE_REQUIRED');
        }
        $allowed = [];
        foreach ($authorizedSourceIds as $sourceId) {
            if (!is_string($sourceId) || $sourceId === '') {
                throw new DomainException('ORDER_CSV_SCOPE_INVALID');
            }
            $allowed[$sourceId] = true;
        }
        $handle = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($handle === false) {
            throw new RuntimeException('ORDER_CSV_STREAM_UNAVAILABLE');
        }
        try {
            self::writeRow($handle, self::COLUMNS);
            $seen = 0;
            foreach ($records as $record) {
                if (++$seen > self::ROW_LIMIT) {
                    throw new DomainException('ORDER_CSV_ROW_LIMIT');
                }
                foreach (['tenant_id', 'source_id', 'id', 'customer_name', 'order_status',
                    'payment_status', 'fulfillment_status', 'currency'] as $key) {
                    if (!isset($record[$key]) || !is_string($record[$key])) {
                        throw new DomainException('ORDER_CSV_INVALID_RECORD');
                    }
                }
                if ($record['tenant_id'] !== $authenticatedTenantId
                    || !isset($allowed[$record['source_id']])) {
                    throw new DomainException('ORDER_CSV_SCOPE_INVALID');
                }
                if (!$record['created_at'] instanceof DateTimeInterface
                    || !isset($record['total_amount_minor'])
                    || !is_int($record['total_amount_minor'])
                    || $record['total_amount_minor'] < 0) {
                    throw new DomainException('ORDER_CSV_INVALID_RECORD');
                }
                $at = DateTimeImmutable::createFromInterface($record['created_at']);
                if ($at < $start || $at >= $end) {
                    throw new DomainException('ORDER_CSV_DATE_MISMATCH');
                }
                $date = $at->setTimezone(new DateTimeZone('America/Bogota'))
                    ->format('Y-m-d H:i:s');
                self::writeRow($handle, [
                    self::safeCell($record['id']),
                    $date,
                    self::safeCell($record['order_status']),
                    self::safeCell($record['payment_status']),
                    self::safeCell($record['fulfillment_status']),
                    self::safeCell($record['customer_name']),
                    self::safeCell($record['currency']),
                    (string) $record['total_amount_minor'],
                ]);
            }
            rewind($handle);
            $result = stream_get_contents($handle);
            if ($result === false) {
                throw new RuntimeException('ORDER_CSV_READ_FAILED');
            }
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private static function safeCell(string $text): string
    {
        // Escaped fields remain literal even when a spreadsheet ignores leading whitespace.
        if (preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1) {
            return "'".$text;
        }
        return $text;
    }

    /** @param resource $handle @param list<string> $fields */
    private static function writeRow($handle, array $fields): void
    {
        if (fputcsv($handle, $fields, ',', '"', '') === false) {
            throw new RuntimeException('ORDER_CSV_WRITE_FAILED');
        }
    }
}
