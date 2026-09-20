<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class CurrentTenantForUser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    public function resolve(User $user): Tenant
    {
        $activeTenant = $this->tenantContext->current();

        if ($activeTenant !== null) {
            $membership = $this->findMembership($user, $activeTenant);
            if (!$membership instanceof Membership) {
                throw new AccessDeniedException('No tienes acceso a esta empresa.');
            }

            return $activeTenant;
        }

        $memberships = $this->entityManager->getRepository(Membership::class)->findBy(
            ['user' => $user, 'active' => true],
            ['id' => 'ASC'],
            2,
        );

        if ($memberships === []) {
            throw new AccessDeniedException('No tienes una empresa activa asociada.');
        }

        if (count($memberships) > 1) {
            throw new AccessDeniedException('Selecciona explícitamente la empresa que quieres administrar.');
        }

        $tenant = $memberships[0]->tenant();
        $this->tenantContext->set($tenant);

        return $tenant;
    }

    private function findMembership(User $user, Tenant $tenant): ?Membership
    {
        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy([
            'user' => $user,
            'tenant' => $tenant,
            'active' => true,
        ]);

        return $membership instanceof Membership ? $membership : null;
    }
}
