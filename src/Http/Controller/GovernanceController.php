<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Governance\GovernanceApiService;
use App\Application\Governance\GovernanceRecordService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Entity\Membership;
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

/** Synthetic governance trial, disabled outside explicitly allowed development/test contexts. */
final class GovernanceController extends AbstractController
{
    use BranchApiSupport {
        requireCsrf as private governanceValidateCsrf;
    }

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly GovernanceRecordService $governanceRecords,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/governance', name: 'api_governance_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $scope = $this->scope($tenant->id(), $branch->id());
        $records = $this->domain(fn (): array => GovernanceApiService::listRecords($this->store($request), $scope));
        $this->recordAudit($tenant, $user, 'governance.synthetic.listed', 'fixture:governance/list');
        return $this->syntheticJson(['records' => $records]);
    }

    #[Route('/api/v1/branches/{branchId}/governance/{kind}/{slug}', name: 'api_governance_view', methods: ['GET'])]
    public function view(string $branchId, string $kind, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $ref = 'fixture:'.$kind.'/'.$slug;
        $record = $this->domain(fn (): ?array => GovernanceApiService::viewRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Referencia sintética no encontrada.');
        }
        $this->recordAudit($tenant, $user, 'governance.synthetic.viewed', $ref);
        return $this->syntheticJson(['record' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/governance', name: 'api_governance_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.create');
        $payload = $this->payload($request, ['kind', 'ref']);
        $result = $this->domain(fn (): array => GovernanceApiService::createRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $payload,
        ));
        $request->getSession()->set('condor_governance_synthetic', $result['store']);
        $this->recordAudit($tenant, $user, 'governance.synthetic.created', $result['record']['ref']);
        return $this->syntheticJson(['record' => $result['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/governance/{kind}/{slug}/state', name: 'api_governance_state', methods: ['PATCH'])]
    public function state(string $branchId, string $kind, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.update');
        $payload = $this->payload($request, ['state']);
        $ref = 'fixture:'.$kind.'/'.$slug;
        $result = $this->domain(fn (): array => GovernanceApiService::changeState(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
            is_string($payload['state'] ?? null) ? $payload['state'] : '',
        ));
        $request->getSession()->set('condor_governance_synthetic', $result['store']);
        $this->recordAudit($tenant, $user, 'governance.synthetic.state_changed', $ref);
        return $this->syntheticJson(['record' => $result['record']]);
    }

    /** @return array<string,mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get('condor_governance_synthetic', []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Registro sintético no disponible.');
        }
        return $store;
    }

    private function scope(string $tenantId, string $branchId): string
    {
        return hash('sha256', $tenantId.'|'.$branchId);
    }

    /** @param array<string,mixed> $payload */
    private function syntheticJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function syntheticOnly(): void
    {
        $rawEnvironment = $this->getParameter('kernel.environment');
        $environment = is_string($rawEnvironment) ? $rawEnvironment : '';
        if (($environment === 'test' && getenv('CONDOR_GOVERNANCE_SYNTHETIC_API') === '0')
            || ($environment !== 'test'
            && ($environment !== 'dev'
                || getenv('CONDOR_GOVERNANCE_SYNTHETIC_API') !== '1'
                || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'))
        ) {
            throw new NotFoundHttpException('Gobierno sintético no disponible.');
        }
    }

    private function recordAudit(Tenant $tenant, User $user, string $action, string $ref): void
    {
        $this->audit($tenant, $user, $action, GovernanceApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
    // Gobierno corporativo real-record bridge: owner-only, opaque refs, flag OFF by default.
    /**
     * Owner-only persisted Gobierno corporativo bridge: both read routes use one guarded
     * handler; both writes share flag, CSRF, and scope validation.
     */
    #[Route(
        '/api/v1/branches/{branchId}/governance-real/records',
        name: 'api_governance_real_list',
        methods: ['GET'],
    )]
    public function governanceRealList(string $branchId): JsonResponse
    {
        return $this->governanceRealRead($branchId, null);
    }

    #[Route(
        '/api/v1/branches/{branchId}/governance-real/records/{ref}',
        name: 'api_governance_real_detail',
        methods: ['GET'],
    )]
    public function governanceRealView(string $branchId, string $ref): JsonResponse
    {
        return $this->governanceRealRead($branchId, $ref);
    }

    #[Route(
        '/api/v1/branches/{branchId}/governance-real/records',
        name: 'api_governance_real_create',
        methods: ['POST'],
    )]
    public function governanceRealCreate(string $branchId, Request $request): JsonResponse
    {
        return $this->governanceRealWrite($branchId, $request, null);
    }

    #[Route(
        '/api/v1/branches/{branchId}/governance-real/records/{ref}/state',
        name: 'api_governance_real_state',
        methods: ['PATCH'],
    )]
    public function governanceRealState(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->governanceRealWrite($branchId, $request, $ref);
    }

    private function governanceRealRead(string $branchId, ?string $ref): JsonResponse
    {
        if (($off = $this->governanceRealDisabled()) !== null) {
            return $off;
        }
        [, $tenant, $company] = $this->governanceRealScope($branchId, 'inventory.view');
        $record = $this->domain(fn (): ?array => $ref === null
            ? $this->governanceRecords->all($tenant, $company)
            : $this->governanceRecords->one($tenant, $company, $ref));

        if ($record === null) {
            throw new NotFoundHttpException('Instrumento de Gobierno no encontrado.');
        }
        return $this->governanceRealJson(
            $ref === null ? ['records' => $record] : ['record' => $record],
        );
    }

    private function governanceRealWrite(string $branchId, Request $request, ?string $ref): JsonResponse
    {
        if (($off = $this->governanceRealDisabled()) !== null) {
            return $off;
        }
        $this->governanceValidateCsrf($request);
        $operation = $ref === null ? 'inventory.create' : 'inventory.update';
        [$actor, $tenant, $company] = $this->governanceRealScope($branchId, $operation);

        $input = $this->payload(
            $request,
            $ref === null ? ['record_ref', 'kind'] : ['state', 'expected_version'],
        );
        if ($ref !== null
            && (!is_string($input['state'] ?? null) || !is_int($input['expected_version'] ?? null))) {
            throw new BadRequestHttpException('Estado y versión obligatorios.');
        }

        $result = $this->domain(fn (): array => $ref === null
            ? $this->governanceRecords->add($tenant, $company, $actor, $input)
            : $this->governanceRecords->transition(
                $tenant, $company, $actor, $ref, $input['state'], $input['expected_version'],
            ));
        return $this->governanceRealJson(
            ['record' => $result],
            $ref === null ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** @return array{string,string,string} */
    private function governanceRealScope(string $branchId, string $permission): array
    {
        $scope = $this->authorizedBranchScope($branchId, $permission);
        [$actor, $tenant, $branch, $membership] = $scope;
        $allowed = $actor->isActive() && $membership->isActive()
            && $membership->roleKey() === Membership::ROLE_OWNER;
        if (!$allowed) {
            throw new AccessDeniedHttpException('Solo el propietario puede gestionar Gobierno corporativo.');
        }

        $legalEntity = $branch->legalEntity();
        if ($legalEntity?->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social no disponible.');
        }
        return [$actor->id(), $tenant->id(), $legalEntity->id()];
    }

    private function governanceRealDisabled(): ?JsonResponse
    {
        if (getenv('GOVERNANCE_REAL_DATA_ENABLED') === 'true') {
            return null;
        }
        return $this->governanceRealJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    /** @param array<string,mixed> $payload */
    private function governanceRealJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }


}
