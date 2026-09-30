<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use DateTimeImmutable;
use DomainException;

final class UsageLedger
{
    private readonly string $tenantId;

    /**
     * @var array<string,array{
     *     metric:UsageMetric,
     *     quantity:int,
     *     window_start:DateTimeImmutable,
     *     window_end:DateTimeImmutable,
     *     observed_at:DateTimeImmutable
     * }>
     */
    private array $buckets = [];

    public function __construct(string $tenantId)
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de ledger inválido.');
        }

        $this->tenantId = $tenantId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function add(UsageRecord $record): void
    {
        if ($record->tenantId() !== $this->tenantId) {
            throw new DomainException('Usage pertenece a otro tenant.');
        }

        $key = self::bucketKey($record->metric(), $record->windowStart(), $record->windowEnd());
        $current = $this->buckets[$key] ?? null;

        if ($current === null) {
            $this->buckets[$key] = [
                'metric' => $record->metric(),
                'quantity' => $record->quantity(),
                'window_start' => $record->windowStart(),
                'window_end' => $record->windowEnd(),
                'observed_at' => $record->observedAt(),
            ];

            return;
        }

        $quantity = $current['quantity'];
        if ($record->metric()->aggregation() === 'max') {
            $quantity = max($quantity, $record->quantity());
        } else {
            if ($record->quantity() > PHP_INT_MAX - $quantity) {
                throw new DomainException('Overflow de usage acumulado.');
            }
            $quantity += $record->quantity();
        }

        $this->buckets[$key] = [
            ...$current,
            'quantity' => $quantity,
            'observed_at' => $record->observedAt() > $current['observed_at']
                ? $record->observedAt()
                : $current['observed_at'],
        ];
    }

    public function quantity(
        UsageMetric|string $metric,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): ?int {
        $metric = self::normalizeMetric($metric);
        self::assertWindow($windowStart, $windowEnd);

        $key = self::bucketKey($metric, $windowStart, $windowEnd);

        return $this->buckets[$key]['quantity'] ?? null;
    }

    /**
     * @return list<array{
     *     tenant_id:string,
     *     metric:string,
     *     aggregation:string,
     *     quantity:int,
     *     window_start:string,
     *     window_end:string,
     *     observed_at:string
     * }>
     */
    public function snapshot(): array
    {
        $rows = [];
        foreach ($this->buckets as $bucket) {
            $rows[] = [
                'tenant_id' => $this->tenantId,
                'metric' => $bucket['metric']->value,
                'aggregation' => $bucket['metric']->aggregation(),
                'quantity' => $bucket['quantity'],
                'window_start' => self::formatTime($bucket['window_start']),
                'window_end' => self::formatTime($bucket['window_end']),
                'observed_at' => self::formatTime($bucket['observed_at']),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [
                $left['metric'],
                $left['window_start'],
                $left['window_end'],
            ] <=> [
                $right['metric'],
                $right['window_start'],
                $right['window_end'],
            ],
        );

        return $rows;
    }

    private static function normalizeMetric(UsageMetric|string $metric): UsageMetric
    {
        return $metric instanceof UsageMetric ? $metric : UsageMetric::fromKey($metric);
    }

    private static function assertWindow(DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): void
    {
        if ($windowEnd <= $windowStart) {
            throw new DomainException('Ventana de usage inválida.');
        }
    }

    private static function bucketKey(
        UsageMetric $metric,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): string {
        return implode('|', [
            $metric->value,
            self::formatTime($windowStart),
            self::formatTime($windowEnd),
        ]);
    }

    private static function formatTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.uP');
    }
}
