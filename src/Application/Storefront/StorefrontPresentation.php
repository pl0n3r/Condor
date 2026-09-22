<?php

declare(strict_types=1);

namespace App\Application\Storefront;

use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StorefrontPresentation
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *   name: string,
     *   slug: string,
     *   headline: string,
     *   description: string,
     *   canonical: string
     * }
     */
    public function publicIdentity(Tenant $tenant): array
    {
        $profile = $this->entityManager
            ->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);

        $primaries = $this->entityManager
            ->getRepository(TenantDomain::class)
            ->findBy([
                'tenant' => $tenant,
                'primary' => true,
                'verified' => true,
            ], limit: 2);
        $primary = count($primaries) === 1
            && $primaries[0] instanceof TenantDomain
                ? $primaries[0]
                : null;

        // Fail closed to the Condor URL if legacy data violates the unique-primary invariant.
        $canonical = $primary instanceof TenantDomain
            ? 'https://'.$primary->hostname().'/'
            : 'https://www.condorapp.com.co/'.$tenant->slug();

        return [
            'name' => $tenant->name(),
            'slug' => $tenant->slug(),
            'headline' => $profile instanceof StorefrontProfile ? $profile->headline() : '',
            'description' => $profile instanceof StorefrontProfile ? $profile->description() : '',
            'canonical' => $canonical,
        ];
    }

    /**
     * This is a database registration state, not DNS/TLS or a live release smoke.
     *
     * @return list<array{host: string, primary: bool, verified: bool, status: string}>
     */
    public function registeredDomains(Tenant $tenant): array
    {
        $domains = $this->entityManager->getRepository(TenantDomain::class)
            ->findBy(['tenant' => $tenant], ['hostname' => 'ASC']);

        return array_map(
            static fn (TenantDomain $domain): array => [
                'host' => $domain->hostname(),
                'primary' => $domain->isPrimary(),
                'verified' => $domain->isVerified(),
                'status' => $domain->isVerified()
                    ? 'Verificado en Condor; DNS y TLS requieren comprobación operativa'
                    : 'Pendiente de verificación',
            ],
            $domains,
        );
    }
}
