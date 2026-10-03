<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class FactoryFeedbackBundle
{
    private const SIGNAL_TYPES = ['usage', 'cost', 'demand'];
    private const MAX_FRESHNESS_SECONDS = 2_678_400;

    /**
     * @param array<string,FactoryFeedbackSignal> $signalsByType
     * @param list<string> $presentTypes
     * @param list<string> $missingTypes
     */
    private function __construct(
        private string $subjectRef,
        private string $windowStart,
        private string $windowEnd,
        private array $signalsByType,
        private array $presentTypes,
        private array $missingTypes,
        private string $observedAt,
        private int $freshnessSeconds,
        private string $evaluatedAt,
        private bool $fresh,
        private bool $available,
    ) {
    }

    /**
     * @param list<FactoryFeedbackSignal> $signals
     */
    public static function fromSignals(
        array $signals,
        string $observedAt,
        int $freshnessSeconds,
        string $evaluatedAt,
    ): self {
        if ($signals === []) {
            throw new DomainException('empty_factory_feedback_bundle');
        }

        if ($freshnessSeconds < 1 || $freshnessSeconds > self::MAX_FRESHNESS_SECONDS) {
            throw new DomainException('invalid_factory_feedback_freshness_seconds');
        }

        $first = $signals[0] ?? null;
        if (!$first instanceof FactoryFeedbackSignal) {
            throw new DomainException('invalid_factory_feedback_bundle_signal');
        }

        $signalsByType = [];
        $hasUnknownDirection = false;

        foreach ($signals as $signal) {
            if (!$signal instanceof FactoryFeedbackSignal) {
                throw new DomainException('invalid_factory_feedback_bundle_signal');
            }

            if (
                $signal->subjectRef() !== $first->subjectRef()
                || $signal->windowStart() !== $first->windowStart()
                || $signal->windowEnd() !== $first->windowEnd()
            ) {
                throw new DomainException('mixed_factory_feedback_bundle');
            }

            $type = $signal->signalType();
            if (array_key_exists($type, $signalsByType)) {
                throw new DomainException('duplicate_factory_feedback_signal_type');
            }

            $signalsByType[$type] = $signal;
            $hasUnknownDirection = $hasUnknownDirection || $signal->direction() === 'unknown';
        }

        $observed = self::parseUtcTimestamp($observedAt, 'observed_at');
        $evaluated = self::parseUtcTimestamp($evaluatedAt, 'evaluated_at');
        $windowEnd = self::parseUtcTimestamp($first->windowEnd(), 'window_end');

        if ($observed < $windowEnd) {
            throw new DomainException('factory_feedback_observed_before_window_end');
        }

        if ($evaluated < $observed) {
            throw new DomainException('factory_feedback_evaluated_before_observed');
        }

        $presentTypes = [];
        $missingTypes = [];

        foreach (self::SIGNAL_TYPES as $type) {
            if (array_key_exists($type, $signalsByType)) {
                $presentTypes[] = $type;
            } else {
                $missingTypes[] = $type;
            }
        }

        $freshUntil = $observed->modify('+' . $freshnessSeconds . ' seconds');
        $fresh = $evaluated <= $freshUntil;
        $available = $fresh && $missingTypes === [] && !$hasUnknownDirection;

        return new self(
            subjectRef: $first->subjectRef(),
            windowStart: $first->windowStart(),
            windowEnd: $first->windowEnd(),
            signalsByType: $signalsByType,
            presentTypes: $presentTypes,
            missingTypes: $missingTypes,
            observedAt: $observedAt,
            freshnessSeconds: $freshnessSeconds,
            evaluatedAt: $evaluatedAt,
            fresh: $fresh,
            available: $available,
        );
    }

    /**
     * @return array{
     *     subject_ref:string,
     *     window_start:string,
     *     window_end:string,
     *     coverage:array{present:list<string>,missing:list<string>},
     *     signals:array<string,array{sample_count:int,direction:string,evidence_ref:string}>,
     *     provenance:array<string,string>,
     *     observed_at:string,
     *     freshness_seconds:int,
     *     evaluated_at:string,
     *     fresh:bool,
     *     available:bool
     * }
     */
    public function toArray(): array
    {
        $signals = [];
        $provenance = [];

        foreach (self::SIGNAL_TYPES as $type) {
            $signal = $this->signalsByType[$type] ?? null;
            if (!$signal instanceof FactoryFeedbackSignal) {
                continue;
            }

            $signals[$type] = [
                'sample_count' => $signal->sampleCount(),
                'direction' => $signal->direction(),
                'evidence_ref' => $signal->evidenceRef(),
            ];
            $provenance[$type] = $signal->evidenceRef();
        }

        return [
            'subject_ref' => $this->subjectRef,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'coverage' => [
                'present' => $this->presentTypes,
                'missing' => $this->missingTypes,
            ],
            'signals' => $signals,
            'provenance' => $provenance,
            'observed_at' => $this->observedAt,
            'freshness_seconds' => $this->freshnessSeconds,
            'evaluated_at' => $this->evaluatedAt,
            'fresh' => $this->fresh,
            'available' => $this->available,
        ];
    }

    public function subjectRef(): string
    {
        return $this->subjectRef;
    }

    public function isFresh(): bool
    {
        return $this->fresh;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** @return list<string> */
    public function presentTypes(): array
    {
        return $this->presentTypes;
    }

    /** @return list<string> */
    public function missingTypes(): array
    {
        return $this->missingTypes;
    }

    private static function parseUtcTimestamp(string $value, string $field): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $value);

        if ($parsed === false || $parsed->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException('invalid_factory_feedback_bundle_' . $field);
        }

        return $parsed;
    }
}
