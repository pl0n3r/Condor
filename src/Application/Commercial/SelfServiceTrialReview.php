<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Identity\Entity\User;
use DomainException;

final readonly class SelfServiceTrialReview
{
    public function __construct(
        private PlatformCommercialTrialCreator $trialCreator,
    ) {
    }

    /**
     * @param array<string,mixed> $application
     * @return array{
     *   status:string,
     *   quote_id:string,
     *   tenant_id:string,
     *   trial?:array<string,mixed>
     * }
     */
    public function review(
        User $reviewer,
        string $applicationTenantId,
        string $targetTenantId,
        array $application,
        string $decision,
    ): array {
        if (
            !$reviewer->isActive()
            || !$reviewer->hasRole(User::ROLE_PLATFORM_OWNER)
        ) {
            throw new DomainException(
                'Solo el dueño de plataforma puede revisar solicitudes de trial.',
            );
        }

        $applicationTenantId = trim($applicationTenantId);
        $targetTenantId = trim($targetTenantId);
        if ($applicationTenantId === '' || $targetTenantId === '') {
            throw new DomainException('Identidad de empresa inválida.');
        }
        if (!hash_equals($applicationTenantId, $targetTenantId)) {
            throw new DomainException(
                'Handoff de trial entre empresas no permitido.',
            );
        }

        $keys = array_keys($application);
        sort($keys);
        if (
            $keys !== [
                'consent_recorded_at',
                'email',
                'quote_id',
                'status',
            ]
        ) {
            throw new DomainException(
                'Solicitud de trial incompatible con revisión owner-only.',
            );
        }

        if (($application['status'] ?? null) !== 'pending_owner_review') {
            throw new DomainException(
                'Solicitud de trial ya resuelta o no pendiente.',
            );
        }

        $quoteId = $application['quote_id'] ?? null;
        $email = $application['email'] ?? null;
        $consentRecordedAt = $application['consent_recorded_at'] ?? null;
        if (
            !is_string($quoteId)
            || trim($quoteId) === ''
            || !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !is_string($consentRecordedAt)
            || trim($consentRecordedAt) === ''
        ) {
            throw new DomainException(
                'Solicitud de trial pendiente con datos inválidos.',
            );
        }

        $decision = trim($decision);
        if ($decision === 'reject') {
            return [
                'status' => 'rejected',
                'quote_id' => $quoteId,
                'tenant_id' => $targetTenantId,
            ];
        }
        if ($decision !== 'approve') {
            throw new DomainException('Decisión de revisión de trial inválida.');
        }

        return [
            'status' => 'approved',
            'quote_id' => $quoteId,
            'tenant_id' => $targetTenantId,
            'trial' => $this->trialCreator->create($targetTenantId),
        ];
    }
}
