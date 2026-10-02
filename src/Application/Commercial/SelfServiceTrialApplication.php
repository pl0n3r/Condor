<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Quote;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final readonly class SelfServiceTrialApplication
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = ['consent', 'email'];

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   quote_id:string,
     *   email:string,
     *   consent_recorded_at:string,
     *   status:string
     * }
     */
    public function submit(
        ?Quote $quote,
        array $payload,
        DateTimeImmutable $submittedAt,
    ): array {
        if (
            !$quote instanceof Quote
            || $quote->status() !== 'draft'
            || $quote->validUntil() < $submittedAt
        ) {
            throw new DomainException('Quote comercial no disponible para solicitud de trial.');
        }

        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== self::ALLOWED_FIELDS) {
            throw new DomainException('Campos de solicitud de trial no permitidos.');
        }

        if (($payload['consent'] ?? null) !== true) {
            throw new DomainException('Consentimiento explícito requerido.');
        }

        $email = $payload['email'] ?? null;
        if (!is_string($email)) {
            throw new DomainException('Email de solicitud inválido.');
        }
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Email de solicitud inválido.');
        }

        return [
            'quote_id' => $quote->id(),
            'email' => $email,
            'consent_recorded_at' => $submittedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\\TH:i:s.u\\Z'),
            'status' => 'pending_owner_review',
        ];
    }
}
