<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\PlanPriceAdminService;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PlanPriceAdminServiceTest extends TestCase
{
    public function testZeroNullAndQuoteAreDifferent(): void
    {
        $zero = PlanPriceAdminService::validateRate([
            'currency' => 'COP', 'monthly_amount' => 0, 'annual_amount' => 0, 'quote_required' => false,
        ]);
        self::assertSame(0, $zero['monthly_amount']);
        $unpriced = PlanPriceAdminService::validateRate([
            'currency' => 'COP', 'monthly_amount' => null, 'annual_amount' => null, 'quote_required' => false,
        ]);
        self::assertNull($unpriced['monthly_amount']);
        $quote = PlanPriceAdminService::validateRate([
            'currency' => 'COP', 'monthly_amount' => null, 'annual_amount' => null, 'quote_required' => true,
        ]);
        self::assertTrue($quote['quote_required']);
    }

    public function testNegativeAndInvalidFormatsFailClosed(): void
    {
        foreach ([-1, '0', 1.5, true, 2147483648] as $bad) {
            try {
                PlanPriceAdminService::validateRate([
                    'currency' => 'COP', 'monthly_amount' => $bad,
                    'annual_amount' => null, 'quote_required' => false,
                ]);
                self::fail('Se aceptó un importe inválido');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHistoricAndScheduledVersionsRemainDistinguishable(): void
    {
        $plan = new Plan('synthetic', 'Sintético');
        $now = new DateTimeImmutable('2026-11-06T14:00:00Z');
        $future = new DateTimeImmutable('2026-11-08T00:00:00Z');
        $old = new PlanVersion($plan, 1, null, null, true, [], $now);
        $old->closeAt($future);
        $new = new PlanVersion(
            $plan, 2, 0, 0, false, [], $future, null, 'COP',
            ['addons' => ['extra-user' => [
                'currency' => 'COP', 'monthly_amount' => 0,
                'annual_amount' => null, 'quote_required' => false,
            ]]],
        );
        $timeline = new PlanVersionTimeline();
        $timeline->assertCanAdd([$old], $new);
        self::assertSame($old, $timeline->effectiveAt([$old, $new], $plan, $now));
        self::assertSame($new, $timeline->effectiveAt([$old, $new], $plan, $future));
        self::assertSame(0, $new->priceOptions()['addons']['extra-user']['monthly_amount']);
        $old->reopenAfterCancellation($future);
        self::assertNull($old->effectiveUntil());
        self::assertSame($old, $timeline->effectiveAt([$old], $plan, $future));
    }
}
