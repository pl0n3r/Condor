<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\FactoryFeedbackSignal;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FactoryFeedbackSignalTest extends TestCase
{
    public function testSignalKeepsOnlyAggregateCanonicalEvidence(): void
    {
        $signal = FactoryFeedbackSignal::fromArray(self::validPayload());

        self::assertSame(self::validPayload(), $signal->toArray());
        self::assertSame('capability:inventory', $signal->subjectRef());
        self::assertSame('usage', $signal->signalType());
        self::assertSame(12, $signal->sampleCount());
        self::assertSame('up', $signal->direction());
        self::assertSame('aggregate:usage:inventory:2026-10', $signal->evidenceRef());
    }

    public function testIndividualSmallCohortOrFreeFormSignalFailsClosed(): void
    {
        $valid = self::validPayload();

        $invalidPayloads = [
            [...$valid, 'user_ref' => 'synthetic'],
            [...$valid, 'tenant_ref' => 'synthetic'],
            [...$valid, 'note' => 'synthetic'],
            [...$valid, 'sample_count' => 2],
            [...$valid, 'subject_ref' => 'tenant:synthetic'],
            [...$valid, 'subject_ref' => 'user:synthetic'],
            [...$valid, 'evidence_ref' => 'event:synthetic'],
            [...$valid, 'signal_type' => 'individual'],
            [...$valid, 'direction' => 'priority'],
            [...$valid, 'window_end' => $valid['window_start']],
            [...$valid, 'window_start' => '2026-10-01'],
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                FactoryFeedbackSignal::fromArray($payload);
                self::fail('Invalid Factory Feedback signal was accepted.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * @return array{
     *     subject_ref:string,
     *     signal_type:string,
     *     window_start:string,
     *     window_end:string,
     *     sample_count:int,
     *     direction:string,
     *     evidence_ref:string
     * }
     */
    private static function validPayload(): array
    {
        return [
            'subject_ref' => 'capability:inventory',
            'signal_type' => 'usage',
            'window_start' => '2026-09-01T00:00:00.000000Z',
            'window_end' => '2026-10-01T00:00:00.000000Z',
            'sample_count' => 12,
            'direction' => 'up',
            'evidence_ref' => 'aggregate:usage:inventory:2026-10',
        ];
    }
}
