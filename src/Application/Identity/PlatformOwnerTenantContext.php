<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PlatformOwnerTenantContext
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *   tenant_count: int,
     *   user_count: int,
     *   branch_count: int,
     *   active_membership_count: int
     * }
     */
    public function metrics(): array
    {
        return [
            'tenant_count' => $this->entityManager->getRepository(Tenant::class)->count([]),
            'user_count' => $this->entityManager->getRepository(User::class)->count([]),
            'branch_count' => $this->entityManager->getRepository(Branch::class)->count([]),
            'active_membership_count' => $this->entityManager
                ->getRepository(Membership::class)
                ->count(['active' => true]),
        ];
    }

    /**
     * @return list<array{
     *   id: string,
     *   name: string,
     *   slug: string,
     *   branch_count: int,
     *   active_membership_count: int
     * }>
     */
    public function tenants(): array
    {
        $tenants = $this->entityManager->getRepository(Tenant::class)->findBy(
            [],
            ['name' => 'ASC'],
        );

        return array_values(array_map(
            fn (Tenant $tenant): array => $this->tenantSummary($tenant),
            array_values(array_filter(
                $tenants,
                static fn (mixed $tenant): bool => $tenant instanceof Tenant,
            )),
        ));
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   slug: string,
     *   branch_count: int,
     *   active_membership_count: int,
     *   branches: list<array{
     *     id: string,
     *     name: string,
     *     slug: string,
     *     is_default: bool
     *   }>
     * }
     */
    public function tenant(string $tenantId): array
    {
        $tenant = $this->entityManager->getRepository(Tenant::class)->find($tenantId);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }

        $branches = $this->entityManager->getRepository(Branch::class)->findBy(
            ['tenant' => $tenant],
            ['default' => 'DESC', 'name' => 'ASC'],
        );

        return [
            ...$this->tenantSummary($tenant),
            'branches' => array_values(array_map(
                static fn (Branch $branch): array => [
                    'id' => $branch->id(),
                    'name' => $branch->name(),
                    'slug' => $branch->slug(),
                    'is_default' => $branch->isDefault(),
                ],
                array_values(array_filter(
                    $branches,
                    static fn (mixed $branch): bool => $branch instanceof Branch,
                )),
            )),
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   slug: string,
     *   branch_count: int,
     *   active_membership_count: int
     * }
     */
    private function tenantSummary(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id(),
            'name' => $tenant->name(),
            'slug' => $tenant->slug(),
            'branch_count' => $this->entityManager
                ->getRepository(Branch::class)
                ->count(['tenant' => $tenant]),
            'active_membership_count' => $this->entityManager
                ->getRepository(Membership::class)
                ->count(['tenant' => $tenant, 'active' => true]),
        ];
    }
}
