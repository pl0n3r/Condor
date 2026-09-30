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

    public function testRestoreValidatesHistoryFailClosed(): void
    {
        $planVersion = $this->planVersion();
        $lastChangedAt = new DateTimeImmutable('2026-10-03T00:00:00.123456Z');
        $valid = [
            ['state' => 'trialing', 'at' => '2026-10-01T00:00:00.000001+00:00'],
            ['state' => 'active', 'at' => '2026-10-02T00:00:00.000001+00:00'],
            ['state' => 'past_due', 'at' => '2026-10-03T00:00:00.123456+00:00'],
        ];

        $restored = SubscriptionLifecycle::restore(
            'tenant-a',
            $planVersion,
            SubscriptionState::PastDue,
            $lastChangedAt,
            $valid,
        );

        self::assertSame(SubscriptionState::PastDue, $restored->state());
        self::assertSame(
            $lastChangedAt->format('U.u'),
            $restored->lastChangedAt()->format('U.u'),
        );
        self::assertSame(
            ['trialing', 'active', 'past_due'],
            array_column($restored->history(), 'state'),
        );

        $invalidHistories = [
            [],
            [
                ['state' => 'active', 'at' => '2026-10-02T00:00:00+00:00'],
                ['state' => 'past_due', 'at' => '2026-10-01T00:00:00+00:00'],
            ],
            [
                ['state' => 'active', 'at' => '2026-10-01T00:00:00+00:00'],
                ['state' => 'suspended', 'at' => '2026-10-02T00:00:00+00:00'],
            ],
            [
                ['state' => 'active', 'at' => 'not-a-timestamp'],
            ],
            [
                1 => ['state' => 'active', 'at' => '2026-10-01T00:00:00.000000Z'],
            ],
            [
                [
                    'state' => 'active',
                    'at' => '2026-10-01T00:00:00.000000Z',
                    'email' => 'should-not-be-accepted@example.test',
                ],
            ],
            [
                ['state' => 'active', 'at' => '2026-02-30T12:00:00.123456Z'],
            ],
            [
                1 => ['state' => 'active', 'at' => '2026-10-01T00:00:00+00:00'],
            ],
            [
                ['state' => 'active', 'at' => '2026-10-01T00:00:00+00:00', 'payload' => 'forbidden'],
            ],
            [
                ['state' => 'active', 'at' => '2026-02-30T00:00:00+00:00'],
            ],
        ];

        foreach ($invalidHistories as $history) {
            try {
                SubscriptionLifecycle::restore(
                    'tenant-a',
                    $planVersion,
                    SubscriptionState::PastDue,
                    $lastChangedAt,
                    $history,
                );
                self::fail('Un historial persistido inválido debía fallar.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(DomainException::class);
        SubscriptionLifecycle::restore(
            'tenant-a',
            $planVersion,
            SubscriptionState::Active,
            $lastChangedAt,
            $valid,
        );
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
