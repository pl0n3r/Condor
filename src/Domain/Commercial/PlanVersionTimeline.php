<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use DateTimeImmutable;
use DomainException;

final class PlanVersionTimeline
{
    /** @param iterable<PlanVersion> $existing */
    public function assertCanAdd(iterable $existing, PlanVersion $candidate): void
    {
        foreach ($existing as $version) {
            if ($version->plan()->key() !== $candidate->plan()->key()) {
                continue;
            }
            if (
                $version->version() === $candidate->version()
                || $this->overlaps($version, $candidate)
            ) {
                throw new DomainException(
                    'Versión duplicada o vigencia comercial solapada.',
                );
            }
        }
    }

    /** @param iterable<PlanVersion> $versions */
    public function effectiveAt(
        iterable $versions,
        Plan $plan,
        DateTimeImmutable $at,
    ): ?PlanVersion {
        $match = null;
        foreach ($versions as $version) {
            if (
                $version->plan()->key() !== $plan->key()
                || !$version->isEffectiveAt($at)
            ) {
                continue;
            }
            if ($match !== null) {
                throw new DomainException('Más de una versión vigente.');
            }
            $match = $version;
        }

        return $match;
    }

    private function overlaps(PlanVersion $a, PlanVersion $b): bool
    {
        return ($a->effectiveUntil() === null
                || $b->effectiveFrom() < $a->effectiveUntil())
            && ($b->effectiveUntil() === null
                || $a->effectiveFrom() < $b->effectiveUntil());
    }
}
