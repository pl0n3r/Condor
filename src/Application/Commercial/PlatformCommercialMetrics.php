<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionState;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class PlatformCommercialMetrics
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   currency:string|null,
     *   mrr:int|null,
     *   arr:int|null,
     *   active_customers:int|null,
     *   trials:int|null
     * }
     */
    public function snapshot(): array
    {
        $subscriptions = $this->entityManager
            ->getRepository(Subscription::class)
            ->findAll();

        return self::derive($subscriptions);
    }

    /**
     * @param list<Subscription> $subscriptions
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   currency:string|null,
     *   mrr:int|null,
     *   arr:int|null,
     *   active_customers:int|null,
     *   trials:int|null
     * }
     */
    public static function derive(array $subscriptions): array
    {
        $seenTenants = [];
        $activeCustomers = 0;
        $trials = 0;
        $mrr = 0;
        $currency = null;

        try {
            foreach ($subscriptions as $subscription) {
                $tenantId = trim($subscription->tenantId());
                if ($tenantId === '' || isset($seenTenants[$tenantId])) {
                    return self::unavailable('invalid_subscription_identity');
                }
                $seenTenants[$tenantId] = true;

                $state = $subscription->state();
                if ($state === SubscriptionState::Trialing) {
                    $lifecycle = $subscription->toLifecycle();
                    $startedAt = $lifecycle->trialStartedAt();
                    $endsAt = $lifecycle->trialEndsAt();
                    if ($startedAt === null || $endsAt === null || $endsAt <= $startedAt) {
                        return self::unavailable('invalid_trial_window');
                    }
                    ++$trials;
                    continue;
                }

                if ($state !== SubscriptionState::Active) {
                    continue;
                }

                $version = $subscription->planVersion();
                $monthlyAmount = $version->monthlyAmount();
                $subscriptionCurrency = strtoupper(trim($version->currency()));
                if (
                    $version->quoteRequired()
                    || $monthlyAmount === null
                    || $monthlyAmount < 1
                    || preg_match('/^[A-Z]{3}$/D', $subscriptionCurrency) !== 1
                ) {
                    return self::unavailable('incomplete_active_pricing');
                }

                if ($currency !== null && $currency !== $subscriptionCurrency) {
                    return self::unavailable('mixed_active_currencies');
                }
                $currency ??= $subscriptionCurrency;

                if ($mrr > PHP_INT_MAX - $monthlyAmount) {
                    return self::unavailable('revenue_overflow');
                }
                $mrr += $monthlyAmount;
                ++$activeCustomers;
            }
        } catch (Throwable) {
            return self::unavailable('invalid_commercial_data');
        }

        if ($mrr > intdiv(PHP_INT_MAX, 12)) {
            return self::unavailable('revenue_overflow');
        }

        return [
            'status' => 'valid',
            'reason' => null,
            'currency' => $currency,
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'active_customers' => $activeCustomers,
            'trials' => $trials,
        ];
    }

    /**
     * @return array{
     *   status:'unavailable',
     *   reason:string,
     *   currency:null,
     *   mrr:null,
     *   arr:null,
     *   active_customers:null,
     *   trials:null
     * }
     */
    private static function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'currency' => null,
            'mrr' => null,
            'arr' => null,
            'active_customers' => null,
            'trials' => null,
        ];
    }
}
