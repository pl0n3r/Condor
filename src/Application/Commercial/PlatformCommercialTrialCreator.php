<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
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
    public function create(string $tenantId, ?string $quoteId = null): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Identidad comercial inválida.');
        }

        if ($quoteId === null) {
            return $this->createLegacyBusinessTrial($tenantId);
        }

        $quoteId = trim($quoteId);
        if ($quoteId === '') {
            throw new DomainException('Quote comercial inválido.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertTenantAvailable($this->entityManager, $tenantId);
        $quote = $this->quoteForTrial($this->entityManager, $quoteId, $now);
        $this->resolveQuoteAddOns($quote->planVersion(), $quote->addOns());

        try {
            $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use (
                    $tenantId,
                    $quoteId,
                    $now,
                ): void {
                    $quote = $entityManager
                        ->getRepository(Quote::class)
                        ->find($quoteId);
                    if (!$quote instanceof Quote) {
                        throw new DomainException(
                            'Quote comercial no disponible para crear el trial.',
                        );
                    }
                    $entityManager->refresh(
                        $quote,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    $this->assertQuoteEligible($quote, $now);
                    $this->assertTenantAvailable($entityManager, $tenantId);

                    $planVersion = $quote->planVersion();
                    $addOns = $this->resolveQuoteAddOns(
                        $planVersion,
                        $quote->addOns(),
                    );
                    $lifecycle = new SubscriptionLifecycle(
                        $tenantId,
                        $planVersion,
                        SubscriptionState::Trialing,
                        $now,
                    );
                    $subscription = Subscription::fromLifecycle(
                        $lifecycle,
                        $now,
                    );
                    $configuration = new SubscriptionConfiguration(
                        $subscription,
                        $quote->vertical(),
                        $quote->quantities(),
                        $addOns,
                        $now,
                    );

                    $entityManager->persist($subscription);
                    $entityManager->persist($configuration);
                    $quote->convert();
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'La empresa ya tiene una suscripción comercial configurada.',
                $exception->getCode(),
                previous: $exception,
            );
        }

        return $this->summary->forTenant($tenantId);
    }

    /** @return array{status:string, subscription:array<string,mixed>|null} */
    private function createLegacyBusinessTrial(string $tenantId): array
    {
        $this->assertTenantAvailable($this->entityManager, $tenantId);

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

    private function assertTenantAvailable(
        EntityManagerInterface $entityManager,
        string $tenantId,
    ): void {
        $existing = $entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        if ($existing instanceof Subscription) {
            throw new DomainException(
                'La empresa ya tiene una suscripción comercial configurada.',
            );
        }
    }

    private function quoteForTrial(
        EntityManagerInterface $entityManager,
        string $quoteId,
        DateTimeImmutable $now,
    ): Quote {
        $quote = $entityManager->getRepository(Quote::class)->find($quoteId);
        if (!$quote instanceof Quote) {
            throw new DomainException(
                'Quote comercial no disponible para crear el trial.',
            );
        }

        $this->assertQuoteEligible($quote, $now);

        return $quote;
    }

    private function assertQuoteEligible(
        Quote $quote,
        DateTimeImmutable $now,
    ): void {
        $planVersion = $quote->planVersion();
        if (
            $quote->status() !== 'draft'
            || $quote->validUntil() < $now
            || $planVersion->plan()->key() !== 'business'
            || !$planVersion->plan()->isActive()
            || !$planVersion->isEffectiveAt($now)
        ) {
            throw new DomainException(
                'Quote comercial no disponible para crear el trial.',
            );
        }
    }

    /**
     * @param list<string> $keys
     * @return list<AddOn>
     */
    private function resolveQuoteAddOns(
        PlanVersion $planVersion,
        array $keys,
    ): array {
        if (count($keys) > 50) {
            throw new DomainException('Add-ons del Quote inválidos.');
        }

        $allowed = [];
        foreach ($planVersion->addOns() as $addOn) {
            if ($addOn->isActive()) {
                $allowed[$addOn->key()] = $addOn;
            }
        }

        $resolved = [];
        foreach ($keys as $key) {
            if (
                !isset($allowed[$key])
                || isset($resolved[$key])
            ) {
                throw new DomainException(
                    'Quote con add-ons incompatibles con la PlanVersion.',
                );
            }
            $resolved[$key] = $allowed[$key];
        }
        ksort($resolved);

        return array_values($resolved);
    }
}
