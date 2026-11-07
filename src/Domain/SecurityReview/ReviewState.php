<?php

declare(strict_types=1);

namespace App\Domain\SecurityReview;

use DomainException;

/** Closed synthetic M14 state machine; no incident workflow is enabled in production. */
final class ReviewState
{
    public const RECERTIFICATION = 'recertification';
    public const INCIDENT = 'incident';

    public static function initial(string $kind): string
    {
        return match ($kind) {
            self::RECERTIFICATION => 'pending',
            self::INCIDENT => 'open',
            default => throw new DomainException('Tipo de simulación no permitido.'),
        };
    }

    public static function assertTransition(string $kind, string $from, string $to): void
    {
        $allowed = match ($kind) {
            self::RECERTIFICATION => [
                'pending' => ['approved', 'rejected'],
                'approved' => [],
                'rejected' => [],
            ],
            self::INCIDENT => [
                'open' => ['investigating'],
                'investigating' => ['resolved'],
                'resolved' => [],
            ],
            default => throw new DomainException('Tipo de simulación no permitido.'),
        };

        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new DomainException('Transición de estado no permitida.');
        }
    }
}
