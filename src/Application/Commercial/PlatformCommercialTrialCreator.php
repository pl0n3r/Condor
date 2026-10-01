<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlatformCommercialTrialCreator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CommercialCatalogReader $catalog,
        private PlatformCommercialTenantSummary $summary,
    ) {
    }

    /** @return array{status:string, subscription:array<string,mixed>|null} */
    public function create(string $tenantId): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Identidad comercial inválida.');
        }

        $existing = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        if ($existing instanceof Subscription) {
            throw new DomainException(
                'La empresa ya tiene una suscripción comercial configurada.',
            );
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $planVersionId = null;
        foreach ($this->catalog->current($now) as $candidate) {
            $candidatePlanVersionId = $candidate['plan_version_id'] ?? null;
            if (
                ($candidate['key'] ?? null) === 'business'
                && is_string($candidatePlanVersionId)
                && trim($candidatePlanVersionId) !== ''
            ) {
                $planVersionId = $candidatePlanVersionId;
                break;
            }
        }

        if ($planVersionId === null) {
            throw new DomainException('Plan Negocio vigente no disponible.');
        }

        $planVersion = $this->entityManager
            ->getRepository(PlanVersion::class)
            ->find($planVersionId);
        if (
            !$planVersion instanceof PlanVersion
            || $planVersion->plan()->key() !== 'business'
            || !$planVersion->plan()->isActive()
            || !$planVersion->isEffectiveAt($now)
        ) {
            throw new DomainException('Plan Negocio vigente no disponible.');
        }

        $lifecycle = new SubscriptionLifecycle(
            $tenantId,
            $planVersion,
            SubscriptionState::Trialing,
            $now,
        );
        $subscription = Subscription::fromLifecycle($lifecycle, $now);
        $this->entityManager->persist($subscription);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'La empresa ya tiene una suscripción comercial configurada.',
                $exception->getCode(),
                previous: $exception,
            );
        }

        return $this->summary->forTenant($tenantId);
    }
}
