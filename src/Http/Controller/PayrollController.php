<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Payroll\PayrollApiService;
use App\Application\Payroll\PayrollRunService;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Four endpoints for synthetic payroll only; never accept tenant IDs in JSON. */
final class PayrollController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly PayrollApiService $payroll,
        private readonly PayrollRunService $records,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/payroll/records', name: 'api_payroll_list', methods: ['GET'])]
    public function index(string $branchId): JsonResponse
    {
        return $this->respond($branchId, 'list', 'users.view');
    }

    #[Route('/api/v1/branches/{branchId}/payroll/records/{slug}', name: 'api_payroll_view', methods: ['GET'])]
    public function show(string $branchId, string $slug): JsonResponse
    {
        return $this->respond($branchId, 'view', 'users.view', null, $slug);
    }

    #[Route('/api/v1/branches/{branchId}/payroll/records', name: 'api_payroll_create', methods: ['POST'])]
    public function store(string $branchId, Request $request): JsonResponse
    {
        return $this->respond($branchId, 'create', 'users.create', $request);
    }

    #[Route('/api/v1/branches/{branchId}/payroll/records/{slug}/state', name: 'api_payroll_state', methods: ['PATCH'])]
    public function revise(string $branchId, string $slug, Request $request): JsonResponse
    {
        return $this->respond($branchId, 'state', 'users.update', $request, $slug);
    }

    /** Persisted payroll uses separate routes; synthetic records remain untouched. */
    #[Route('/api/v1/branches/{branchId}/payroll/real-records', name: 'api_payroll_real_list', methods: ['GET'])]
    public function realList(string $branchId): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        [, $tenant, $branch] = $this->realScope($branchId, 'users.view');
        return $this->privateJson(['records' => $this->domain(
            fn (): array => $this->records->listRecords(
                $tenant->id(), $this->realCompany($tenant, $branch),
            ),
        )]);
    }

    #[Route('/api/v1/branches/{branchId}/payroll/real-records/{ref}', name: 'api_payroll_real_detail', methods: ['GET'])]
    public function realDetail(string $branchId, string $ref): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        [, $tenant, $branch] = $this->realScope($branchId, 'users.view');
        $record = $this->domain(fn (): ?array => $this->records->detail(
            $tenant->id(), $this->realCompany($tenant, $branch), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Registro de nómina no encontrado.');
        }
        return $this->privateJson(['record' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/payroll/real-records', name: 'api_payroll_real_create', methods: ['POST'])]
    public function realCreate(string $branchId, Request $request): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->realScope($branchId, 'users.create');
        $input = $this->payload($request, ['record_ref', 'employee_ref', 'period_ref']);
        return $this->privateJson(['record' => $this->domain(
            fn (): array => $this->records->create(
                $tenant->id(), $this->realCompany($tenant, $branch), $actor->id(), $input,
            ),
        )], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/payroll/real-records/{ref}/state', name: 'api_payroll_real_state', methods: ['PATCH'])]
    public function realState(string $branchId, string $ref, Request $request): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->realScope($branchId, 'users.update');
        $update = $this->payload($request, ['state', 'expected_version']);
        $nextState = $update['state'] ?? null;
        $revision = $update['expected_version'] ?? null;
        if (!is_string($nextState) || !is_int($revision)) {
            throw new BadRequestHttpException('Estado y versión obligatorios.');
        }
        $companyId = $this->realCompany($tenant, $branch);
        $record = $this->domain(
            fn (): array => $this->records->changeState(
                $tenant->id(), $companyId, $actor->id(), $ref, $nextState, $revision,
            ),
        );
        return $this->privateJson(['record' => $record]);
    }

    /** Server flag must be checked before authorization, CSRF or any persistence read. */
    private function realDisabled(): ?JsonResponse
    {
        if (getenv('PAYROLL_REAL_DATA_ENABLED') === 'true') { return null; }
        return $this->privateJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    /** @return array{\App\Domain\Identity\Entity\User, Tenant, Branch} */
    private function realScope(string $branchId, string $permission): array
    {
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if (!$actor->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Nómina reservada al propietario autorizado.');
        }
        return [$actor, $tenant, $branch];
    }

    private function realCompany(Tenant $tenant, Branch $branch): string
    {
        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social no disponible.');
        }
        return $company->id();
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function respond(
        string $branchId, string $command, string $permission,
        ?Request $request = null, ?string $slug = null,
    ): JsonResponse {
        // A dev flag never overrides the production-stage guard.
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && (
            $environment !== 'dev'
            || getenv('CONDOR_PAYROLL_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        )) {
            throw new NotFoundHttpException('Nómina sintética desactivada.');
        }
        if ($command === 'create' || $command === 'state') {
            if (!$request instanceof Request) {
                throw new AccessDeniedHttpException('Solicitud incompleta.');
            }
            $this->requireCsrf($request);
        }
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if (!$actor->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Nómina sintética reservada al propietario.');
        }
        $payload = match ($command) {
            'list' => [],
            'view' => ['ref' => 'fixture:payroll/'.$slug],
            'create' => $this->payload($request ?? throw new AccessDeniedHttpException('Solicitud incompleta.'), ['payroll_ref', 'employee_ref', 'period_ref']),
            'state' => ['ref' => 'fixture:payroll/'.$slug] +
                $this->payload($request ?? throw new AccessDeniedHttpException('Solicitud incompleta.'), ['state', 'expected_version']),
            default => throw new NotFoundHttpException(),
        };
        $result = $this->domain(
            fn (): array => $this->payroll->execute($tenant, $branch, $actor, true, $command, $payload),
        );
        if ($command === 'view' && $result['record'] === null) {
            throw new NotFoundHttpException('Registro ficticio no encontrado.');
        }
        $response = $this->json($result, $command === 'create' ? Response::HTTP_CREATED : Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
