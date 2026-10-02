<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

/**
 * ARPA/NRR sobre snapshots y movimientos canónicos.
 *
 * Ventana: [inicio, fin). ARPA usa unidades monetarias menores por cliente
 * activo; NRR usa porcentaje (100 = retención neta completa).
 */
final class SaasRetentionMetrics
{
    /**
     * @param array<string,mixed> $baselineSnapshot salida de PlatformCommercialMetrics
     * @param array<string,mixed> $endingSnapshot salida de PlatformCommercialMetrics
     * @param array<string,mixed> $revenueMovements salida de SaasRevenueMovements
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   currency:string|null,
     *   baseline_mrr:int|null,
     *   ending_mrr:int|null,
     *   arpa:float|null,
     *   nrr:float|null
     * }
     */
    public static function derive(
        array $baselineSnapshot,
        array $endingSnapshot,
        array $revenueMovements,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        try {
            if ($windowEnd <= $windowStart) {
                throw new DomainException('invalid_window');
            }

            $baseline = self::snapshot($baselineSnapshot, true);
            $ending = self::snapshot($endingSnapshot, false, $baseline['currency']);
            if ($baseline['currency'] !== $ending['currency']) {
                throw new DomainException('mixed_snapshot_currencies');
            }

            if (
                ($revenueMovements['status'] ?? null) !== 'valid'
                || ($revenueMovements['currency'] ?? null) !== $baseline['currency']
            ) {
                throw new DomainException('invalid_movement_evidence');
            }
            $movements = $revenueMovements['movements'] ?? null;
            if (!is_array($movements) || !array_is_list($movements)) {
                throw new DomainException('invalid_movement_evidence');
            }

            $endingDelta = 0;
            $retainedDelta = 0;
            foreach ($movements as $movement) {
                if (!is_array($movement)) {
                    throw new DomainException('invalid_movement_evidence');
                }

                $type = $movement['type'] ?? null;
                $amount = $movement['amount'] ?? null;
                $delta = $movement['mrr_delta'] ?? null;
                $occurredAt = $movement['occurred_at'] ?? null;
                $recognition = $movement['recognition'] ?? null;
                if (
                    !is_string($type)
                    || !in_array($type, ['new', 'expansion', 'contraction', 'churn'], true)
                    || !is_int($amount)
                    || $amount < 1
                    || !is_int($delta)
                    || !is_string($occurredAt)
                    || !is_string($recognition)
                    || !in_array($recognition, ['effective', 'scheduled'], true)
                ) {
                    throw new DomainException('invalid_movement_evidence');
                }

                $expectedDelta = in_array($type, ['contraction', 'churn'], true) ? -$amount : $amount;
                if ($delta !== $expectedDelta) {
                    throw new DomainException('invalid_movement_evidence');
                }

                $at = self::canonicalTime($occurredAt);
                if ($recognition !== 'effective' || $at < $windowStart || $at >= $windowEnd) {
                    continue;
                }

                $endingDelta = self::safeAdd($endingDelta, $delta);
                if ($type !== 'new') {
                    $retainedDelta = self::safeAdd($retainedDelta, $delta);
                }
            }

            $expectedEnding = self::safeAdd($baseline['mrr'], $endingDelta);
            if ($expectedEnding !== $ending['mrr']) {
                throw new DomainException('incoherent_window_evidence');
            }

            $retainedMrr = self::safeAdd($baseline['mrr'], $retainedDelta);
            if ($retainedMrr < 0) {
                throw new DomainException('invalid_retained_mrr');
            }

            return [
                'status' => 'valid',
                'reason' => null,
                'currency' => $baseline['currency'],
                'baseline_mrr' => $baseline['mrr'],
                'ending_mrr' => $ending['mrr'],
                'arpa' => $ending['active_customers'] === 0
                    ? null
                    : $ending['mrr'] / $ending['active_customers'],
                'nrr' => ($retainedMrr / $baseline['mrr']) * 100,
            ];
        } catch (DomainException $exception) {
            return self::unavailable($exception->getMessage());
        } catch (Throwable) {
            return self::unavailable('invalid_or_ambiguous_retention_evidence');
        }
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{currency:string,mrr:int,active_customers:int}
     */
    private static function snapshot(array $raw, bool $baseline, ?string $fallbackCurrency = null): array
    {
        $reason = $baseline ? 'missing_baseline' : 'invalid_ending_snapshot';
        $currency = $raw['currency'] ?? null;
        $mrr = $raw['mrr'] ?? null;
        $activeCustomers = $raw['active_customers'] ?? null;
        if (!$baseline && $mrr === 0 && $activeCustomers === 0 && $currency === null) {
            $currency = $fallbackCurrency;
        }
        if (
            ($raw['status'] ?? null) !== 'valid'
            || !is_string($currency)
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || !is_int($mrr)
            || $mrr < ($baseline ? 1 : 0)
            || !is_int($activeCustomers)
            || $activeCustomers < ($baseline ? 1 : 0)
            || (($mrr === 0) !== ($activeCustomers === 0))
        ) {
            throw new DomainException($reason);
        }

        return [
            'currency' => $currency,
            'mrr' => $mrr,
            'active_customers' => $activeCustomers,
        ];
    }

    private static function canonicalTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s.u\\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException('invalid_movement_time');
        }

        return $parsed;
    }

    private static function safeAdd(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new DomainException('revenue_overflow');
        }

        return $left + $right;
    }

    /** @return array{status:'unavailable',reason:string,currency:null,baseline_mrr:null,ending_mrr:null,arpa:null,nrr:null} */
    private static function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'currency' => null,
            'baseline_mrr' => null,
            'ending_mrr' => null,
            'arpa' => null,
            'nrr' => null,
        ];
    }
}
