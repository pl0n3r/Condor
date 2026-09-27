<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\CommercialCatalog\CommercialCatalogRepository;
use App\Domain\CommercialCatalog\Entity\AddOn;
use App\Domain\CommercialCatalog\Entity\Capability;
use App\Domain\CommercialCatalog\Entity\PlanVersion;
use App\Domain\CommercialCatalog\Entity\Vertical;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineCommercialCatalogRepository implements CommercialCatalogRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function effectivePlanVersions(DateTimeImmutable $at): array
    {
        /** @var list<PlanVersion> $result */
        $result = $this->entityManager
            ->getRepository(PlanVersion::class)
            ->createQueryBuilder('version')
            ->innerJoin('version.plan', 'plan')
            ->andWhere('version.availableForSale = :available')
            ->andWhere('version.validFrom <= :at')
            ->andWhere('(version.validUntil IS NULL OR version.validUntil > :at)')
            ->andWhere('plan.active = :active')
            ->setParameter('available', true)
            ->setParameter('active', true)
            ->setParameter('at', $at)
            ->orderBy('plan.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function activeVerticals(): array
    {
        /** @var list<Vertical> $result */
        $result = $this->entityManager
            ->getRepository(Vertical::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $result;
    }

    public function activeCapabilities(): array
    {
        /** @var list<Capability> $result */
        $result = $this->entityManager
            ->getRepository(Capability::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $result;
    }

    public function activeAddOns(): array
    {
        /** @var list<AddOn> $result */
        $result = $this->entityManager
            ->getRepository(AddOn::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $result;
    }
}
