<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\OperationsSummaryService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Production\Entity\ProductionOrder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OperationsSummaryController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionEntitlementContextFactory $entitlementContexts,
        private readonly EntitlementResolver $entitlementResolver,
        private readonly OperationsSummaryService $summaries,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/production/operations-summary', name: 'api_operations_summary', methods: ['GET'])]
    public function summary(string $branchId, Request $request): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.view',
        );
        if ($request->query->count() !== 0) {
            throw new BadRequestHttpException('El resumen no admite filtros sin verificar.');
        }
        /** @var EntitlementSnapshot $entitlements */
        $entitlements = $this->domain(fn (): EntitlementSnapshot =>
            $this->entitlementResolver->resolve(
                $this->entitlementContexts->forTenant(
                    $tenant->id(),
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ),
            )
        );
        if (!$this->domain(fn (): bool => $entitlements->addOn('production-lite'))) {
            throw new UnprocessableEntityHttpException(
                'Producción Lite no está habilitada para este tenant.',
            );
        }
        $source = $this->entityManager->getRepository(InventorySource::class)
            ->findOneBy(['tenant' => $tenant, 'branch' => $branch]);
        if (!$source instanceof InventorySource
            || $source->type() !== InventorySource::TYPE_BRANCH
            || !$source->isActive()) {
            throw new NotFoundHttpException('Fuente activa de la sede no encontrada.');
        }

        /** @var list<ProductionOrder> $orders */
        $orders = $this->entityManager->getRepository(ProductionOrder::class)
            ->findBy(
                ['tenant' => $tenant, 'source' => $source],
                ['createdAt' => 'DESC'],
                51,
            );
        return $this->json([
            'branch_id' => $branch->id(),
            'source_id' => $source->id(),
            'sample_limited' => count($orders) > 50,
            ...$this->summaries->summarize(
                $tenant,
                $source,
                array_slice($orders, 0, 50),
            ),
        ]);
    }
}
