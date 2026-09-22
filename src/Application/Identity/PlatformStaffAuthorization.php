<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\PlatformStaffGrant;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlatformStaffAuthorization
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function can(User $actor, Tenant $tenant, string $permission): bool
    {
        $normalized = PermissionCatalog::normalize([$permission]);
        [$module, $action] = explode('.', $normalized[0], 2);

        if (!$actor->isActive()) {
            return false;
        }
        if ($actor->hasRole(User::ROLE_PLATFORM_OWNER)) {
            return true;
        }
        if (!$actor->hasRole(User::ROLE_PLATFORM_STAFF)) {
            return false;
        }

        foreach ($this->grants($actor) as $grant) {
            if (
                $grant->moduleKey() === $module
                && $grant->covers($tenant)
                && $grant->allows($action)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function permissions(User $actor, Tenant $tenant): array
    {
        if (!$actor->isActive()) {
            return [];
        }
        if ($actor->hasRole(User::ROLE_PLATFORM_OWNER)) {
            return PermissionCatalog::all();
        }
        if (!$actor->hasRole(User::ROLE_PLATFORM_STAFF)) {
            return [];
        }

        $permissions = [];
        foreach ($this->grants($actor) as $grant) {
            if (!$grant->covers($tenant)) {
                continue;
            }

            foreach ($grant->actions() as $action) {
                $permissions[$grant->moduleKey().'.'.$action] = true;
            }
        }

        $result = array_keys($permissions);
        sort($result, SORT_STRING);

        return array_values($result);
    }

    public function canAccessTenant(User $actor, Tenant $tenant): bool
    {
        return $this->permissions($actor, $tenant) !== [];
    }

    /** @return list<Tenant> */
    public function accessibleTenants(User $actor): array
    {
        if (!$actor->isActive()) {
            return [];
        }
        if ($actor->hasRole(User::ROLE_PLATFORM_OWNER)) {
            return $this->allTenants();
        }
        if (!$actor->hasRole(User::ROLE_PLATFORM_STAFF)) {
            return [];
        }

        $tenants = [];
        foreach ($this->grants($actor) as $grant) {
            if ($grant->tenant() === null) {
                return $this->allTenants();
            }

            $tenants[$grant->tenant()->id()] = $grant->tenant();
        }

        $result = array_values($tenants);
        usort(
            $result,
            static fn (Tenant $left, Tenant $right): int => strcmp(
                $left->name(),
                $right->name(),
            ),
        );

        return $result;
    }

    /** @return list<PlatformStaffGrant> */
    private function grants(User $actor): array
    {
        $rows = $this->entityManager
            ->getRepository(PlatformStaffGrant::class)
            ->findBy(['staff' => $actor]);

        return array_values(array_filter(
            $rows,
            static fn (mixed $grant): bool => $grant instanceof PlatformStaffGrant,
        ));
    }

    /** @return list<Tenant> */
    private function allTenants(): array
    {
        $rows = $this->entityManager
            ->getRepository(Tenant::class)
            ->findBy([], ['name' => 'ASC']);

        return array_values(array_filter(
            $rows,
            static fn (mixed $tenant): bool => $tenant instanceof Tenant,
        ));
    }
}
