<?php
declare(strict_types=1);
namespace App\Http\Controller;
use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\OperationsProcessService;
use App\Application\Workflow\OperationalProcessService;
use App\Domain\Identity\Entity\Membership;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
/** Synthetic-only, tenant-authorized operational rehearsal, never a task runner. */
final class OperationsProcessController extends AbstractController
{
    use BranchApiSupport;
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionEntitlementContextFactory $entitlementContexts,
        private readonly EntitlementResolver $entitlementResolver,
        private readonly OperationalProcessService $operationalProcessRecords,
    ) {
    }
    #[Route('/api/v1/branches/{branchId}/operations/processes', name: 'api_operations_processes', methods: ['GET'])]
    public function index(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $this->requirePreview($tenant->id());
        return $this->json(['branch_id' => $branch->id(),
            'processes' => OperationsProcessService::catalog(), 'persisted' => false]);
    }
    #[Route('/api/v1/branches/{branchId}/operations/processes/preview', name: 'api_operations_processes_preview', methods: ['POST'])]
    public function preview(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.update');
        $this->requirePreview($tenant->id());
        if (strlen((string) $request->getContent()) > 2048) {
            throw new UnprocessableEntityHttpException('Simulación demasiado extensa.');
        }
        $input = $this->payload($request, ['process_id', 'procedure_id', 'task_id', 'state']);
        return $this->json([
            'branch_id' => $branch->id(),
            'preview' => $this->domain(fn (): array => OperationsProcessService::preview($input)),
        ]);
    }
    private function requirePreview(string $tenantId): void
    {
        if (!in_array($this->getParameter('kernel.environment'), ['dev', 'test'], true)) {
            throw new NotFoundHttpException('Simulación no disponible.');
        }
        $entitlements = $this->domain(fn (): EntitlementSnapshot =>
            $this->entitlementResolver->resolve($this->entitlementContexts->forTenant(
                $tenantId,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ))
        );
        if (!$this->domain(fn (): bool => $entitlements->addOn('production-lite'))) {
            throw new UnprocessableEntityHttpException('Módulo de producción no habilitado.');
        }
    }

    /**
     * Owner-only persisted Procesos bridge: both read routes use one guarded
     * handler; both writes share flag, CSRF, and scope validation.
     */
    #[Route(
        '/api/v1/branches/{branchId}/operations-processes-real/records',
        name: 'api_operations_process_real_list',
        methods: ['GET'],
    )]
    public function operationsProcessRealList(string $branchId, Request $request): JsonResponse
    {
        $after = $request->query->get('after');
        if ($after === '') {
            throw new BadRequestHttpException('Cursor de paginación inválido.');
        }
        return $this->operationsProcessRealRead($branchId, null, $after);
    }

    #[Route(
        '/api/v1/branches/{branchId}/operations-processes-real/records/{ref}',
        name: 'api_operations_process_real_detail',
        methods: ['GET'],
    )]
    public function operationsProcessRealView(string $branchId, string $ref): JsonResponse
    {
        return $this->operationsProcessRealRead($branchId, $ref);
    }

    #[Route(
        '/api/v1/branches/{branchId}/operations-processes-real/records',
        name: 'api_operations_process_real_create',
        methods: ['POST'],
    )]
    public function operationsProcessRealCreate(string $branchId, Request $request): JsonResponse
    {
        return $this->operationsProcessRealWrite($branchId, $request, null);
    }

    #[Route(
        '/api/v1/branches/{branchId}/operations-processes-real/records/{ref}/state',
        name: 'api_operations_process_real_state',
        methods: ['PATCH'],
    )]
    public function operationsProcessRealState(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->operationsProcessRealWrite($branchId, $request, $ref);
    }

    private function operationsProcessRealRead(string $branchId, ?string $ref, ?string $after = null): JsonResponse
    {
        if (($off = $this->operationsProcessRealDisabled()) !== null) {
            return $off;
        }
        [, $tenant, $company] = $this->operationsProcessRealScope($branchId, 'inventory.view');
        $record = $this->domain(fn (): ?array => $ref === null
            ? $this->operationalProcessRecords->page($tenant, $company, $after)
            : $this->operationalProcessRecords->one($tenant, $company, $ref));

        if ($record === null) {
            throw new NotFoundHttpException('Proceso de Procesos no encontrado.');
        }
        return $this->operationsProcessRealJson(
            $ref === null ? $record : ['record' => $record],
        );
    }

    private function operationsProcessRealWrite(string $branchId, Request $request, ?string $ref): JsonResponse
    {
        if (($off = $this->operationsProcessRealDisabled()) !== null) {
            return $off;
        }
        $this->requireCsrf($request);
        $operation = $ref === null ? 'inventory.create' : 'inventory.update';
        [$actor, $tenant, $company] = $this->operationsProcessRealScope($branchId, $operation);

        $input = $this->payload(
            $request,
            $ref === null ? ['record_ref', 'kind'] : ['state', 'expected_version'],
        );
        if ($ref !== null
            && (!is_string($input['state'] ?? null) || !is_int($input['expected_version'] ?? null))) {
            throw new BadRequestHttpException('Estado y versión obligatorios.');
        }

        $result = $this->domain(fn (): array => $ref === null
            ? $this->operationalProcessRecords->add($tenant, $company, $actor, $input)
            : $this->operationalProcessRecords->transition(
                $tenant, $company, $actor, $ref, $input['state'], $input['expected_version'],
            ));
        return $this->operationsProcessRealJson(
            ['record' => $result],
            $ref === null ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** @return array{string,string,string} */
    private function operationsProcessRealScope(string $branchId, string $permission): array
    {
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        $roleIsOwner = $membership->roleKey() === Membership::ROLE_OWNER;
        if (!$roleIsOwner || !$actor->isActive() || !$membership->isActive()) {
            throw new AccessDeniedHttpException('Procesos requiere rol propietario activo.');
        }
        $legalEntity = $branch->legalEntity();
        if ($legalEntity?->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('La razón social no pertenece al tenant solicitado.');
        }
        return [$actor->id(), $tenant->id(), $legalEntity->id()];
    }

    private function operationsProcessRealDisabled(): ?JsonResponse
    {
        if (getenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED') === 'true') {
            return null;
        }
        return $this->operationsProcessRealJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    /** @param array<string,mixed> $payload */
    private function operationsProcessRealJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }


}
