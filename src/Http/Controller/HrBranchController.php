<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrBranchCatalog;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Authorized, read-only M01 S04 branch previews; never queries HR records. */
final class HrBranchController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrBranchCatalog $catalog,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/hr/branch-preview', name: 'api_hr_branch_preview_index', methods: ['GET'])]
    #[Route('/api/v1/branches/{branchId}/hr/branch-preview/{ref}', name: 'api_hr_branch_preview_detail', methods: ['GET'])]
    public function preview(string $branchId, Request $request, ?string $ref = null): JsonResponse
    {
        $this->assertSyntheticMode();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');

        $query = $request->query->all();
        if (array_diff(array_keys($query), ['company_id']) !== []) {
            throw new BadRequestHttpException('Solo se admite company_id.');
        }
        $company = $branch->legalEntity();
        if (!$company instanceof LegalEntity || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }
        if (array_key_exists('company_id', $query)
            && (!is_string($query['company_id']) || !hash_equals($company->id(), $query['company_id']))) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }

        if ($ref === null) {
            $result = ['branches' => $this->catalog->listFor($tenant, $company, $branch)];
        } else {
            $sample = $this->catalog->findFor($tenant, $company, $branch, $ref);
            if ($sample === null) {
                throw new NotFoundHttpException('Sede ficticia no encontrada.');
            }
            $result = ['branch' => $sample];
        }

        $response = new JsonResponse($result + [
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function assertSyntheticMode(): void
    {
        $flag = getenv('HR_REAL_DATA_ENABLED');
        if ($flag !== false && !in_array(strtolower(trim($flag)), ['', 'false', '0', 'off', 'no'], true)) {
            throw new NotFoundHttpException('Sedes ficticias inhabilitadas.');
        }

        $env = $this->getParameter('kernel.environment');
        if ($env === 'test') {
            return;
        }
        if ($env === 'dev' && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion') {
            return;
        }
        throw new NotFoundHttpException('Vista previa no disponible fuera del entorno sintético.');
    }
}
