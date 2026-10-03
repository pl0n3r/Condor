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
    private const COGS_CATEGORIES = [
        'ai_compute',
        'infrastructure',
        'integration',
        'messaging',
        'storage',
    ];

    private const EMPTY_METRICS = [
        'provenance' => null,
        'contribution_margin_percent' => null,
        'gross_margin_percent' => null,
        'contribution_margin_amount' => null,
        'gross_margin_amount' => null,
        'support_cost' => null,
        'cogs' => null,
        'revenue_mrr' => null,
        'observed_at' => null,
        'source_window' => null,
        'currency' => null,
        'tenant_ref' => null,
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
     *   gross_margin_percent:float|null,
     *   contribution_margin_amount:int|null,
     *   contribution_margin_percent:float|null,
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

            /** @var array<string,int> $amountByCategory */
            $amountByCategory = [];
            /** @var list<array{category:string,source_ref:string}> $costProvenance */
            $costProvenance = [];
            foreach ($costRows as $row) {
                $category = $row['category'];
                $amount = $row['amount'];
                $currency = $row['currency'];
                $sourceRef = $row['source_ref'];
                if (
                    $amount < 0
                    || $currency !== $costCurrency
                    || isset($amountByCategory[$category])
                ) {
                    throw new DomainException('invalid_cost_evidence');
                }

                $amountByCategory[$category] = $amount;
                $costProvenance[] = [
                    'category' => $category,
                    'source_ref' => $sourceRef,
                ];
            }

            $cogs = 0;
            foreach (self::COGS_CATEGORIES as $category) {
                if (!isset($amountByCategory[$category])) {
                    throw new DomainException('incomplete_cost_coverage');
                }
                $cogs = self::safeAdd($cogs, $amountByCategory[$category]);
            }
            if (!isset($amountByCategory['support']) || count($amountByCategory) !== 6) {
                throw new DomainException('incomplete_cost_coverage');
            }

            $supportCost = $amountByCategory['support'];
            $computedTotalCost = self::safeAdd($cogs, $supportCost);
            if ($computedTotalCost !== $totalCost) {
                throw new DomainException('invalid_cost_evidence');
            }

            $grossMargin = $revenueMrr - $cogs;
            $contributionMargin = $revenueMrr - $computedTotalCost;

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
                'gross_margin_percent' => ($grossMargin / $revenueMrr) * 100,
                'contribution_margin_amount' => $contributionMargin,
                'contribution_margin_percent' => ($contributionMargin / $revenueMrr) * 100,
                'provenance' => [
                    'revenue' => [
                        'kind' => 'subscription_plan_version',
                        'subscription_ref' => $subscription->id(),
                        'plan_version_ref' => $planVersion->id(),
                    ],
                    'costs' => $costProvenance,
                ],
            ];
        } catch (DomainException $exception) {
            return self::unavailable($exception->getMessage());
        } catch (Throwable) {
            return self::unavailable('invalid_or_ambiguous_unit_economics_evidence');
        }
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
        return ['status' => 'unavailable', 'reason' => $reason] + self::EMPTY_METRICS;
    }
}
