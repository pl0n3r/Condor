<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\CustomerService\{CustomerCaseApiService, SupportCaseService};
use App\Domain\Identity\Entity\Membership;
use App\Application\Identity\{BranchAuthorization, CurrentTenantForUser};
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Development-only synthetic service case API; no real customer data or integrations. */
final class CustomerServiceController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SupportCaseService $supportCaseRecords,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/customer-service', name: 'api_customer_service_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.view');
        $records = $this->domain(fn (): array => CustomerCaseApiService::listRecords(
            $this->store($request), $this->scope($tenant->id(), $branch->id()),
        ));
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.listed', 'fixture:case/list');
        return $this->json(['cases' => $records]);
    }

    #[Route('/api/v1/branches/{branchId}/customer-service/{slug}', name: 'api_customer_service_view', methods: ['GET'])]
    public function view(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.view');
        $ref = 'fixture:case/'.$slug;
        $scope = $this->scope($tenant->id(), $branch->id());
        $records = $this->store($request);
        $record = $this->domain(
            static fn (): ?array => CustomerCaseApiService::viewRecord($records, $scope, $ref),
        );
        if ($record === null) {
            throw new NotFoundHttpException('Caso sintético no encontrado.');
        }
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.viewed', $ref);
        return $this->json(['case' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/customer-service', name: 'api_customer_service_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.create');
        $payload = $this->payload($request, ['case_ref', 'type']);
        $result = $this->domain(fn (): array => CustomerCaseApiService::createRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $payload,
        ));
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.created', $result['record']['case_ref']);
        $request->getSession()->set('condor_customer_service_synthetic', $result['store']);
        return $this->json(['case' => $result['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/customer-service/{slug}/state', name: 'api_customer_service_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.update');
        $payload = $this->payload($request, ['state']);
        $ref = 'fixture:case/'.$slug;
        $result = $this->domain(fn (): array => CustomerCaseApiService::changeState(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
            is_string($payload['state'] ?? null) ? $payload['state'] : '',
        ));
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.state_changed', $ref);
        $request->getSession()->set('condor_customer_service_synthetic', $result['store']);
        return $this->json(['case' => $result['record']]);
    }

    /** @return array<string,mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get('condor_customer_service_synthetic', []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Casos sintéticos no disponibles.');
        }
        return $store;
    }

    private function scope(string $tenantId, string $branchId): string
    {
        return hash('sha256', $tenantId.'|'.$branchId);
    }

    private function syntheticOnly(): void
    {
        $raw = $this->getParameter('kernel.environment');
        $env = is_string($raw) ? $raw : '';
        if ($env !== 'test' && ($env !== 'dev'
            || getenv('CONDOR_CUSTOMER_SERVICE_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion')) {
            throw new NotFoundHttpException('Servicio al cliente sintético no disponible.');
        }
    }

    private function recordAudit(Tenant $tenant, User $user, string $action, string $ref): void
    {
        $this->audit($tenant, $user, $action, CustomerCaseApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
    /**
     * Owner-only persisted Procesos bridge: both read routes use one guarded
     * handler; both writes share flag, CSRF, and scope validation.
     */
    #[Route(
        '/api/v1/branches/{branchId}/customer-service-real/records',
        name: 'api_customer_service_real_list',
        methods: ['GET'],
    )]
    public function customerServiceRealList(string $branchId, Request $request): JsonResponse
    {
        $after = $request->query->get('after');
        if ($after === '') {
            throw new BadRequestHttpException('Cursor de paginación inválido.');
        }
        return $this->customerServiceRealRead($branchId, null, $after);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customer-service-real/records/{ref}',
        name: 'api_customer_service_real_detail',
        methods: ['GET'],
    )]
    public function customerServiceRealView(string $branchId, string $ref): JsonResponse
    {
        return $this->customerServiceRealRead($branchId, $ref);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customer-service-real/records',
        name: 'api_customer_service_real_create',
        methods: ['POST'],
    )]
    public function customerServiceRealCreate(string $branchId, Request $request): JsonResponse
    {
        return $this->customerServiceRealWrite($branchId, $request, null);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customer-service-real/records/{ref}/state',
        name: 'api_customer_service_real_state',
        methods: ['PATCH'],
    )]
    public function customerServiceRealState(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->customerServiceRealWrite($branchId, $request, $ref);
    }

    private function customerServiceRealRead(string $branchId, ?string $ref, ?string $after = null): JsonResponse
    {
        if (($off = $this->customerServiceRealDisabled()) !== null) {
            return $off;
        }
        [, $tenant, $company] = $this->customerServiceRealScope($branchId, 'inventory.view');
        $record = $this->domain(fn (): ?array => $ref === null
            ? $this->supportCaseRecords->page($tenant, $company, $after)
            : $this->supportCaseRecords->one($tenant, $company, $ref));

        if ($record === null) {
            throw new NotFoundHttpException('Caso de Servicio al cliente no encontrado.');
        }
        return $this->customerServiceRealJson(
            $ref === null ? $record : ['record' => $record],
        );
    }

    private function customerServiceRealWrite(string $branchId, Request $request, ?string $ref): JsonResponse
    {
        if (($off = $this->customerServiceRealDisabled()) !== null) {
            return $off;
        }
        $this->requireCsrf($request);
        $operation = $ref === null ? 'inventory.create' : 'inventory.update';
        [$actor, $tenant, $company] = $this->customerServiceRealScope($branchId, $operation);

        $input = $this->payload(
            $request,
            $ref === null ? ['record_ref', 'kind'] : ['state', 'expected_version'],
        );
        if ($ref !== null
            && (!is_string($input['state'] ?? null) || !is_int($input['expected_version'] ?? null))) {
            throw new BadRequestHttpException('Estado y versión obligatorios.');
        }

        $result = $this->domain(fn (): array => $ref === null
            ? $this->supportCaseRecords->add($tenant, $company, $actor, $input)
            : $this->supportCaseRecords->transition(
                $tenant, $company, $actor, $ref, $input['state'], $input['expected_version'],
            ));
        return $this->customerServiceRealJson(
            ['record' => $result],
            $ref === null ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** @return array{string,string,string} */
    private function customerServiceRealScope(string $branchId, string $permission): array
    {
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        $roleIsOwner = $membership->roleKey() === Membership::ROLE_OWNER;
        if (!$roleIsOwner || !$actor->isActive() || !$membership->isActive()) {
            throw new AccessDeniedHttpException('Servicio al cliente requiere rol propietario activo.');
        }
        $legalEntity = $branch->legalEntity();
        if ($legalEntity?->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('La razón social no pertenece al tenant solicitado.');
        }
        return [$actor->id(), $tenant->id(), $legalEntity->id()];
    }

    private function customerServiceRealDisabled(): ?JsonResponse
    {
        if (getenv('CUSTOMER_SERVICE_REAL_DATA_ENABLED') === 'true') {
            return null;
        }
        return $this->customerServiceRealJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    /** @param array<string,mixed> $payload */
    private function customerServiceRealJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }


}
