<?php

declare(strict_types=1);

namespace App\Application\Commercial;

final class PlatformFactoryFeedback
{
    /**
     * @param list<mixed> $bundles
     * @return array{
     *     status:'valid'|'unavailable',
     *     reason:string|null,
     *     feedback:list<array{
     *         subject_ref:string,
     *         source_window:array{start:string,end:string},
     *         coverage:array{present:list<string>,missing:list<string>},
     *         freshness:array{
     *             observed_at:string,
     *             evaluated_at:string,
     *             threshold_seconds:int,
     *             status:'fresh'
     *         },
     *         signals:array<string,array{
     *             sample_count:int,
     *             direction:string,
     *             evidence_ref:string
     *         }>,
     *         provenance:array<string,string>
     *     }>|null
     * }
     */
    public static function read(bool $owner, array $bundles): array
    {
        if (!$owner) {
            return self::closed('owner_required');
        }

        if ($bundles === []) {
            return self::closed('feedback_unavailable');
        }

        $feedback = [];
        $seenSubjects = [];

        foreach ($bundles as $candidate) {
            if (!$candidate instanceof FactoryFeedbackBundle) {
                return self::closed('invalid_feedback_bundle');
            }

            if (!$candidate->isFresh()) {
                return self::closed('stale_feedback');
            }

            if (!$candidate->isAvailable()) {
                return self::closed('unavailable_feedback');
            }

            $subjectRef = $candidate->subjectRef();
            if (isset($seenSubjects[$subjectRef])) {
                return self::closed('duplicate_feedback_subject');
            }
            $seenSubjects[$subjectRef] = true;

            $bundle = $candidate->toArray();
            if (
                $bundle['coverage']['present'] !== ['usage', 'cost', 'demand']
                || $bundle['coverage']['missing'] !== []
                || $bundle['fresh'] !== true
                || $bundle['available'] !== true
            ) {
                return self::closed('incoherent_feedback_bundle');
            }

            $feedback[] = [
                'subject_ref' => $subjectRef,
                'source_window' => [
                    'start' => $bundle['window_start'],
                    'end' => $bundle['window_end'],
                ],
                'coverage' => $bundle['coverage'],
                'freshness' => [
                    'observed_at' => $bundle['observed_at'],
                    'evaluated_at' => $bundle['evaluated_at'],
                    'threshold_seconds' => $bundle['freshness_seconds'],
                    'status' => 'fresh',
                ],
                'signals' => $bundle['signals'],
                'provenance' => $bundle['provenance'],
            ];
        }

        usort(
            $feedback,
            static fn (array $left, array $right): int =>
                $left['subject_ref'] <=> $right['subject_ref'],
        );

        return [
            'status' => 'valid',
            'reason' => null,
            'feedback' => $feedback,
        ];
    }

    /**
     * @return array{
     *     status:'unavailable',
     *     reason:string,
     *     feedback:null
     * }
     */
    private static function closed(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'feedback' => null,
        ];
    }
}
