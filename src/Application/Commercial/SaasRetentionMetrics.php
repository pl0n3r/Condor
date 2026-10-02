<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

/**
 * Combina snapshots de MRR canónicos con movimientos de revenue ya derivados.
 *
 * La ventana usa semántica [inicio, fin): inicio incluido y fin excluido.
 * ARPA se expresa en unidades monetarias menores por cliente activo.
 * NRR se expresa como porcentaje (100.0 = retención neta completa).
 *
 * @phpstan-type NormalizedSnapshot array{
 *   currency:string,
 *   mrr:int,
 *   active_customers:int
 * }
 * @phpstan-type Movement array{
 *   tenant_id:string,
 *   type:'new'|'expansion'|'contraction'|'churn',
 *   amount:int,
 *   mrr_delta:int,
 *   occurred_at:string,
 *   recognition:'effective'|'scheduled'
 * }
 */
final class SaasRetentionMetrics
{
    /**
     * @param array<string,mixed> $baselineSnapshot salida canónica de PlatformCommercialMetrics
     * @param array<string,mixed> $endingSnapshot salida canónica de PlatformCommercialMetrics
     * @param array<string,mixed> $revenueMovements salida canónica de SaasRevenueMovements
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
            return self::deriveCanonical(
                $baselineSnapshot,
                $endingSnapshot,
                $revenueMovements,
                $windowStart,
                $windowEnd,
            );
        } catch (DomainException $exception) {
            return self::unavailable($exception->getMessage());
        } catch (Throwable) {
            return self::unavailable('invalid_or_ambiguous_retention_evidence');
        }
    }

    /**
     * @param array<string,mixed> $baselineSnapshot
     * @param array<string,mixed> $endingSnapshot
     * @param array<string,mixed> $revenueMovements
     * @return array{
     *   status:'valid',
     *   reason:null,
     *   currency:string,
     *   baseline_mrr:int,
     *   ending_mrr:int,
     *   arpa:float,
     *   nrr:float
     * }
     */
    private static function deriveCanonical(
        array $baselineSnapshot,
        array $endingSnapshot,
        array $revenueMovements,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        if ($windowEnd <= $windowStart) {
            throw new DomainException('invalid_window');
        }

        $baseline = self::normalizeSnapshot($baselineSnapshot, 'baseline');
        $ending = self::normalizeSnapshot($endingSnapshot, 'ending');

        if ($baseline['mrr'] < 1 || $baseline['active_customers'] < 1) {
            throw new DomainException('missing_baseline');
        }
        if ($ending['mrr'] < 1 || $ending['active_customers'] < 1) {
            throw new DomainException('undefined_ending_arpa');
        }
        if ($baseline['currency'] !== $ending['currency']) {
            throw new DomainException('mixed_snapshot_currencies');
        }

        $movements = self::normalizeMovements($revenueMovements, $baseline['currency']);
        $new = 0;
        $expansion = 0;
        $contraction = 0;
        $churn = 0;

        foreach ($movements as $movement) {
            if ($movement['recognition'] !== 'effective') {
                continue;
            }

            $occurredAt = self::parseCanonicalTime($movement['occurred_at']);
            if ($occurredAt < $windowStart || $occurredAt >= $windowEnd) {
                continue;
            }

            $amount = $movement['amount'];
            if ($movement['type'] === 'new') {
                $new = self::safeAdd($new, $amount);
            } elseif ($movement['type'] === 'expansion') {
                $expansion = self::safeAdd($expansion, $amount);
            } elseif ($movement['type'] === 'contraction') {
                $contraction = self::safeAdd($contraction, $amount);
            } else {
                $churn = self::safeAdd($churn, $amount);
            }
        }

        $expectedEnding = self::safeAdd($baseline['mrr'], $new);
        $expectedEnding = self::safeAdd($expectedEnding, $expansion);
        $expectedEnding = self::safeAdd($expectedEnding, -$contraction);
        $expectedEnding = self::safeAdd($expectedEnding, -$churn);
        if ($expectedEnding !== $ending['mrr']) {
            throw new DomainException('incoherent_window_evidence');
        }

        $retainedMrr = self::safeAdd($baseline['mrr'], $expansion);
        $retainedMrr = self::safeAdd($retainedMrr, -$contraction);
        $retainedMrr = self::safeAdd($retainedMrr, -$churn);
        if ($retainedMrr < 0) {
            throw new DomainException('invalid_retained_mrr');
        }

        return [
            'status' => 'valid',
            'reason' => null,
            'currency' => $baseline['currency'],
            'baseline_mrr' => $baseline['mrr'],
            'ending_mrr' => $ending['mrr'],
            'arpa' => $ending['mrr'] / $ending['active_customers'],
            'nrr' => ($retainedMrr / $baseline['mrr']) * 100,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return NormalizedSnapshot
     */
    private static function normalizeSnapshot(array $snapshot, string $role): array
    {
        if (($snapshot['status'] ?? null) !== 'valid') {
            throw new DomainException($role === 'baseline' ? 'missing_baseline' : 'invalid_ending_snapshot');
        }

        $currency = $snapshot['currency'] ?? null;
        $mrr = $snapshot['mrr'] ?? null;
        $activeCustomers = $snapshot['active_customers'] ?? null;
        if (
            !is_string($currency)
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || !is_int($mrr)
            || $mrr < 0
            || !is_int($activeCustomers)
            || $activeCustomers < 0
        ) {
            throw new DomainException($role === 'baseline' ? 'missing_baseline' : 'invalid_ending_snapshot');
        }

        return [
            'currency' => $currency,
            'mrr' => $mrr,
            'active_customers' => $activeCustomers,
        ];
    }

    /**
     * @param array<string,mixed> $evidence
     * @return list<Movement>
     */
    private static function normalizeMovements(array $evidence, string $currency): array
    {
        if (
            ($evidence['status'] ?? null) !== 'valid'
            || ($evidence['currency'] ?? null) !== $currency
        ) {
            throw new DomainException('invalid_movement_evidence');
        }

        $rawMovements = $evidence['movements'] ?? null;
        if (!is_array($rawMovements) || !array_is_list($rawMovements)) {
            throw new DomainException('invalid_movement_evidence');
        }

        /** @var list<Movement> $movements */
        $movements = [];
        foreach ($rawMovements as $raw) {
            if (!is_array($raw)) {
                throw new DomainException('invalid_movement_evidence');
            }

            $tenantId = $raw['tenant_id'] ?? null;
            $type = $raw['type'] ?? null;
            $amount = $raw['amount'] ?? null;
            $delta = $raw['mrr_delta'] ?? null;
            $occurredAt = $raw['occurred_at'] ?? null;
            $recognition = $raw['recognition'] ?? null;

            if (
                !is_string($tenantId)
                || trim($tenantId) === ''
                || !is_string($type)
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

            /** @var 'new'|'expansion'|'contraction'|'churn' $type */
            /** @var 'effective'|'scheduled' $recognition */
            $movements[] = [
                'tenant_id' => $tenantId,
                'type' => $type,
                'amount' => $amount,
                'mrr_delta' => $delta,
                'occurred_at' => $occurredAt,
                'recognition' => $recognition,
            ];
        }

        return $movements;
    }

    private static function parseCanonicalTime(string $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $value, $timezone);
        if (
            !$parsed instanceof DateTimeImmutable
            || $parsed->format('Y-m-d\\TH:i:s.u\\Z') !== $value
        ) {
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

    /**
     * @return array{
     *   status:'unavailable',
     *   reason:string,
     *   currency:null,
     *   baseline_mrr:null,
     *   ending_mrr:null,
     *   arpa:null,
     *   nrr:null
     * }
     */
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
