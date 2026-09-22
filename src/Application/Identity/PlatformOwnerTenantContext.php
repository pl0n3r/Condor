<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
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
     * Señales funcionales agregadas de los últimos 30 días. Siempre
     * devuelve las claves conocidas en cero cuando aún no hay actividad,
     * para que el propietario vea un estado vacío explícito en vez de
     * un dato ausente o un placeholder.
     *
     * @return array<string, int>
     */
    public function functionalSignals(): array
    {
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-30 days');

        $counts = array_fill_keys([
            FunctionalSignal::TENANT_CREATED,
            FunctionalSignal::LOGIN_SUCCESS,
            FunctionalSignal::LOGIN_FAILURE,
            FunctionalSignal::AUTHORIZATION_DENIED,
            FunctionalSignal::ROLE_MODIFIED,
        ], 0);

        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('signal.type AS type')
            ->addSelect('COUNT(signal.id) AS aggregate_count')
            ->from(FunctionalSignal::class, 'signal')
            ->where('signal.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('signal.type')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $type = $row['type'] ?? null;
            if (!is_string($type) || !array_key_exists($type, $counts)) {
                continue;
            }

            $counts[$type] = (int) ($row['aggregate_count'] ?? 0);
        }

        return $counts;
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
            'catalog' => $this->catalog($tenant),
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
     * @return list<array{
     *   id: string,
     *   name: string,
     *   slug: string,
     *   description: string|null,
     *   variants: list<array{id: string, sku: string, name: string}>
     * }>
     */
    private function catalog(Tenant $tenant): array
    {
        $products = $this->entityManager
            ->getRepository(Product::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );
        $variants = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );

        $variantsByProduct = [];
        foreach ($variants as $variant) {
            if (
                !$variant instanceof ProductVariant
                || !$variant->product()->isActive()
            ) {
                continue;
            }

            $variantsByProduct[$variant->product()->id()][] = [
                'id' => $variant->id(),
                'sku' => $variant->sku(),
                'name' => $variant->name(),
            ];
        }

        $catalog = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $catalog[] = [
                'id' => $product->id(),
                'name' => $product->name(),
                'slug' => $product->slug(),
                'description' => $product->description(),
                'variants' => $variantsByProduct[$product->id()] ?? [],
            ];
        }

        return $catalog;
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
