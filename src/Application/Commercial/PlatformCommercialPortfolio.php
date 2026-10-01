<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\UsageLedger;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlatformCommercialPortfolio
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformCommercialTenantSummary $subscriptionSummary,
    ) {
    }

    /**
     * @return list<array{
     *   tenant: array{id:string,name:string,slug:string},
     *   commercial_subscription: array{status:string,subscription:array<string,mixed>|null},
     *   usage: list<array{
     *     tenant_id:string,
     *     metric:string,
     *     aggregation:string,
     *     quantity:int,
     *     window_start:string,
     *     window_end:string,
     *     observed_at:string
     *   }>
     * }>
     */
    public function all(): array
    {
        $tenants = $this->entityManager
            ->getRepository(Tenant::class)
            ->findBy([], ['name' => 'ASC', 'id' => 'ASC']);

        $portfolio = [];
        foreach ($tenants as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $ledger = new UsageLedger($tenant->id());
            $observations = $this->entityManager
                ->getRepository(UsageObservation::class)
                ->findBy(['tenantId' => $tenant->id()]);

            foreach ($observations as $observation) {
                if (!$observation instanceof UsageObservation) {
                    continue;
                }

                $ledger->add($observation->toRecord());
            }

            $portfolio[] = [
                'tenant' => [
                    'id' => $tenant->id(),
                    'name' => $tenant->name(),
                    'slug' => $tenant->slug(),
                ],
                'commercial_subscription' => $this->subscriptionSummary
                    ->forTenant($tenant->id()),
                'usage' => $ledger->snapshot(),
            ];
        }

        return $portfolio;
    }
}
