<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\SubscriptionLifecycle;
use DomainException;

final readonly class SelfServiceTrialStatus
{
    /**
     * @param array<string,mixed> $application
     * @param array<string,mixed>|null $review
     * @return array{
     *   status:string,
     *   trial_started_at?:string,
     *   trial_ends_at?:string
     * }
     */
    public function read(
        string $lookupQuoteId,
        string $requesterTenantId,
        string $applicationTenantId,
        array $application,
        ?array $review,
    ): array {
        $lookupQuoteId = trim($lookupQuoteId);
        $requesterTenantId = trim($requesterTenantId);
        $applicationTenantId = trim($applicationTenantId);

        if (
            $lookupQuoteId === ''
            || $requesterTenantId === ''
            || $applicationTenantId === ''
        ) {
            throw new DomainException('Consulta de trial inválida.');
        }
        if (!hash_equals($applicationTenantId, $requesterTenantId)) {
            throw new DomainException('Consulta de trial no autorizada.');
        }

        $applicationKeys = array_keys($application);
        sort($applicationKeys);
        if (
            $applicationKeys !== [
                'consent_recorded_at',
                'email',
                'quote_id',
                'status',
            ]
        ) {
            throw new DomainException('Solicitud de trial incompatible con consulta.');
        }

        $quoteId = $application['quote_id'] ?? null;
        $email = $application['email'] ?? null;
        $consentRecordedAt = $application['consent_recorded_at'] ?? null;
        if (
            !is_string($quoteId)
            || trim($quoteId) === ''
            || !hash_equals($quoteId, $lookupQuoteId)
        ) {
            throw new DomainException('Solicitud de trial no encontrada.');
        }
        if (
            !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !is_string($consentRecordedAt)
            || trim($consentRecordedAt) === ''
            || ($application['status'] ?? null) !== 'pending_owner_review'
        ) {
            throw new DomainException('Solicitud de trial incoherente.');
        }

        if ($review === null) {
            return ['status' => 'pending_owner_review'];
        }

        $reviewStatus = $review['status'] ?? null;
        $reviewQuoteId = $review['quote_id'] ?? null;
        $reviewTenantId = $review['tenant_id'] ?? null;
        if (
            !is_string($reviewStatus)
            || !is_string($reviewQuoteId)
            || !hash_equals($quoteId, $reviewQuoteId)
            || !is_string($reviewTenantId)
            || !hash_equals($applicationTenantId, $reviewTenantId)
        ) {
            throw new DomainException('Resultado de revisión de trial incoherente.');
        }

        $reviewKeys = array_keys($review);
        sort($reviewKeys);
        if ($reviewStatus === 'rejected') {
            if ($reviewKeys !== ['quote_id', 'status', 'tenant_id']) {
                throw new DomainException('Resultado de revisión de trial incoherente.');
            }

            return ['status' => 'rejected'];
        }

        if (
            $reviewStatus !== 'approved'
            || $reviewKeys !== ['quote_id', 'status', 'tenant_id', 'trial']
            || !is_array($review['trial'] ?? null)
        ) {
            throw new DomainException('Resultado de revisión de trial incoherente.');
        }

        $trial = $review['trial'];
        $subscription = $trial['subscription'] ?? null;
        if (
            ($trial['status'] ?? null) !== 'configured'
            || !is_array($subscription)
            || ($subscription['state'] ?? null) !== 'trialing'
        ) {
            throw new DomainException('Trial aprobado sin ventana autorizada.');
        }

        $startedAt = $subscription['trial_started_at'] ?? null;
        $endsAt = $subscription['trial_ends_at'] ?? null;
        if (!is_string($startedAt) || !is_string($endsAt)) {
            throw new DomainException('Trial aprobado sin ventana autorizada.');
        }

        $parsedStart = SubscriptionLifecycle::parseHistoricalTime($startedAt);
        $parsedEnd = SubscriptionLifecycle::parseHistoricalTime($endsAt);
        if ($parsedEnd <= $parsedStart) {
            throw new DomainException('Ventana de trial incoherente.');
        }

        return [
            'status' => 'approved',
            'trial_started_at' => $startedAt,
            'trial_ends_at' => $endsAt,
        ];
    }
}
