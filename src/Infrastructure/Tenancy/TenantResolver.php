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

    public function resolve(Request $request): ?Tenant
    {
        $slug = $request->attributes->get('tenant_slug');
        if (is_string($slug) && $slug !== '') {
            return $this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => strtolower($slug)]);
        }

        $host = strtolower(rtrim($request->getHost(), '.'));
        if (in_array($host, self::PLATFORM_HOSTS, true)) {
            return null;
        }

        $domain = $this->entityManager->getRepository(TenantDomain::class)->findOneBy([
            'hostname' => $host,
            'verified' => true,
        ]);

        return $domain instanceof TenantDomain ? $domain->tenant() : null;
    }
}
