<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class BranchAuthorization
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<string> */
    public function permissions(User $user, Tenant $tenant, Branch $branch): array
    {
        if ($branch->tenant()->id() !== $tenant->id()) {
            throw new AccessDeniedException('La sede no pertenece a la empresa activa.');
        }

        $membership = $this->membership($user, $tenant);
        if ($membership->roleKey() === Membership::ROLE_OWNER) {
            return PermissionCatalog::all();
        }

        return $this->assignedPermissions($membership, $tenant, $branch);
    }

    /**
     * @param list<string> $permissions
     */
    public function hasAnyPermission(
        User $user,
        Tenant $tenant,
        array $permissions,
    ): bool {
        $requested = PermissionCatalog::normalize($permissions);
        $membership = $this->membership($user, $tenant);
        if ($membership->roleKey() === Membership::ROLE_OWNER) {
            return true;
        }

        $effective = $this->assignedPermissions($membership, $tenant);

        return array_intersect($requested, $effective) !== [];
    }

    public function canAccessBranch(
        User $user,
        Tenant $tenant,
        Branch $branch,
    ): bool {
        if ($branch->tenant()->id() !== $tenant->id()) {
            return false;
        }

        $membership = $this->membership($user, $tenant);
        if ($membership->roleKey() === Membership::ROLE_OWNER) {
            return true;
        }

        $assignments = $this->entityManager
            ->getRepository(BranchRoleAssignment::class)
            ->findBy([
                'tenant' => $tenant,
                'membership' => $membership,
                'branch' => $branch,
            ]);

        foreach ($assignments as $assignment) {
            if (
                $assignment instanceof BranchRoleAssignment
                && $assignment->role()->isActive()
            ) {
                return true;
            }
        }

        return false;
    }

    public function require(
        User $user,
        Tenant $tenant,
        Branch $branch,
        string $permission,
    ): Membership {
        PermissionCatalog::normalize([$permission]);

        $membership = $this->membership($user, $tenant);
        if ($membership->roleKey() === Membership::ROLE_OWNER) {
            return $membership;
        }

        if (!in_array($permission, $this->permissions($user, $tenant, $branch), true)) {
            throw new AccessDeniedException('No tienes permiso para realizar esta acción.');
        }

        return $membership;
    }

    /**
     * @return list<string>
     */
    private function assignedPermissions(
        Membership $membership,
        Tenant $tenant,
        ?Branch $branch = null,
    ): array {
        $criteria = [
            'tenant' => $tenant,
            'membership' => $membership,
        ];
        if ($branch instanceof Branch) {
            $criteria['branch'] = $branch;
        }

        $permissions = [];
        $assignments = $this->entityManager
            ->getRepository(BranchRoleAssignment::class)
            ->findBy($criteria);

        foreach ($assignments as $assignment) {
            if (
                !$assignment instanceof BranchRoleAssignment
                || !$assignment->role()->isActive()
            ) {
                continue;
            }

            foreach ($assignment->role()->permissions() as $permission) {
                $permissions[$permission] = true;
            }
        }

        $result = array_keys($permissions);
        sort($result, SORT_STRING);

        return array_values($result);
    }

    public function membership(User $user, Tenant $tenant): Membership
    {
        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy([
            'user' => $user,
            'tenant' => $tenant,
            'active' => true,
        ]);

        if (!$membership instanceof Membership) {
            throw new AccessDeniedException('No tienes acceso a esta empresa.');
        }

        return $membership;
    }

    public function isOwner(Membership $membership): bool
    {
        return $membership->roleKey() === Membership::ROLE_OWNER;
    }
}
