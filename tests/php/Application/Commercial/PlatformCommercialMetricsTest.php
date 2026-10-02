<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\PlatformCommercialMetrics;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PlatformCommercialMetricsTest extends TestCase
{
    public function testMrrArrActiveCustomersAndTrialsAreDerivedFromCanonicalCommercialData(): void
    {
        $activeA = $this->subscription(
            'tenant-active-a',
            'plan-active-a',
            100000,
            'COP',
            SubscriptionState::Active,
        );
        $activeB = $this->subscription(
            'tenant-active-b',
            'plan-active-b',
            250000,
            'COP',
            SubscriptionState::Active,
        );
        $trial = $this->subscription(
            'tenant-trial',
            'plan-trial',
            180000,
            'COP',
            SubscriptionState::Trialing,
        );
        $cancelled = $this->subscription(
            'tenant-cancelled',
            'plan-cancelled',
            99000,
            'COP',
            SubscriptionState::Cancelled,
        );

        self::assertSame(
            [
                'status' => 'valid',
                'reason' => null,
                'currency' => 'COP',
                'mrr' => 350000,
                'arr' => 4200000,
                'active_customers' => 2,
                'trials' => 1,
            ],
            PlatformCommercialMetrics::derive([
                $activeA,
                $activeB,
                $trial,
                $cancelled,
            ]),
        );
    }

    public function testIncompleteOrInconsistentCommercialDataIsNotReportedAsValidMetrics(): void
    {
        $cop = $this->subscription(
            'tenant-cop',
            'plan-cop',
            100000,
            'COP',
            SubscriptionState::Active,
        );
        $usd = $this->subscription(
            'tenant-usd',
            'plan-usd',
            100,
            'USD',
            SubscriptionState::Active,
        );

        self::assertSame(
            [
                'status' => 'unavailable',
                'reason' => 'mixed_active_currencies',
                'currency' => null,
                'mrr' => null,
                'arr' => null,
                'active_customers' => null,
                'trials' => null,
            ],
            PlatformCommercialMetrics::derive([$cop, $usd]),
        );

        $quotedPlan = new Plan('quoted-plan', 'Plan cotizable');
        $quotedVersion = new PlanVersion(
            $quotedPlan,
            1,
            null,
            null,
            true,
            [],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $quoted = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                'tenant-quoted',
                $quotedVersion,
                SubscriptionState::Active,
                new DateTimeImmutable('2026-10-01T00:00:00Z'),
            ),
            new DateTimeImmutable('2026-10-01T00:00:01Z'),
        );

        self::assertSame(
            [
                'status' => 'unavailable',
                'reason' => 'incomplete_active_pricing',
                'currency' => null,
                'mrr' => null,
                'arr' => null,
                'active_customers' => null,
                'trials' => null,
            ],
            PlatformCommercialMetrics::derive([$quoted]),
        );
    }

    private function subscription(
        string $tenantId,
        string $planKey,
        int $monthlyAmount,
        string $currency,
        SubscriptionState $state,
    ): Subscription {
        $plan = new Plan($planKey, ucfirst(str_replace('-', ' ', $planKey)));
        $version = new PlanVersion(
            $plan,
            1,
            $monthlyAmount,
            $monthlyAmount * 10,
            false,
            [],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            currency: $currency,
        );

        $at = new DateTimeImmutable('2026-10-01T00:00:00Z');

        return Subscription::fromLifecycle(
            new SubscriptionLifecycle($tenantId, $version, $state, $at),
            $at->modify('+1 second'),
        );
    }
}
