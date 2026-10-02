<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

final class SaasMetricsHistory
{
    private const SOURCE = [
        'baseline' => PlatformCommercialMetrics::class,
        'movements' => SaasRevenueMovements::class,
        'retention' => SaasRetentionMetrics::class,
    ];

    /**
     * @param list<array{
     *   baseline_snapshot:array<string,mixed>,
     *   ending_snapshot:array<string,mixed>,
     *   revenue_movements:array<string,mixed>,
     *   window_start:string,
     *   window_end:string,
     *   observed_at:string
     * }> $windows
     * @phpstan-param array<array-key,array{baseline_snapshot:array<string,mixed>,ending_snapshot:array<string,mixed>,revenue_movements:array<string,mixed>,window_start:string,window_end:string,observed_at:string}> $windows
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   currency:string|null,
     *   points:list<array<string,mixed>>|null
     * }
     */
    public static function derive(
        bool $owner,
        array $windows,
        DateTimeImmutable $now,
        int $freshForSeconds = 86400,
    ): array {
        try {
            if (!$owner) {
                throw new DomainException('owner_required');
            }
            if ($freshForSeconds < 1 || $freshForSeconds > 31536000) {
                throw new DomainException('invalid_freshness_window');
            }
            if ($windows === [] || !array_is_list($windows)) {
                throw new DomainException('invalid_history_series');
            }

            $now = $now->setTimezone(new DateTimeZone('UTC'));
            $previousEnd = null;
            $currency = null;
            $points = [];

            foreach ($windows as $raw) {
                self::exactPointKeys($raw);

                $start = self::canonicalTime($raw['window_start']);
                $end = self::canonicalTime($raw['window_end']);
                $observedAt = self::canonicalTime($raw['observed_at']);

                if ($end <= $start) {
                    throw new DomainException('invalid_source_window');
                }
                if ($previousEnd instanceof DateTimeImmutable && $start < $previousEnd) {
                    throw new DomainException('overlapping_source_windows');
                }
                if ($observedAt < $end || $observedAt > $now) {
                    throw new DomainException('incoherent_freshness');
                }

                $metrics = SaasRetentionMetrics::derive(
                    $raw['baseline_snapshot'],
                    $raw['ending_snapshot'],
                    $raw['revenue_movements'],
                    $start,
                    $end,
                );
                if ($metrics['status'] !== 'valid') {
                    throw new DomainException('invalid_window_metrics');
                }

                $pointCurrency = $metrics['currency'];
                if (!is_string($pointCurrency)) {
                    throw new DomainException('invalid_window_metrics');
                }
                if ($currency !== null && $currency !== $pointCurrency) {
                    throw new DomainException('mixed_history_currencies');
                }
                $currency ??= $pointCurrency;

                $ageSeconds = $now->getTimestamp() - $observedAt->getTimestamp();
                $points[] = [
                    'source' => self::SOURCE,
                    'source_window' => [
                        'start' => $start->format('Y-m-d\\TH:i:s.u\\Z'),
                        'end' => $end->format('Y-m-d\\TH:i:s.u\\Z'),
                    ],
                    'freshness' => [
                        'observed_at' => $observedAt->format('Y-m-d\\TH:i:s.u\\Z'),
                        'age_seconds' => $ageSeconds,
                        'status' => $ageSeconds <= $freshForSeconds ? 'fresh' : 'stale',
                    ],
                    'metrics' => [
                        'currency' => $pointCurrency,
                        'baseline_mrr' => $metrics['baseline_mrr'],
                        'ending_mrr' => $metrics['ending_mrr'],
                        'arpa' => $metrics['arpa'],
                        'nrr' => $metrics['nrr'],
                    ],
                ];

                $previousEnd = $end;
            }

            return [
                'status' => 'valid',
                'reason' => null,
                'currency' => $currency,
                'points' => $points,
            ];
        } catch (Throwable $exception) {
            $reason = $exception instanceof DomainException
                ? $exception->getMessage()
                : 'invalid_or_ambiguous_history_evidence';

            return [
                'status' => 'unavailable',
                'reason' => $reason,
                'currency' => null,
                'points' => null,
            ];
        }
    }

    /** @param array<string,mixed> $raw */
    private static function exactPointKeys(array $raw): void
    {
        $expected = [
            'baseline_snapshot',
            'ending_snapshot',
            'observed_at',
            'revenue_movements',
            'window_end',
            'window_start',
        ];
        $actual = array_keys($raw);
        sort($actual);
        if ($actual !== $expected) {
            throw new DomainException('invalid_history_point');
        }
    }

    private static function canonicalTime(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1
        ) {
            throw new DomainException('invalid_history_time');
        }

        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new DomainException('invalid_history_time');
        }

        $canonical = $parsed->setTimezone(new DateTimeZone('UTC'));
        if ($canonical->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException('invalid_history_time');
        }

        return $canonical;
    }

}
