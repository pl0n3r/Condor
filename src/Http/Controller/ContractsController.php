<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Contracts\ContractApiService;
use App\Application\Contracts\ContractRecordService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Entity\Membership;
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

/** HTTP facade for synthetic-only labour contract previews. Never active in production. */
final class ContractsController extends AbstractController
{
    use BranchApiSupport;

    private const SESSION_KEY = 'condor_contracts_synthetic';

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContractRecordService $records,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/contracts', name: 'api_contracts_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        return $this->execute($branchId, $request, 'list');
    }

    #[Route('/api/v1/branches/{branchId}/contracts/{kind}/{slug}', name: 'api_contracts_view', methods: ['GET'])]
    public function view(string $branchId, string $kind, string $slug, Request $request): JsonResponse
    {
        return $this->execute($branchId, $request, 'view', 'fixture:'.$kind.'/'.$slug);
    }

    #[Route('/api/v1/branches/{branchId}/contracts', name: 'api_contracts_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        return $this->execute($branchId, $request, 'create');
    }

    #[Route('/api/v1/branches/{branchId}/contracts/{kind}/{slug}/state', name: 'api_contracts_state', methods: ['PATCH'])]
    public function state(string $branchId, string $kind, string $slug, Request $request): JsonResponse
    {
        return $this->execute($branchId, $request, 'state', 'fixture:'.$kind.'/'.$slug);
    }

    /**
     * The persisted contract routes are separate from the fixture endpoints.
     * Every operation runs through the same OFF/tenant/owner/CSRF gateway.
     */
    #[Route('/api/v1/branches/{branchId}/contracts/real-records', name: 'api_contracts_real_list', methods: ['GET'], priority: 20)]
    public function realList(string $branchId): JsonResponse
    {
        return $this->realHandle($branchId, 'list');
    }

    #[Route('/api/v1/branches/{branchId}/contracts/real-records/{ref}', name: 'api_contracts_real_detail', methods: ['GET'], priority: 20)]
    public function realDetail(string $branchId, string $ref): JsonResponse
    {
        return $this->realHandle($branchId, 'detail', null, $ref);
    }

    #[Route('/api/v1/branches/{branchId}/contracts/real-records', name: 'api_contracts_real_create', methods: ['POST'], priority: 20)]
    public function realCreate(string $branchId, Request $request): JsonResponse
    {
        return $this->realHandle($branchId, 'create', $request);
    }

    #[Route('/api/v1/branches/{branchId}/contracts/real-records/{ref}/state', name: 'api_contracts_real_state', methods: ['PATCH'], priority: 20)]
    public function realState(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->realHandle($branchId, 'state', $request, $ref);
    }

    /** A server-side switch is evaluated before session, identity, CSRF or SQL. */
    private function realHandle(string $branchId, string $action, ?Request $request = null, ?string $ref = null): JsonResponse
    {
        if (getenv('CONTRACTS_REAL_DATA_ENABLED') !== 'true') {
            return $this->privateJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
        }

        $permission = match ($action) {
            'list', 'detail' => 'inventory.view',
            'create' => 'inventory.create',
            'state' => 'inventory.update',
            default => throw new NotFoundHttpException(),
        };
        if ($action === 'create' || $action === 'state') {
            $this->requireCsrf($request ?? throw new BadRequestHttpException('Solicitud incompleta.'));
        }
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if (!$actor->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Contratación reservada al propietario autorizado.');
        }
        $legalEntity = $branch->legalEntity();
        if ($legalEntity === null || $legalEntity->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social no disponible.');
        }

        $tenantId = $tenant->id();
        $companyId = $legalEntity->id();
        $record = $this->domain(function () use ($action, $request, $ref, $tenantId, $companyId, $actor): mixed {
            return match ($action) {
                'list' => $this->records->listRecords($tenantId, $companyId),
                'detail' => $this->records->detail($tenantId, $companyId, $ref ?? ''),
                'create' => $this->records->create(
                    $tenantId, $companyId, $actor->id(),
                    $this->payload($request ?? throw new BadRequestHttpException(), [
                        'record_ref', 'worker_ref', 'template_ref', 'kind',
                    ]),
                ),
                'state' => $this->changeRealContract($request, $tenantId, $companyId, $actor->id(), $ref),
                default => throw new NotFoundHttpException(),
            };
        });
        if ($action === 'detail' && $record === null) {
            throw new NotFoundHttpException('Registro de contratación no encontrado.');
        }
        $name = $action === 'list' ? 'records' : 'record';
        return $this->privateJson([$name => $record], $action === 'create' ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** @return array<string,mixed> */
    private function changeRealContract(?Request $request, string $tenant, string $company, string $actor, ?string $ref): array
    {
        $input = $this->payload(
            $request ?? throw new BadRequestHttpException(),
            ['state', 'expected_version'],
        );
        if (!is_string($input['state'] ?? null) || !is_int($input['expected_version'] ?? null)) {
            throw new BadRequestHttpException('Estado y versión obligatorios.');
        }
        return $this->records->changeState(
            $tenant, $company, $actor, $ref ?? '', $input['state'], $input['expected_version'],
        );
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function execute(string $branchId, Request $request, string $action, ?string $ref = null): JsonResponse
    {
        $this->requireSyntheticEnvironment();
        if ($action === 'create' || $action === 'state') {
            $this->requireCsrf($request);
        }
        $permission = match ($action) {
            'list', 'view' => 'inventory.view',
            'create' => 'inventory.create',
            'state' => 'inventory.update',
            default => throw new \LogicException('Operación sintética no admitida.'),
        };
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, $permission);
        $scope = $this->companyScope($tenant, $branch);
        $session = $request->getSession();
        $store = $session->get(self::SESSION_KEY, []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Almacén de prueba no disponible.');
        }
        $recordRef = $ref ?? 'fixture:contract/list';
        if ($action === 'list') {
            $records = $this->domain(fn (): array => ContractApiService::listRecords($store, $scope));
            $this->recordAudit($tenant, $user, 'contracts.synthetic.listed', $recordRef);
            return $this->json(['records' => $records, 'synthetic_only' => true]);
        }
        if ($action === 'view') {
            $record = $this->domain(fn (): ?array => ContractApiService::viewRecord($store, $scope, $recordRef));
            if ($record === null) {
                throw new NotFoundHttpException('Contrato ficticio no encontrado.');
            }
            $this->recordAudit($tenant, $user, 'contracts.synthetic.viewed', $recordRef);
            return $this->json(['record' => $record, 'synthetic_only' => true]);
        }
        if ($action === 'create') {
            $payload = $this->payload($request, ['kind', 'ref']);
            $result = $this->domain(fn (): array => ContractApiService::createRecord($store, $scope, $payload));
            $recordRef = $result['record']['ref'];
        } else {
            $payload = $this->payload($request, ['state']);
            $result = $this->domain(fn (): array => ContractApiService::changeState(
                $store, $scope, $recordRef,
                is_string($payload['state'] ?? null) ? $payload['state'] : '',
            ));
        }
        $event = $action === 'create' ? 'contracts.synthetic.created' : 'contracts.synthetic.state_changed';
        $this->recordAudit($tenant, $user, $event, $recordRef);
        $session->set(self::SESSION_KEY, $result['store']);
        return $this->json(
            ['record' => $result['record'], 'synthetic_only' => true],
            $action === 'create' ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    private function companyScope(Tenant $tenant, Branch $branch): string
    {
        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa no disponible para la sede.');
        }
        return hash('sha256', implode('|', [$tenant->id(), $company->id(), $branch->id()]));
    }

    private function requireSyntheticEnvironment(): void
    {
        $parameter = $this->getParameter('kernel.environment');
        $environment = is_string($parameter) ? $parameter : '';
        if ($environment !== 'test'
            && ($environment !== 'dev' || getenv('CONDOR_CONTRACTS_SYNTHETIC_API') !== '1')
        ) {
            throw new NotFoundHttpException('Contratación sintética no disponible.');
        }
    }

    private function recordAudit(Tenant $tenant, User $actor, string $event, string $ref): void
    {
        $this->audit($tenant, $actor, $event, ContractApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
