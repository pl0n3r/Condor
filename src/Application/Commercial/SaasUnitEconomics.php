<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class SaasUnitEconomics
{
    private const NULL_RESULT_KEYS = [
        'provenance',
        'gross_margin_percent',
        'support_cost',
        'tenant_ref',
        'contribution_margin_amount',
        'observed_at',
        'cogs',
        'currency',
        'gross_margin_amount',
        'source_window',
        'revenue_mrr',
        'contribution_margin_percent',
    ];

    /**
     * @param array<string,mixed> $costEvidence
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   tenant_ref:string|null,
     *   currency:string|null,
     *   source_window:array{start:string,end:string}|null,
     *   observed_at:string|null,
     *   revenue_mrr:int|null,
     *   cogs:int|null,
     *   support_cost:int|null,
     *   gross_margin_amount:int|null,
     *   gross_margin_percent:string|null,
     *   contribution_margin_amount:int|null,
     *   contribution_margin_percent:string|null,
     *   provenance:array{
     *     revenue:array{kind:string,subscription_ref:string,plan_version_ref:string},
     *     costs:list<array{category:string,source_ref:string}>
     *   }|null
     * }
     */
    public static function derive(Subscription $subscription, array $costEvidence): array
    {
        try {
            $costSnapshot = CostAttributionSnapshot::derive($costEvidence);
            if ($costSnapshot['status'] !== 'valid') {
                $costReason = $costSnapshot['reason'];
                throw new DomainException(
                    is_string($costReason) && $costReason !== ''
                        ? 'cost_' . $costReason
                        : 'invalid_cost_evidence',
                );
            }

            $tenantRef = $costSnapshot['tenant_ref'];
            $costCurrency = $costSnapshot['currency'];
            $sourceWindow = $costSnapshot['source_window'];
            $observedAtRaw = $costSnapshot['observed_at'];
            $costRows = $costSnapshot['costs'];
            $totalCost = $costSnapshot['total_cost'];

            if (
                !is_string($tenantRef)
                || !is_string($costCurrency)
                || preg_match('/^[A-Z]{3}$/D', $costCurrency) !== 1
                || !is_array($sourceWindow)
                || !is_string($observedAtRaw)
                || !is_array($costRows)
                || !is_int($totalCost)
                || $totalCost < 0
            ) {
                throw new DomainException('invalid_cost_evidence');
            }

            $subscriptionTenant = trim($subscription->tenantId());
            if ($subscriptionTenant === '' || $subscriptionTenant !== $tenantRef) {
                throw new DomainException('tenant_mismatch');
            }

            $windowStart = SubscriptionLifecycle::parseHistoricalTime($sourceWindow['start']);
            $windowEnd = SubscriptionLifecycle::parseHistoricalTime($sourceWindow['end']);
            $observedAt = SubscriptionLifecycle::parseHistoricalTime($observedAtRaw);
            if (
                $windowEnd <= $windowStart
                || $observedAt < $windowEnd
                || $windowStart->modify('+1 month')->format('U.u') !== $windowEnd->format('U.u')
            ) {
                throw new DomainException('invalid_revenue_window');
            }

            $planVersion = $subscription->planVersion();
            $revenueMrr = $planVersion->monthlyAmount();
            $revenueCurrency = strtoupper(trim($planVersion->currency()));
            if (
                $planVersion->quoteRequired()
                || $revenueMrr === null
                || $revenueMrr < 1
                || preg_match('/^[A-Z]{3}$/D', $revenueCurrency) !== 1
            ) {
                throw new DomainException('ambiguous_revenue');
            }
            if ($revenueCurrency !== $costCurrency) {
                throw new DomainException('mixed_revenue_cost_currency');
            }

            $effectiveUntil = $planVersion->effectiveUntil();
            if (
                $planVersion->effectiveFrom() > $windowStart
                || ($effectiveUntil instanceof DateTimeImmutable && $effectiveUntil < $windowEnd)
                || !self::activeForWindow($subscription, $windowStart, $windowEnd)
            ) {
                throw new DomainException('ambiguous_revenue_window');
            }

            $supportCost = null;
            /** @var list<array{category:string,source_ref:string}> $costProvenance */
            $costProvenance = [];
            foreach ($costRows as $row) {
                if ($row['currency'] !== $costCurrency) {
                    throw new DomainException('invalid_cost_evidence');
                }
                if ($row['category'] === 'support') {
                    $supportCost = $row['amount'];
                }
                $costProvenance[] = [
                    'category' => $row['category'],
                    'source_ref' => $row['source_ref'],
                ];
            }
            if ($supportCost === null || count($costRows) !== 6 || $supportCost > $totalCost) {
                throw new DomainException('incomplete_cost_coverage');
            }

            $cogs = $totalCost - $supportCost;
            $grossMargin = $revenueMrr - $cogs;
            $contributionMargin = $revenueMrr - $totalCost;

            return [
                'status' => 'valid',
                'reason' => null,
                'tenant_ref' => $tenantRef,
                'currency' => $revenueCurrency,
                'source_window' => $sourceWindow,
                'observed_at' => $observedAtRaw,
                'revenue_mrr' => $revenueMrr,
                'cogs' => $cogs,
                'support_cost' => $supportCost,
                'gross_margin_amount' => $grossMargin,
                'gross_margin_percent' => self::exactPercent($grossMargin, $revenueMrr),
                'contribution_margin_amount' => $contributionMargin,
                'contribution_margin_percent' => self::exactPercent($contributionMargin, $revenueMrr),
                'provenance' => [
                    'revenue' => [
                        'kind' => 'subscription_plan_version',
                        'subscription_ref' => $subscription->id(),
                        'plan_version_ref' => $planVersion->id(),
                    ],
                    'costs' => $costProvenance,
                ],
            ];
        } catch (Throwable $exception) {
            return self::unavailable(
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'invalid_or_ambiguous_unit_economics_evidence',
            );
        }
    }

    /**
     * Return an exact reduced rational in percent units.
     *
     * Examples: 50% => "50", one third => "100/3", negative one third => "-100/3".
     * No rounding is performed; negative values keep their sign before reduction.
     */
    private static function exactPercent(int $margin, int $revenue): string
    {
        if ($revenue < 1) {
            throw new DomainException('ambiguous_revenue');
        }
        if ($margin === 0) {
            return '0';
        }

        $negative = $margin < 0;
        $absoluteMargin = abs($margin);
        $common = self::greatestCommonDivisor($absoluteMargin, $revenue);
        $numeratorBase = intdiv($absoluteMargin, $common);
        $denominator = intdiv($revenue, $common);

        $scaleCommon = self::greatestCommonDivisor(100, $denominator);
        $scale = intdiv(100, $scaleCommon);
        $denominator = intdiv($denominator, $scaleCommon);
        $numerator = self::multiplyDecimalBySmall($numeratorBase, $scale);

        if ($negative) {
            $numerator = '-' . $numerator;
        }

        return $denominator === 1 ? $numerator : $numerator . '/' . $denominator;
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            $remainder = $left % $right;
            $left = $right;
            $right = $remainder;
        }

        return $left;
    }

    /**
     * Multiply a non-negative integer by a factor <= 100 using decimal digits,
     * so the intermediate product never depends on platform integer headroom.
     */
    private static function multiplyDecimalBySmall(int $value, int $factor): string
    {
        if ($value < 0 || $factor < 0 || $factor > 100) {
            throw new DomainException('invalid_percentage_scale');
        }

        $digits = strrev((string) $value);
        $carry = 0;
        $result = '';

        for ($index = 0, $length = strlen($digits); $index < $length; $index++) {
            $product = ((int) $digits[$index] * $factor) + $carry;
            $result .= (string) ($product % 10);
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result .= (string) ($carry % 10);
            $carry = intdiv($carry, 10);
        }

        return strrev($result);
    }

    private static function activeForWindow(
        Subscription $subscription,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): bool {
        $stateAtStart = null;
        $previous = null;

        foreach ($subscription->history() as $entry) {
            $state = SubscriptionState::tryFrom($entry['state']);
            if (!$state instanceof SubscriptionState) {
                throw new DomainException('ambiguous_revenue_window');
            }

            $at = SubscriptionLifecycle::parseHistoricalTime($entry['at']);
            if ($previous instanceof DateTimeImmutable && $at <= $previous) {
                throw new DomainException('ambiguous_revenue_window');
            }
            $previous = $at;

            if ($at <= $windowStart) {
                $stateAtStart = $state;
                continue;
            }
            if ($at >= $windowEnd) {
                break;
            }
            if ($state !== SubscriptionState::Active) {
                return false;
            }
        }

        return $stateAtStart === SubscriptionState::Active;
    }

    /**
     * @return array{
     *   status:'unavailable',
     *   reason:string,
     *   tenant_ref:null,
     *   currency:null,
     *   source_window:null,
     *   observed_at:null,
     *   revenue_mrr:null,
     *   cogs:null,
     *   support_cost:null,
     *   gross_margin_amount:null,
     *   gross_margin_percent:null,
     *   contribution_margin_amount:null,
     *   contribution_margin_percent:null,
     *   provenance:null
     * }
     */
    private static function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason] + array_fill_keys(self::NULL_RESULT_KEYS, null);
    }
}
