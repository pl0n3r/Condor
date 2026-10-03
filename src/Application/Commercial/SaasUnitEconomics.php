<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DateTimeZone;
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
            if (($costSnapshot['status'] ?? null) !== 'valid') {
                $costReason = $costSnapshot['reason'] ?? null;
                throw new DomainException(
                    is_string($costReason) && $costReason !== ''
                        ? 'cost_' . $costReason
                        : 'invalid_cost_evidence',
                );
            }

            $tenantRef = $costSnapshot['tenant_ref'] ?? null;
            $costCurrency = $costSnapshot['currency'] ?? null;
            $sourceWindow = $costSnapshot['source_window'] ?? null;
            $observedAtRaw = $costSnapshot['observed_at'] ?? null;
            $costRows = $costSnapshot['costs'] ?? null;
            $totalCost = $costSnapshot['total_cost'] ?? null;

            if (
                !is_string($tenantRef)
                || !is_string($costCurrency)
                || preg_match('/^[A-Z]{3}$/D', $costCurrency) !== 1
                || !is_array($sourceWindow)
                || array_keys($sourceWindow) !== ['start', 'end']
                || !is_string($observedAtRaw)
                || !is_array($costRows)
                || !array_is_list($costRows)
                || !is_int($totalCost)
                || $totalCost < 0
            ) {
                throw new DomainException('invalid_cost_evidence');
            }

            $subscriptionTenant = trim($subscription->tenantId());
            if ($subscriptionTenant === '' || $subscriptionTenant !== $tenantRef) {
                throw new DomainException('tenant_mismatch');
            }

            $windowStart = self::canonicalTime($sourceWindow['start']);
            $windowEnd = self::canonicalTime($sourceWindow['end']);
            $observedAt = self::canonicalTime($observedAtRaw);
            if (
                $windowEnd <= $windowStart
                || $observedAt < $windowEnd
                || self::instant($windowStart->modify('+1 month')) !== self::instant($windowEnd)
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
                if (!is_array($row)) {
                    throw new DomainException('invalid_cost_evidence');
                }
                $category = $row['category'] ?? null;
                $amount = $row['amount'] ?? null;
                $currency = $row['currency'] ?? null;
                $sourceRef = $row['source_ref'] ?? null;
                if (
                    !is_string($category)
                    || !is_int($amount)
                    || $amount < 0
                    || $currency !== $costCurrency
                    || !is_string($sourceRef)
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
                'source_window' => [
                    'start' => self::formatTime($windowStart),
                    'end' => self::formatTime($windowEnd),
                ],
                'observed_at' => self::formatTime($observedAt),
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
            if (!is_array($entry)) {
                throw new DomainException('ambiguous_revenue_window');
            }
            $keys = array_keys($entry);
            sort($keys);
            if ($keys !== ['at', 'state']) {
                throw new DomainException('ambiguous_revenue_window');
            }

            $stateValue = $entry['state'] ?? null;
            if (!is_string($stateValue)) {
                throw new DomainException('ambiguous_revenue_window');
            }
            $state = SubscriptionState::tryFrom($stateValue);
            if (!$state instanceof SubscriptionState) {
                throw new DomainException('ambiguous_revenue_window');
            }

            $at = SubscriptionLifecycle::parseHistoricalTime($entry['at'] ?? null);
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

    private static function canonicalTime(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1
        ) {
            throw new DomainException('invalid_source_time');
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || self::formatTime($parsed) !== $value
        ) {
            throw new DomainException('invalid_source_time');
        }

        return $parsed;
    }

    private static function safeAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new DomainException('cost_overflow');
        }

        return $left + $right;
    }

    private static function instant(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('U.u');
    }

    private static function formatTime(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
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
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'tenant_ref' => null,
            'currency' => null,
            'source_window' => null,
            'observed_at' => null,
            'revenue_mrr' => null,
            'cogs' => null,
            'support_cost' => null,
            'gross_margin_amount' => null,
            'gross_margin_percent' => null,
            'contribution_margin_amount' => null,
            'contribution_margin_percent' => null,
            'provenance' => null,
        ];
    }
}
