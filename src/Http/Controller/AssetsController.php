<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Assets\AssetApiService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Offline/synthetic-only M10 HTTP surface. Never enabled on a live environment. */
final class AssetsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetApiService $assets,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/assets', name: 'api_assets_list', methods: ['GET'])]
    public function list(string $branchId): JsonResponse
    {
        [$tenant, $office] = $this->access($branchId, 'inventory.view');
        return $this->json(['assets' => $this->domain(fn (): array => $this->assets->listRecords($tenant, $office))]);
    }

    #[Route('/api/v1/branches/{branchId}/assets/{slug}', name: 'api_assets_view', methods: ['GET'])]
    public function view(string $branchId, string $slug): JsonResponse
    {
        [$tenant, $office] = $this->access($branchId, 'inventory.view');
        $asset = $this->domain(fn (): ?array => $this->assets->viewRecord($tenant, $office, $slug));
        if ($asset === null) {
            throw new NotFoundHttpException('Activo ficticio no encontrado.');
        }
        return $this->json(['asset' => $asset]);
    }

    #[Route('/api/v1/branches/{branchId}/assets', name: 'api_assets_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$tenant, $office] = $this->access($branchId, 'inventory.create');
        $payload = $this->payload($request, ['asset_ref', 'warranty_until']);
        $asset = $this->domain(fn (): array => $this->assets->createRecord($tenant, $office, $payload));
        return $this->json(['asset' => $asset], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/assets/{slug}/state', name: 'api_assets_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$tenant, $office] = $this->access($branchId, 'inventory.update');
        $payload = $this->payload($request, ['state', 'evidence_ref']);
        $asset = $this->domain(fn (): array => $this->assets->changeState($tenant, $office, $slug, $payload));
        return $this->json(['asset' => $asset]);
    }

    /** @return array{Tenant,Branch} */
    private function access(string $branchId, string $permission): array
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_ASSETS_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        )) {
            throw new NotFoundHttpException('Activos sintéticos no disponibles.');
        }
        [, $tenant, $office] = $this->authorizedBranchScope($branchId, $permission);
        return [$tenant, $office];
    }
}
