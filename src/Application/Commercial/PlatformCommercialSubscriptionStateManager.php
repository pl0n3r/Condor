<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlatformCommercialSubscriptionStateManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformCommercialTenantSummary $summary,
    ) {
    }

    /** @return array{status:string, subscription:array<string,mixed>|null} */
    public function transition(string $tenantId, string $targetState): array
    {
        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);

        if (!$subscription instanceof Subscription) {
            throw new DomainException(
                'La empresa no tiene una suscripción comercial configurada.',
            );
        }

        $target = SubscriptionState::tryFrom(trim($targetState));
        if ($target === null) {
            throw new DomainException('Estado de suscripción inválido.');
        }

        $changedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lifecycle = $subscription->toLifecycle();
        $lifecycle->transitionTo($target, $changedAt);
        $subscription->syncFromLifecycle($lifecycle, $changedAt);
        $this->entityManager->flush();

        return $this->summary->forTenant($tenantId);
    }
}
