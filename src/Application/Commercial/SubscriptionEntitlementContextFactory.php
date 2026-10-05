<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\EntitlementOverride;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class SubscriptionEntitlementContextFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<mixed> $overrides
     */
    public function forTenant(
        string $tenantId,
        DateTimeImmutable $evaluatedAt,
        array $overrides = [],
    ): EntitlementContext {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de entitlement inválido.');
        }

        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        if (
            !$subscription instanceof Subscription
            || !hash_equals($tenantId, $subscription->tenantId())
        ) {
            throw new DomainException(
                'Suscripción comercial no disponible para el tenant.',
            );
        }

        $configuration = $this->entityManager
            ->getRepository(SubscriptionConfiguration::class)
            ->findOneBy(['subscription' => $subscription]);
        if (
            !$configuration instanceof SubscriptionConfiguration
            || $configuration->subscription()->id() !== $subscription->id()
            || !hash_equals(
                $tenantId,
                $configuration->subscription()->tenantId(),
            )
        ) {
            throw new DomainException(
                'Configuración comercial no disponible para el tenant.',
            );
        }

        $validatedOverrides = [];
        foreach ($overrides as $override) {
            if (
                !$override instanceof EntitlementOverride
                || !hash_equals($tenantId, $override->tenantId())
            ) {
                throw new DomainException(
                    'Override de entitlement pertenece a otro tenant o es inválido.',
                );
            }
            $validatedOverrides[] = $override;
        }

        return new EntitlementContext(
            $tenantId,
            $subscription->planVersion(),
            $configuration->vertical(),
            $configuration->addOns(),
            $validatedOverrides,
            $evaluatedAt,
            $configuration->quantities(),
        );
    }
}
