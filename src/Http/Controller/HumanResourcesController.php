<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\EmployeeApiService;
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
