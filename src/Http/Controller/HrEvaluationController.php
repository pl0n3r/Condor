<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrEvaluationCatalog;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** M01 S10: demonstration-only performance evaluations without personnel records. */
final class HrEvaluationController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrEvaluationCatalog $catalog,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/hr/evaluations',
        name: 'api_hr_evaluations_index',
        methods: ['GET'],
    )]
    public function index(string $branchId, Request $request): JsonResponse
    {
        return $this->respondToRead($request, $branchId, null);
    }

    #[Route(
        '/api/v1/branches/{branchId}/hr/evaluations/{ref}',
        name: 'api_hr_evaluations_detail',
        methods: ['GET'],
    )]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->respondToRead($request, $branchId, $ref);
    }

    private function respondToRead(Request $request, string $branchId, ?string $ref): JsonResponse
    {
        $this->requireDemoEnvironment();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $company = $this->companyFromAuthorizedBranch($tenant, $branch, $request);

        if ($ref === null) {
            $payload = ['evaluations' => $this->catalog->listFor($tenant, $company, $branch)];
        } else {
            $evaluation = $this->catalog->findFor($tenant, $company, $branch, $ref);
            if ($evaluation === null) {
                throw new NotFoundHttpException('Evaluación ficticia no encontrada.');
            }
            $payload = ['evaluation' => $evaluation];
        }

        return $this->json($payload + [
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function companyFromAuthorizedBranch(Tenant $tenant, Branch $branch, Request $request): LegalEntity
    {
        $query = $request->query->all();
        foreach (array_keys($query) as $parameter) {
            if ($parameter !== 'company_id') {
                throw new BadRequestHttpException('Filtro no permitido.');
            }
        }

        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social fuera del alcance autorizado.');
        }
        if (array_key_exists('company_id', $query)) {
            $asked = $query['company_id'];
            if (!is_string($asked) || !hash_equals($company->id(), $asked)) {
                throw new NotFoundHttpException('Razón social fuera del alcance autorizado.');
            }
        }

        return $company;
    }

    private function requireDemoEnvironment(): void
    {
        $configured = getenv('HR_REAL_DATA_ENABLED');
        $disabled = $configured === false
            || in_array(strtolower(trim($configured)), ['', 'false', '0', 'off', 'no'], true);
        if (!$disabled) {
            throw new NotFoundHttpException('Evaluaciones sintéticas no disponibles con datos reales habilitados.');
        }

        $mode = $this->getParameter('kernel.environment');
        $devPreview = $mode === 'dev'
            && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion';
        if ($mode !== 'test' && !$devPreview) {
            throw new NotFoundHttpException('Evaluaciones sintéticas no disponibles.');
        }
    }
}
