<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\FactoryFeedbackBundle;
use App\Application\Commercial\FactoryFeedbackSignal;
use App\Application\Commercial\PlatformFactoryFeedback;
use PHPUnit\Framework\TestCase;

final class PlatformFactoryFeedbackTest extends TestCase
{
    public function testOwnerReadsOnlyCanonicalAggregateFeedback(): void
    {
        $result = PlatformFactoryFeedback::read(
            true,
            [
                self::bundle('product:commercial'),
                self::bundle('capability:inventory'),
            ],
        );

        self::assertSame('valid', $result['status']);
        self::assertNull($result['reason']);
        self::assertNotNull($result['feedback']);
        self::assertSame(
            ['capability:inventory', 'product:commercial'],
            array_column($result['feedback'], 'subject_ref'),
        );

        $first = $result['feedback'][0];
        self::assertSame(
            [
                'start' => '2026-09-01T00:00:00.000000Z',
                'end' => '2026-10-01T00:00:00.000000Z',
            ],
            $first['source_window'],
        );
        self::assertSame(
            ['present' => ['usage', 'cost', 'demand'], 'missing' => []],
            $first['coverage'],
        );
        self::assertSame('fresh', $first['freshness']['status']);
        self::assertSame(
            'aggregate:usage:inventory:2026-10',
            $first['provenance']['usage'],
        );
    }

    public function testNonOwnerStaleOrIncoherentFeedbackFailsClosed(): void
    {
        $valid = self::bundle('capability:inventory');

        $cases = [
            PlatformFactoryFeedback::read(false, [$valid]),
            PlatformFactoryFeedback::read(true, [self::staleBundle()]),
            PlatformFactoryFeedback::read(true, [$valid, $valid]),
            PlatformFactoryFeedback::read(true, [$valid, 'synthetic']),
            PlatformFactoryFeedback::read(true, []),
        ];

        foreach ($cases as $result) {
            self::assertSame('unavailable', $result['status']);
            self::assertNotSame('', $result['reason']);
            self::assertNull($result['feedback']);
            self::assertArrayNotHasKey('score', $result);
            self::assertArrayNotHasKey('rank', $result);
            self::assertArrayNotHasKey('priority', $result);
            self::assertArrayNotHasKey('action', $result);
            self::assertArrayNotHasKey('recommendation', $result);
        }
    }

    private static function bundle(string $subjectRef): FactoryFeedbackBundle
    {
        return FactoryFeedbackBundle::fromSignals(
            [
                self::signal($subjectRef, 'usage', 'up', 12),
                self::signal($subjectRef, 'cost', 'flat', 9),
                self::signal($subjectRef, 'demand', 'up', 7),
            ],
            '2026-10-01T00:05:00.000000Z',
            3_600,
            '2026-10-01T00:30:00.000000Z',
        );
    }

    private static function staleBundle(): FactoryFeedbackBundle
    {
        return FactoryFeedbackBundle::fromSignals(
            [
                self::signal('capability:inventory', 'usage', 'up', 12),
                self::signal('capability:inventory', 'cost', 'flat', 9),
                self::signal('capability:inventory', 'demand', 'up', 7),
            ],
            '2026-10-01T00:05:00.000000Z',
            60,
            '2026-10-01T00:30:00.000000Z',
        );
    }

    private static function signal(
        string $subjectRef,
        string $type,
        string $direction,
        int $sampleCount,
    ): FactoryFeedbackSignal {
        $subjectKey = str_replace(':', '-', $subjectRef);

        return FactoryFeedbackSignal::fromArray([
            'subject_ref' => $subjectRef,
            'signal_type' => $type,
            'window_start' => '2026-09-01T00:00:00.000000Z',
            'window_end' => '2026-10-01T00:00:00.000000Z',
            'sample_count' => $sampleCount,
            'direction' => $direction,
            'evidence_ref' => 'aggregate:' . $type . ':' . $subjectKey . ':2026-10',
        ]);
    }
}
