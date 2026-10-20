<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use DomainException;
use RuntimeException;

/**
 * Pure CSV rendering for already-authorized report records.
 *
 * The caller must resolve the authenticated tenant and query authoritative
 * inventory/orders/purchasing sources. This class independently enforces the
 * tenant/date boundary and emits an allowlisted, stable CSV projection; it
 * neither trusts query parameters as identity nor accesses a database/network.
 */
final class TenantCsvReportService
{
    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'inventory' => ['record_id', 'occurred_at', 'source_id', 'variant_id', 'quantity', 'reserved_quantity'],
        'sales' => ['order_id', 'occurred_at', 'currency', 'total_amount_minor', 'payment_status'],
        'purchases' => ['purchase_order_id', 'occurred_at', 'supplier_id', 'status', 'total_cost_minor'],
    ];

    /**
     * @param iterable<array<string, mixed>> $records From an authorized repository only.
     * @return array{filename:string,content_type:string,body:string}
     */
    public function build(
        string $kind,
        string $authenticatedTenantId,
        string $fromDate,
        string $throughDate,
        iterable $records,
    ): array {
        if (!isset(self::COLUMNS[$kind])) {
            throw new DomainException('REPORT_KIND_UNSUPPORTED');
        }
        if ($authenticatedTenantId === '' || strlen($authenticatedTenantId) > 128) {
            throw new DomainException('TENANT_REQUIRED');
        }
        self::assertDate($fromDate);
        self::assertDate($throughDate);
        if ($fromDate > $throughDate) {
            throw new DomainException('INVALID_REPORT_RANGE');
        }

        $stream = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($stream === false) {
            throw new RuntimeException('CSV_STREAM_UNAVAILABLE');
        }
        try {
            $columns = self::COLUMNS[$kind];
            self::writeRow($stream, $columns);
            $seen = 0;
            foreach ($records as $record) {
                if (!isset($record['tenant_id'])
                    || !is_string($record['tenant_id'])
                    || !isset($record['occurred_at'])
                    || !is_string($record['occurred_at'])) {
                    throw new DomainException('INVALID_REPORT_RECORD');
                }
                // Validate dates even on foreign records: no malformed rows pass.
                self::assertDate($record['occurred_at']);
                if ($record['tenant_id'] !== $authenticatedTenantId
                    || $record['occurred_at'] < $fromDate
                    || $record['occurred_at'] > $throughDate) {
                    continue;
                }
                if (++$seen > 10000) {
                    throw new DomainException('REPORT_ROW_LIMIT');
                }
                $values = [];
                foreach ($columns as $column) {
                    if (!array_key_exists($column, $record)
                        || !(is_string($record[$column]) || is_int($record[$column]))) {
                        throw new DomainException('INVALID_REPORT_COLUMN');
                    }
                    $values[] = self::safeCell((string) $record[$column]);
                }
                self::writeRow($stream, $values);
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new RuntimeException('CSV_READ_FAILED');
            }
            return [
                'filename' => sprintf('%s-%s-%s.csv', $kind, $fromDate, $throughDate),
                'content_type' => 'text/csv; charset=utf-8',
                'body' => $csv,
            ];
        } finally {
            fclose($stream);
        }
    }

    private static function assertDate(string $value): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new DomainException('INVALID_REPORT_DATE');
        }
    }

    private static function safeCell(string $value): string
    {
        // Spreadsheet readers may execute formulas prefixed by leading spaces,
        // control characters, '=', '+', '-', or '@'. Protect untrusted labels.
        // Preserve complete signed numeric values; reject formula expressions.
        if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
            return $value;
        }
        if (preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * @param resource $stream
     * @param list<string> $values
     */
    private static function writeRow($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '') === false) {
            throw new RuntimeException('CSV_WRITE_FAILED');
        }
    }
}
