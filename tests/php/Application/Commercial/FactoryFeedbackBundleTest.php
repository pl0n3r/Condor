<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\FactoryFeedbackBundle;
use App\Application\Commercial\FactoryFeedbackSignal;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FactoryFeedbackBundleTest extends TestCase
{
    public function testBundleReportsCoverageProvenanceAndFreshness(): void
    {
        $bundle = FactoryFeedbackBundle::fromSignals(
            [
                self::signal('usage', 'up', 12),
                self::signal('cost', 'flat', 9),
                self::signal('demand', 'up', 7),
            ],
            '2026-10-01T00:05:00.000000Z',
            3_600,
            '2026-10-01T00:30:00.000000Z',
        );

        $payload = $bundle->toArray();

        self::assertTrue($bundle->isAvailable());
        self::assertTrue($bundle->isFresh());
        self::assertSame(['usage', 'cost', 'demand'], $bundle->presentTypes());
        self::assertSame([], $bundle->missingTypes());
        self::assertSame(
            [
                'usage' => 'aggregate:usage:inventory:2026-10',
                'cost' => 'aggregate:cost:inventory:2026-10',
                'demand' => 'aggregate:demand:inventory:2026-10',
            ],
            $payload['provenance'],
        );
        self::assertArrayNotHasKey('score', $payload);
        self::assertArrayNotHasKey('rank', $payload);
        self::assertArrayNotHasKey('priority', $payload);
        self::assertArrayNotHasKey('action', $payload);
    }

    public function testMixedDuplicateStaleOrIncompleteEvidenceFailsClosed(): void
    {
        foreach ([
            [
                self::signal('usage', 'up', 12),
                self::signal('usage', 'flat', 8),
            ],
            [
                self::signal('usage', 'up', 12),
                self::signal('cost', 'flat', 9, 'product:commercial'),
            ],
        ] as $invalidSignals) {
            try {
                FactoryFeedbackBundle::fromSignals(
                    $invalidSignals,
                    '2026-10-01T00:05:00.000000Z',
                    3_600,
                    '2026-10-01T00:30:00.000000Z',
                );
                self::fail('Ambiguous Factory Feedback evidence was accepted.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $incomplete = FactoryFeedbackBundle::fromSignals(
            [self::signal('usage', 'up', 12)],
            '2026-10-01T00:05:00.000000Z',
            3_600,
            '2026-10-01T00:30:00.000000Z',
        );
        self::assertFalse($incomplete->isAvailable());
        self::assertSame(['cost', 'demand'], $incomplete->missingTypes());

        $stale = FactoryFeedbackBundle::fromSignals(
            [
                self::signal('usage', 'up', 12),
                self::signal('cost', 'flat', 9),
                self::signal('demand', 'up', 7),
            ],
            '2026-10-01T00:05:00.000000Z',
            60,
            '2026-10-01T00:30:00.000000Z',
        );
        self::assertFalse($stale->isAvailable());
        self::assertFalse($stale->isFresh());

        $unknown = FactoryFeedbackBundle::fromSignals(
            [
                self::signal('usage', 'unknown', 12),
                self::signal('cost', 'flat', 9),
                self::signal('demand', 'up', 7),
            ],
            '2026-10-01T00:05:00.000000Z',
            3_600,
            '2026-10-01T00:30:00.000000Z',
        );
        self::assertFalse($unknown->isAvailable());
    }

    private static function signal(
        string $type,
        string $direction,
        int $sampleCount,
        string $subjectRef = 'capability:inventory',
    ): FactoryFeedbackSignal {
        return FactoryFeedbackSignal::fromArray([
            'subject_ref' => $subjectRef,
            'signal_type' => $type,
            'window_start' => '2026-09-01T00:00:00.000000Z',
            'window_end' => '2026-10-01T00:00:00.000000Z',
            'sample_count' => $sampleCount,
            'direction' => $direction,
            'evidence_ref' => 'aggregate:' . $type . ':inventory:2026-10',
        ]);
    }
}
