<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class FactoryFeedbackSignal
{
    private const SIGNAL_TYPES = ['usage', 'cost', 'demand'];
    private const DIRECTIONS = ['up', 'flat', 'down', 'unknown'];
    private const SUBJECT_PATTERN = '/^(?:capability|product):[a-z0-9][a-z0-9:_-]{1,149}$/D';
    private const EVIDENCE_PATTERN = '/^aggregate:[a-z0-9][a-z0-9:_-]{1,149}$/D';

    public function __construct(
        private string $subjectRef,
        private string $signalType,
        private string $windowStart,
        private string $windowEnd,
        private int $sampleCount,
        private string $direction,
        private string $evidenceRef,
    ) {
        if (preg_match(self::SUBJECT_PATTERN, $subjectRef) !== 1) {
            throw new DomainException('invalid_factory_feedback_subject_ref');
        }

        if (!in_array($signalType, self::SIGNAL_TYPES, true)) {
            throw new DomainException('invalid_factory_feedback_signal_type');
        }

        self::assertUtcTimestamp($windowStart, 'window_start');
        self::assertUtcTimestamp($windowEnd, 'window_end');

        if ($windowStart >= $windowEnd) {
            throw new DomainException('invalid_factory_feedback_window');
        }

        if ($sampleCount < 3) {
            throw new DomainException('factory_feedback_cohort_too_small');
        }

        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new DomainException('invalid_factory_feedback_direction');
        }

        if (preg_match(self::EVIDENCE_PATTERN, $evidenceRef) !== 1) {
            throw new DomainException('invalid_factory_feedback_evidence_ref');
        }
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $actual = array_keys($input);
        sort($actual);
        $expected = [
            'direction',
            'evidence_ref',
            'sample_count',
            'signal_type',
            'subject_ref',
            'window_end',
            'window_start',
        ];

        if ($actual !== $expected) {
            throw new DomainException('invalid_factory_feedback_signal_shape');
        }

        if (
            !is_string($input['subject_ref'])
            || !is_string($input['signal_type'])
            || !is_string($input['window_start'])
            || !is_string($input['window_end'])
            || is_bool($input['sample_count'])
            || !is_int($input['sample_count'])
            || !is_string($input['direction'])
            || !is_string($input['evidence_ref'])
        ) {
            throw new DomainException('invalid_factory_feedback_signal_shape');
        }

        return new self(
            $input['subject_ref'],
            $input['signal_type'],
            $input['window_start'],
            $input['window_end'],
            $input['sample_count'],
            $input['direction'],
            $input['evidence_ref'],
        );
    }

    /** @return array{subject_ref:string,signal_type:string,window_start:string,window_end:string,sample_count:int,direction:string,evidence_ref:string} */
    public function toArray(): array
    {
        return [
            'subject_ref' => $this->subjectRef,
            'signal_type' => $this->signalType,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'sample_count' => $this->sampleCount,
            'direction' => $this->direction,
            'evidence_ref' => $this->evidenceRef,
        ];
    }

    public function subjectRef(): string
    {
        return $this->subjectRef;
    }

    public function signalType(): string
    {
        return $this->signalType;
    }

    public function windowStart(): string
    {
        return $this->windowStart;
    }

    public function windowEnd(): string
    {
        return $this->windowEnd;
    }

    public function sampleCount(): int
    {
        return $this->sampleCount;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function evidenceRef(): string
    {
        return $this->evidenceRef;
    }

    private static function assertUtcTimestamp(string $value, string $field): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $value);

        if ($parsed === false || $parsed->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException('invalid_factory_feedback_' . $field);
        }
    }
}
