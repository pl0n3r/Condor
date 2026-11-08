<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Assets\{AssetApiService, AssetRecordService};
use App\Domain\Identity\Entity\Membership;
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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Legacy synthetic assets plus opt-in persisted, tenant-scoped asset records. */
final class AssetsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetApiService $assets,
        private readonly AssetRecordService $assetRecords,
        private readonly CsrfTokenManagerInterface $csrfTokens,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/assets', name: 'api_assets_list', methods: ['GET'])]
    public function list(string $branchId): JsonResponse
    {
        [$tenant, $office] = $this->access($branchId, 'inventory.view');
        return $this->json([
            'assets' => $this->domain(fn (): array => $this->assets->listRecords($tenant, $office)),
            'csrf_token' => $this->csrfTokens->getToken('branch_access')->getValue(),
        ]);
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

    #[Route('/api/v1/branches/{branchId}/assets/{slug}/responsible', name: 'api_assets_responsible', methods: ['PATCH'])]
    public function responsible(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$tenant, $office] = $this->access($branchId, 'inventory.update');
        $payload = $this->payload($request, ['responsible_ref']);
        $asset = $this->domain(fn (): array => $this->assets->assignResponsible($tenant, $office, $slug, $payload));
        return $this->json(['asset' => $asset]);
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
    /**
     * Persisted asset endpoints are strictly opt-in, separate from synthetic /assets.
     * No authenticated lookups or database access occur while the flag is OFF.
     */
    #[Route('/api/v1/branches/{branchId}/assets-real/records', name: 'api_assets_real_list', methods: ['GET'])]
    public function assetsRealList(string $branchId, Request $request): JsonResponse
    {
        if (($disabled = $this->assetsRealDisabled()) !== null) {
            return $disabled;
        }
        $after = $request->query->get('after');
        if ($after !== null && (!is_string($after) || $after === '')) {
            throw new BadRequestHttpException('Cursor de activos inválido.');
        }
        [, $tenant, $company] = $this->assetsRealScope($branchId, 'inventory.view');
        return $this->assetsRealJson(
            $this->domain(fn (): array => $this->assetRecords->page($tenant, $company, $after)),
        );
    }

    #[Route('/api/v1/branches/{branchId}/assets-real/records/{ref}', name: 'api_assets_real_view', methods: ['GET'])]
    public function assetsRealView(string $branchId, string $ref): JsonResponse
    {
        if (($disabled = $this->assetsRealDisabled()) !== null) {
            return $disabled;
        }
        [, $tenant, $company] = $this->assetsRealScope($branchId, 'inventory.view');
        $record = $this->domain(fn (): ?array => $this->assetRecords->one($tenant, $company, $ref));
        if ($record === null) {
            throw new NotFoundHttpException('Activo no encontrado.');
        }
        return $this->assetsRealJson(['record' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/assets-real/records', name: 'api_assets_real_create', methods: ['POST'])]
    public function assetsRealCreate(string $branchId, Request $request): JsonResponse
    {
        return $this->assetsRealWrite($branchId, $request, null);
    }

    #[Route('/api/v1/branches/{branchId}/assets-real/records/{ref}/state', name: 'api_assets_real_state', methods: ['PATCH'])]
    public function assetsRealState(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->assetsRealWrite($branchId, $request, $ref);
    }

    private function assetsRealWrite(string $branchId, Request $request, ?string $ref): JsonResponse
    {
        if (($disabled = $this->assetsRealDisabled()) !== null) {
            return $disabled;
        }
        $this->requireCsrf($request);
        $permission = $ref === null ? 'inventory.create' : 'inventory.update';
        [$actor, $tenant, $company] = $this->assetsRealScope($branchId, $permission);
        $input = $this->payload(
            $request,
            $ref === null
                ? ['record_ref', 'category', 'warranty_until']
                : ['state', 'expected_version', 'responsible_ref', 'evidence_ref'],
        );
        if ($ref !== null && (!is_string($input['state'] ?? null)
            || !is_int($input['expected_version'] ?? null)
            || (array_key_exists('responsible_ref', $input)
                && $input['responsible_ref'] !== null && !is_string($input['responsible_ref']))
            || (array_key_exists('evidence_ref', $input)
                && $input['evidence_ref'] !== null && !is_string($input['evidence_ref'])))) {
            throw new BadRequestHttpException('Estado, versión o referencias inválidos.');
        }
        $record = $this->domain(fn (): array => $ref === null
            ? $this->assetRecords->add($tenant, $company, $actor, $input)
            : $this->assetRecords->transition(
                $tenant, $company, $actor, $ref,
                $input['state'], $input['expected_version'],
                $input['responsible_ref'] ?? null, $input['evidence_ref'] ?? null,
            ));
        return $this->assetsRealJson(
            ['record' => $record],
            $ref === null ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** @return array{string,string,string} */
    private function assetsRealScope(string $branchId, string $permission): array
    {
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if ($membership->roleKey() !== Membership::ROLE_OWNER
            || !$user->isActive() || !$membership->isActive()) {
            throw new AccessDeniedHttpException('Solo el propietario activo puede modificar activos.');
        }
        $legalEntity = $branch->legalEntity();
        if ($legalEntity?->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social ajena al tenant.');
        }
        return [$user->id(), $tenant->id(), $legalEntity->id()];
    }

    private function assetsRealDisabled(): ?JsonResponse
    {
        if (getenv('ASSETS_REAL_DATA_ENABLED') === 'true') {
            return null;
        }
        return $this->assetsRealJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    /** @param array<string,mixed> $body */
    private function assetsRealJson(array $body, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($body, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }


}
