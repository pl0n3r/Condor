<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\EmployeeApiService;
use App\Application\HumanResources\HumanResourcesPreview;
use App\Application\HumanResources\HumanResourceRecordService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
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

/** Owner-only, construction-gated synthetic HR extension; no personal information. */
final class HumanResourcesController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmployeeApiService $employees,
        private readonly HumanResourceRecordService $records,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/hr/extension/employees', name: 'api_hr_extension_list', methods: ['GET'])]
    public function list(string $branchId): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($branchId, 'users.view');
        return $this->privateJson(['employees' => $this->domain(
            fn (): array => $this->employees->listRecords($tenant, $branch, $actor, true),
        )]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/extension/employees/{slug}', name: 'api_hr_extension_view', methods: ['GET'])]
    public function view(string $branchId, string $slug): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($branchId, 'users.view');
        $employee = $this->domain(fn (): ?array => $this->employees->viewRecord($tenant, $branch, $actor, true, $slug));
        if ($employee === null) {
            throw new NotFoundHttpException('Registro de RR. HH. ficticio no encontrado.');
        }
        return $this->privateJson(['employee' => $employee]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/extension/employees', name: 'api_hr_extension_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->access($branchId, 'users.create');
        $payload = $this->payload($request, ['employee_ref', 'position_ref']);
        return $this->privateJson(['employee' => $this->domain(
            fn (): array => $this->employees->createRecord($tenant, $branch, $actor, true, $payload),
        )], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/hr/extension/employees/{slug}/state', name: 'api_hr_extension_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->access($branchId, 'users.update');
        $payload = $this->payload($request, ['state', 'expected_version']);
        return $this->privateJson(['employee' => $this->domain(
            fn (): array => $this->employees->changeState($tenant, $branch, $actor, true, $slug, $payload),
        )]);
    }

    /**
     * Proyección efímera y ficticia; ninguna orden laboral, dato personal o
     * persistencia. La identidad de empresa se obtiene de la sede autorizada.
     */
    #[Route(
        '/api/v1/branches/{branchId}/hr/extension/previews/{kind}',
        name: 'api_hr_extension_preview',
        requirements: ['kind' => 'structure|leave|performance'],
        methods: ['POST'],
    )]
    public function preview(string $branchId, string $kind, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [, $tenant, $branch] = $this->access($branchId, 'users.view');
        $company = $branch->legalEntity();
        if ($company === null) {
            throw new NotFoundHttpException('Entidad legal no disponible.');
        }
        if (strlen((string) $request->getContent()) > 16384) {
            throw new BadRequestHttpException('Proyección sintética excede tamaño permitido.');
        }
        $allowed = match ($kind) {
            'structure' => ['as_of', 'areas', 'sites', 'positions'],
            'leave' => ['as_of', 'requests', 'balances'],
            'performance' => ['as_of', 'viewer_employee_ref', 'evaluations'],
            default => throw new NotFoundHttpException('Vista sintética no disponible.'),
        };
        $payload = $this->payload($request, $allowed);

        return $this->privateJson(['preview' => $this->domain(
            fn (): array => HumanResourcesPreview::project(
                $kind, $tenant->id(), $company->id(), $payload,
            ),
        )]);
    }

    /** Server-gated real RRHH endpoints. All tenant/company/actor IDs come from session authorization. */
    #[Route('/api/v1/branches/{branchId}/hr/records', name: 'api_hr_records_list', methods: ['GET'])]
    public function recordsList(string $branchId): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        return $this->privateJson(['records' => $this->domain(
            fn (): array => $this->records->listRecords($tenant->id(), $this->realCompany($tenant, $branch)),
        )]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/records/{ref}', name: 'api_hr_records_detail', methods: ['GET'])]
    public function recordsDetail(string $branchId, string $ref): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $row = $this->domain(fn (): ?array => $this->records->detail(
            $tenant->id(), $this->realCompany($tenant, $branch), $ref,
        ));
        if ($row === null) { throw new NotFoundHttpException('Registro laboral no encontrado.'); }
        return $this->privateJson(['record' => $row]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/records', name: 'api_hr_records_create', methods: ['POST'])]
    public function recordsCreate(string $branchId, Request $request): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.create');
        $input = $this->payload($request, ['record_ref', 'kind', 'collaborator_ref', 'position_ref']);
        return $this->privateJson(['record' => $this->domain(
            fn (): array => $this->records->create(
                $tenant->id(), $this->realCompany($tenant, $branch), $actor->id(), $input,
            ),
        )], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/hr/records/{ref}/state', name: 'api_hr_records_state', methods: ['PATCH'])]
    public function recordsState(string $branchId, string $ref, Request $request): JsonResponse
    {
        if (($off = $this->realDisabled()) !== null) { return $off; }
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.update');
        $input = $this->payload($request, ['state', 'expected_version']);
        if (!is_string($input['state'] ?? null) || !is_int($input['expected_version'] ?? null)) {
            throw new BadRequestHttpException('Estado y versión obligatorios.');
        }
        return $this->privateJson(['record' => $this->domain(
            fn (): array => $this->records->changeState(
                $tenant->id(), $this->realCompany($tenant, $branch), $actor->id(),
                $ref, $input['state'], $input['expected_version'],
            ),
        )]);
    }

    private function realDisabled(): ?JsonResponse
    {
        if (getenv('HR_REAL_DATA_ENABLED') === 'true') { return null; }
        return $this->privateJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    private function realCompany(Tenant $tenant, Branch $branch): string
    {
        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social no disponible.');
        }
        return $company->id();
    }

    /** @return array{User,Tenant,Branch} */
    private function access(string $branchId, string $permission): array
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_HR_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion')) {
            throw new NotFoundHttpException('Extensión sintética de RR. HH. no disponible.');
        }
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if (!$actor->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Solo el dueño del tenant puede operar RR. HH. sintético.');
        }
        return [$actor, $tenant, $branch];
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
