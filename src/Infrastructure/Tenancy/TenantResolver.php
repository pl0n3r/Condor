<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class TenantResolver
{
    private const PLATFORM_HOSTS = [
        'condorapp.com.co',
        'www.condorapp.com.co',
        'localhost',
        '127.0.0.1',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public static function isPlatformHost(string $host): bool
    {
        return in_array(strtolower(rtrim($host, '.')), self::PLATFORM_HOSTS, true);
    }

    public function resolve(Request $request): ?Tenant
    {
        $host = strtolower(rtrim($request->getHost(), '.'));

        if (self::isPlatformHost($host)) {
            $slug = $request->attributes->get('tenant_slug');
            if (!is_string($slug) || $slug === '') {
                return null;
            }

            return $this->entityManager
                ->getRepository(Tenant::class)
                ->findOneBy(['slug' => strtolower($slug)]);
        }

        $domain = $this->entityManager->getRepository(TenantDomain::class)->findOneBy([
            'hostname' => $host,
            'verified' => true,
        ]);

        return $domain instanceof TenantDomain ? $domain->tenant() : null;
    }
}
