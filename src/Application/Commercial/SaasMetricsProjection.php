<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionChange;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Read-only, synthetic SaaS dashboard. All monetary amounts use the canonical
 * catalog's integer minor units (COP cents); ratios are reduced fractions,
 * never imprecise floats or a second price table.
 */
final class SaasMetricsProjection
{
    /**
     * @param list<Subscription> $baselineSubscriptions
     * @param list<Subscription> $endingSubscriptions
     * @param list<Subscription> $historySubscriptions
     * @param list<SubscriptionChange> $changes
     * @param list<array<string,mixed>> $audits
     * @return array<string,mixed>
     */
    public static function fromSubscriptions(
        bool $owner,
        array $baselineSubscriptions,
        array $endingSubscriptions,
        array $historySubscriptions,
        array $changes,
        array $audits,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        try {
            $tenantsBySource = [];
            foreach ([$baselineSubscriptions, $endingSubscriptions, $historySubscriptions] as $set) {
                $seen = [];
                foreach ($set as $subscription) {
                    $tenant = $subscription->tenantId();
                    self::syntheticTenant($tenant);
                    if (isset($seen[$tenant])) {
                        throw new DomainException('duplicate_tenant');
                    }
                    $seen[$tenant] = true;
                }
                $tenantsBySource[] = $seen;
            }
            foreach ($tenantsBySource[2] as $tenant => $present) {
                if (!isset($tenantsBySource[0][$tenant]) && !isset($tenantsBySource[1][$tenant])) {
                    throw new DomainException('invalid_tenant_scope');
                }
            }
            foreach ($changes as $change) {
                self::syntheticTenant($change->tenantId());
                if (!isset($tenantsBySource[2][$change->tenantId()])) {
                    throw new DomainException('change_without_subscription');
                }
            }

            $baseline = PlatformCommercialMetrics::derive($baselineSubscriptions);
            $ending = PlatformCommercialMetrics::derive($endingSubscriptions);
            $movements = SaasRevenueMovements::derive($historySubscriptions, $changes);

            return self::derive($owner, $baseline, $ending, $movements, $audits, $start, $end);
        } catch (DomainException $exception) {
            return self::unavailable($exception->getMessage());
        } catch (Throwable) {
            return self::unavailable('invalid_commercial_input');
        }
    }

    /**
     * Compose only verified projections from the existing commercial sources.
     * Every movement (including scheduled ones) needs an authorized synthetic
     * audit receipt; missing/extra/duplicate receipts fail closed.
     *
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $ending
     * @param array<string,mixed> $revenue
     * @param list<array<string,mixed>> $audits
     * @return array<string,mixed>
     */
    public static function derive(
        bool $owner,
        array $baseline,
        array $ending,
        array $revenue,
        array $audits,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        try {
            if (!$owner) {
                throw new DomainException('owner_required');
            }
            if ($end <= $start) {
                throw new DomainException('invalid_window');
            }
            $validated = SaasRetentionMetrics::derive($baseline, $ending, $revenue, $start, $end);
            if ($validated['status'] !== 'valid') {
                throw new DomainException('invalid_source_projections');
            }
            $endingMrr = $validated['ending_mrr'];
            $baselineMrr = $validated['baseline_mrr'];
            if (!is_int($endingMrr) || !is_int($baselineMrr)) {
                throw new DomainException('invalid_source_projections');
            }

            if (
                ($ending['status'] ?? null) !== 'valid'
                || ($baseline['status'] ?? null) !== 'valid'
                || !is_int($ending['arr'] ?? null)
                || !is_int($ending['trials'] ?? null)
                || $ending['trials'] < 0
                || !is_int($ending['active_customers'] ?? null)
                || $ending['arr'] !== self::checkedMultiply($endingMrr, 12)
                || ($baseline['currency'] ?? null) !== $validated['currency']
            ) {
                throw new DomainException('invalid_source_projections');
            }

            $movements = $revenue['movements'];
            if (!is_array($movements) || !array_is_list($movements)) {
                throw new DomainException('invalid_audit_evidence');
            }
            $movementsByKey = [];
            $totals = ['new' => 0, 'expansion' => 0, 'contraction' => 0, 'churn' => 0];
            foreach ($movements as $movement) {
                if (!is_array($movement)) {
                    throw new DomainException('invalid_movement_evidence');
                }
                $tenant = $movement['tenant_id'] ?? null;
                self::syntheticTenant($tenant);
                $type = $movement['type'] ?? null;
                $at = $movement['occurred_at'] ?? null;
                $recognition = $movement['recognition'] ?? null;
                $amount = $movement['amount'] ?? null;
                $delta = $movement['mrr_delta'] ?? null;
                if (
                    !is_string($type) || !array_key_exists($type, $totals)
                    || !is_string($at) || !is_string($recognition)
                    || !in_array($recognition, ['effective', 'scheduled'], true)
                    || !is_int($amount) || $amount < 1 || !is_int($delta)
                    || $delta !== (in_array($type, ['contraction', 'churn'], true) ? -$amount : $amount)
                ) {
                    throw new DomainException('invalid_movement_evidence');
                }
                $key = json_encode([$tenant, $type, $at, $recognition], JSON_THROW_ON_ERROR);
                if (isset($movementsByKey[$key])) {
                    throw new DomainException('duplicate_movement');
                }
                $movementsByKey[$key] = true;
                $time = self::utcTime($at);
                if ($recognition === 'effective' && $time >= $start && $time < $end) {
                    $totals[$type] = self::checkedAdd($totals[$type], $amount);
                }
            }

            $verified = [];
            foreach ($audits as $audit) {
                $fields = array_keys($audit);
                sort($fields);
                if ($fields !== ['actor_role', 'audit_id', 'occurred_at', 'recognition', 'tenant_id', 'type']) {
                    throw new DomainException('invalid_audit_evidence');
                }
                self::syntheticTenant($audit['tenant_id']);
                if (
                    $audit['actor_role'] !== 'saas_owner'
                    || !is_string($audit['audit_id'])
                    || preg_match('/^synthetic-audit-[a-z0-9-]+$/D', $audit['audit_id']) !== 1
                    || !is_string($audit['type'])
                    || !is_string($audit['occurred_at'])
                    || !is_string($audit['recognition'])
                ) {
                    throw new DomainException('unauthorized_or_real_audit');
                }
                $key = json_encode([
                    $audit['tenant_id'], $audit['type'],
                    $audit['occurred_at'], $audit['recognition'],
                ], JSON_THROW_ON_ERROR);
                if (!isset($movementsByKey[$key]) || isset($verified[$key])) {
                    throw new DomainException('invalid_audit_evidence');
                }
                $verified[$key] = true;
            }
            if (count($verified) !== count($movementsByKey)) {
                throw new DomainException('missing_audit');
            }

            $retained = self::checkedAdd(
                self::checkedAdd($baselineMrr, $totals['expansion']),
                -self::checkedAdd($totals['contraction'], $totals['churn']),
            );
            if ($retained < 0) {
                throw new DomainException('invalid_retained_mrr');
            }

            $active = $ending['active_customers'];
            return [
                'status' => 'valid',
                'reason' => null,
                'currency' => $validated['currency'],
                'mrr_minor' => $endingMrr,
                'arr_minor' => $ending['arr'],
                'active_customers' => $active,
                'trials' => $ending['trials'],
                'arpa_minor_exact' => $active === 0 ? null : self::fraction($endingMrr, $active),
                'new_mrr_minor' => $totals['new'],
                'expansion_mrr_minor' => $totals['expansion'],
                'contraction_mrr_minor' => $totals['contraction'],
                'churned_mrr_minor' => $totals['churn'],
                'nrr_percent_exact' => self::percent($retained, $baselineMrr),
                'audit_count' => count($verified),
                'source' => [
                    'snapshot' => PlatformCommercialMetrics::class,
                    'movements' => SaasRevenueMovements::class,
                    'retention' => SaasRetentionMetrics::class,
                ],
            ];
        } catch (DomainException $exception) {
            return self::unavailable($exception->getMessage());
        } catch (Throwable) {
            return self::unavailable('invalid_or_ambiguous_metrics');
        }
    }

    private static function syntheticTenant(mixed $tenant): void
    {
        if (!is_string($tenant) || preg_match('/^synthetic-tenant-[a-z0-9-]+$/D', $tenant) !== 1) {
            throw new DomainException('real_or_invalid_tenant');
        }
    }

    private static function utcTime(string $at): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $at, new \DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i:s.u\Z') !== $at) {
            throw new DomainException('invalid_movement_time');
        }
        return $date;
    }

    private static function checkedAdd(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new DomainException('metrics_overflow');
        }
        return $a + $b;
    }

    private static function checkedMultiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($b !== 0 && $a > intdiv(PHP_INT_MAX, $b))) {
            throw new DomainException('metrics_overflow');
        }
        return $a * $b;
    }

    /** @return array{numerator:int,denominator:int} */
    private static function fraction(int $numerator, int $denominator): array
    {
        if ($numerator < 0 || $denominator < 1) {
            throw new DomainException('invalid_ratio');
        }
        $a = $numerator;
        $b = $denominator;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $gcd = max(1, $a);
        return ['numerator' => intdiv($numerator, $gcd), 'denominator' => intdiv($denominator, $gcd)];
    }

    /** @return array{numerator:int,denominator:int} */
    private static function percent(int $retained, int $baseline): array
    {
        $f = self::fraction($retained, $baseline);
        return self::fraction(self::checkedMultiply($f['numerator'], 100), $f['denominator']);
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable', 'reason' => $reason,
            'currency' => null, 'mrr_minor' => null, 'arr_minor' => null,
            'active_customers' => null, 'trials' => null, 'arpa_minor_exact' => null,
            'new_mrr_minor' => null, 'expansion_mrr_minor' => null,
            'contraction_mrr_minor' => null, 'churned_mrr_minor' => null,
            'nrr_percent_exact' => null, 'audit_count' => null, 'source' => null,
        ];
    }
}
