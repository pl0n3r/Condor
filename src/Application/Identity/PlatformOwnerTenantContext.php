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
    public const DEFAULT_PAGE_SIZE = 24;
    public const MAX_PAGE_SIZE = 50;

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
            'tenant_count' => $this->entityManager
                ->getRepository(Tenant::class)
                ->count([]),
            'user_count' => $this->entityManager
                ->getRepository(User::class)
                ->count([]),
            'branch_count' => $this->entityManager
                ->getRepository(Branch::class)
                ->count([]),
            'active_membership_count' => $this->entityManager
                ->getRepository(Membership::class)
                ->count(['active' => true]),
        ];
    }

    /**
     * @return array{
     *   items: list<array{
     *     id: string,
     *     name: string,
     *     slug: string,
     *     branch_count: int,
     *     active_membership_count: int
     *   }>,
     *   page: int,
     *   per_page: int,
     *   total: int,
     *   has_previous: bool,
     *   has_next: bool
     * }
     */
    public function tenantPage(
        int $page,
        int $perPage = self::DEFAULT_PAGE_SIZE,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $repository = $this->entityManager->getRepository(Tenant::class);
        $total = $repository->count([]);
        $offset = ($page - 1) * $perPage;

        $rows = $repository->findBy(
            [],
            ['name' => 'ASC', 'id' => 'ASC'],
            $perPage,
            $offset,
        );
        $tenants = array_values(array_filter(
            $rows,
            static fn (mixed $tenant): bool => $tenant instanceof Tenant,
        ));
        $tenantIds = array_map(
            static fn (Tenant $tenant): string => $tenant->id(),
            $tenants,
        );
        $branchCounts = $this->branchCountsByTenant($tenantIds);
        $membershipCounts = $this->activeMembershipCountsByTenant($tenantIds);

        return [
            'items' => array_values(array_map(
                static fn (Tenant $tenant): array => [
                    'id' => $tenant->id(),
                    'name' => $tenant->name(),
                    'slug' => $tenant->slug(),
                    'branch_count' => $branchCounts[$tenant->id()] ?? 0,
                    'active_membership_count' => (
                        $membershipCounts[$tenant->id()] ?? 0
                    ),
                ],
                $tenants,
            )),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_previous' => $page > 1,
            'has_next' => ($offset + count($tenants)) < $total,
        ];
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
        $tenant = $this->entityManager
            ->getRepository(Tenant::class)
            ->find($tenantId);

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

    /**
     * @param list<string> $tenantIds
     * @return array<string, int>
     */
    private function branchCountsByTenant(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('IDENTITY(branch.tenant) AS tenant_id')
            ->addSelect('COUNT(branch.id) AS aggregate_count')
            ->from(Branch::class, 'branch')
            ->where('IDENTITY(branch.tenant) IN (:tenantIds)')
            ->setParameter('tenantIds', $tenantIds)
            ->groupBy('branch.tenant')
            ->getQuery()
            ->getArrayResult();

        return $this->indexCounts($rows);
    }

    /**
     * @param list<string> $tenantIds
     * @return array<string, int>
     */
    private function activeMembershipCountsByTenant(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('IDENTITY(membership.tenant) AS tenant_id')
            ->addSelect('COUNT(membership.id) AS aggregate_count')
            ->from(Membership::class, 'membership')
            ->where('membership.active = :active')
            ->andWhere('IDENTITY(membership.tenant) IN (:tenantIds)')
            ->setParameter('active', true)
            ->setParameter('tenantIds', $tenantIds)
            ->groupBy('membership.tenant')
            ->getQuery()
            ->getArrayResult();

        return $this->indexCounts($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function indexCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $tenantId = $row['tenant_id'] ?? null;
            if (!is_string($tenantId) || $tenantId === '') {
                continue;
            }

            $counts[$tenantId] = (int) ($row['aggregate_count'] ?? 0);
        }

        return $counts;
    }
}
