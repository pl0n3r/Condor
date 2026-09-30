<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\EntitlementOverride;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\SubscriptionChange;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SubscriptionChangeTest extends TestCase
{
    public function testUpgradeIsImmediateAndCompatibleDowngradeIsScheduled(): void
    {
        $basic = $this->planVersion('basic', 79900);
        $pro = $this->planVersion('pro', 499900);
        $requestedAt = new DateTimeImmutable('2026-10-10T12:00:00Z');

        $upgrade = SubscriptionChange::upgrade('tenant-a', $basic, $pro, $requestedAt);
        self::assertSame('effective', $upgrade->status());
        self::assertEquals($requestedAt, $upgrade->effectiveAt());

        $renewsAt = new DateTimeImmutable('2026-11-10T12:00:00Z');
        $downgrade = SubscriptionChange::downgrade(
            'tenant-a',
            $pro,
            $basic,
            $requestedAt,
            $renewsAt,
            true,
        );
        self::assertSame('scheduled', $downgrade->status());
        self::assertEquals($renewsAt, $downgrade->effectiveAt());
    }

    public function testIncompatibleDowngradeRequiresResolutionWithoutEffectiveDate(): void
    {
        $pro = $this->planVersion('pro', 499900);
        $basic = $this->planVersion('basic', 79900);
        $addOn = new AddOn('extra-users', 'Usuarios extra', 30000);
        $override = $this->override('tenant-a');

        $change = SubscriptionChange::downgrade(
            'tenant-a',
            $pro,
            $basic,
            new DateTimeImmutable('2026-10-10T12:00:00Z'),
            new DateTimeImmutable('2026-11-10T12:00:00Z'),
            false,
            [$addOn],
            [$override],
            ['users', 'active_addons'],
        );

        self::assertSame('pending_resolution', $change->status());
        self::assertNull($change->effectiveAt());
        self::assertSame(['active_addons', 'users'], $change->blockers());
        self::assertSame($pro, $change->currentPlan());
        self::assertSame($basic, $change->targetPlan());
        self::assertSame([$addOn], $change->addOns());
        self::assertSame([$override], $change->overrides());
        self::assertSame(25, $override->value());
    }

    public function testSamePlanAddOnChangeIsAllowedAndNoOpIsRejected(): void
    {
        $plan = $this->planVersion('pro', 499900);
        $addOn = new AddOn('analytics', 'Analítica avanzada', 50000);
        $at = new DateTimeImmutable('2026-10-10T12:00:00Z');

        $change = SubscriptionChange::upgrade('tenant-a', $plan, $plan, $at, [$addOn]);
        self::assertSame([$addOn], $change->addOns());

        $this->expectException(DomainException::class);
        SubscriptionChange::upgrade('tenant-a', $plan, $plan, $at);
    }

    public function testSubscriptionSliceReusesCanonicalContractsAndTenantScope(): void
    {
        $basic = $this->planVersion('basic', 79900);
        $pro = $this->planVersion('pro', 499900);
        $addOn = new AddOn('analytics', 'Analítica avanzada', 50000);
        $override = $this->override('tenant-a');

        $change = SubscriptionChange::upgrade(
            'tenant-a',
            $basic,
            $pro,
            new DateTimeImmutable('2026-10-10T12:00:00Z'),
            [$addOn, $addOn],
            [$override],
        );

        self::assertInstanceOf(PlanVersion::class, $change->currentPlan());
        self::assertInstanceOf(PlanVersion::class, $change->targetPlan());
        self::assertSame([$addOn], $change->addOns());
        self::assertSame([$override], $change->overrides());

        $this->expectException(DomainException::class);
        SubscriptionChange::upgrade(
            'tenant-b',
            $basic,
            $pro,
            new DateTimeImmutable('2026-10-11T12:00:00Z'),
            [],
            [$override],
        );
    }

    private function planVersion(string $key, int $monthlyAmount): PlanVersion
    {
        return new PlanVersion(
            new Plan($key, ucfirst($key)),
            1,
            $monthlyAmount,
            $monthlyAmount * 10,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    private function override(string $tenantId): EntitlementOverride
    {
        return new EntitlementOverride(
            $tenantId,
            'limit',
            'users',
            25,
            'Contrato vigente',
            'owner',
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }
}
