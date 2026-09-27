<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CommercialCatalogModelTest extends TestCase
{
    public function testPlanVersionsPreserveHistoryAndRejectOverlap(): void
    {
        $plan = new Plan('negocio', 'Negocio');
        $timeline = new PlanVersionTimeline();
        $first = new PlanVersion(
            $plan,
            1,
            199900,
            1999000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $second = new PlanVersion(
            $plan,
            2,
            219900,
            2199000,
            false,
            ['users' => 10],
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        $timeline->assertCanAdd([$first], $second);
        self::assertSame(
            1,
            $timeline->effectiveAt(
                [$first, $second],
                $plan,
                new DateTimeImmutable('2026-06-30T23:59:59Z'),
            )?->version(),
        );
        self::assertSame(
            2,
            $timeline->effectiveAt(
                [$first, $second],
                $plan,
                new DateTimeImmutable('2026-07-01T00:00:00Z'),
            )?->version(),
        );

        $overlap = new PlanVersion(
            $plan,
            3,
            229900,
            null,
            false,
            [],
            new DateTimeImmutable('2026-06-01T00:00:00Z'),
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
        );

        $this->expectException(DomainException::class);
        $timeline->assertCanAdd([$first, $second], $overlap);
    }

    public function testPlanAndVerticalAreIndependent(): void
    {
        $plan = new Plan('pro', 'Pro');
        $commerce = new Vertical('commerce', 'Comercio');
        $legal = new Vertical('legal', 'Legal / Abogados');
        $version = new PlanVersion(
            $plan,
            1,
            499900,
            4999000,
            false,
            ['companies' => 5],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        $version->addVertical($commerce);
        $version->addVertical($legal);
        $version->addVertical($commerce);

        self::assertSame('pro', $version->plan()->key());
        self::assertSame(
            ['commerce', 'legal'],
            array_map(
                static fn (Vertical $vertical): string => $vertical->key(),
                $version->verticals(),
            ),
        );
    }

    public function testEnterprisePriceIsUnknownNotZero(): void
    {
        $enterprise = new Plan('enterprise', 'Enterprise');
        $quote = new PlanVersion(
            $enterprise,
            1,
            null,
            null,
            true,
            [],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        self::assertTrue($quote->quoteRequired());
        self::assertNull($quote->monthlyAmount());
        self::assertNull($quote->annualAmount());

        $this->expectException(DomainException::class);
        new PlanVersion(
            new Plan('basic', 'Básico'),
            1,
            null,
            null,
            false,
            [],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }
}
