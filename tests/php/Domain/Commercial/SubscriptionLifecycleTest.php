<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SubscriptionLifecycleTest extends TestCase
{
    public function testLifecycleTransitionsAreCanonicalAndHistorical(): void
    {
        $lifecycle = new SubscriptionLifecycle(
            'tenant-a',
            $this->planVersion(),
            SubscriptionState::Trialing,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
        );

        $lifecycle->transitionTo(SubscriptionState::Active, new DateTimeImmutable('2026-10-02T00:00:00Z'));
        $lifecycle->transitionTo(SubscriptionState::PastDue, new DateTimeImmutable('2026-11-02T00:00:00Z'));
        $lifecycle->transitionTo(SubscriptionState::GracePeriod, new DateTimeImmutable('2026-11-03T00:00:00Z'));
        $lifecycle->transitionTo(SubscriptionState::Suspended, new DateTimeImmutable('2026-11-04T00:00:00Z'));
        $lifecycle->transitionTo(SubscriptionState::Active, new DateTimeImmutable('2026-11-05T00:00:00Z'));

        self::assertSame(SubscriptionState::Active, $lifecycle->state());
        self::assertSame(
            ['trialing', 'active', 'past_due', 'grace_period', 'suspended', 'active'],
            array_column($lifecycle->history(), 'state'),
        );
    }

    public function testInvalidOrRegressiveTransitionsFailClosed(): void
    {
        $lifecycle = new SubscriptionLifecycle(
            'tenant-a',
            $this->planVersion(),
            SubscriptionState::Active,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $before = $lifecycle->history();

        foreach ([
            [SubscriptionState::Suspended, '2026-10-02T00:00:00Z'],
            [SubscriptionState::Trialing, '2026-10-02T00:00:00Z'],
            [SubscriptionState::PastDue, '2026-10-01T00:00:00Z'],
        ] as [$target, $at]) {
            try {
                $lifecycle->transitionTo($target, new DateTimeImmutable($at));
                self::fail('Una transición inválida debía fallar.');
            } catch (DomainException) {
                self::assertSame(SubscriptionState::Active, $lifecycle->state());
                self::assertSame($before, $lifecycle->history());
            }
        }
    }

    private function planVersion(): PlanVersion
    {
        return new PlanVersion(
            new Plan('pro', 'Pro'),
            1,
            499900,
            4999000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }
}
