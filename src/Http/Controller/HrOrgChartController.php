<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrOrgChartCatalog;
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

/** Fixture-only organizational hierarchy. Does not touch employee records. */
final class HrOrgChartController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrOrgChartCatalog $catalog,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/hr/org-chart', name: 'api_hr_org_chart_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->previewContext($branchId, $request);

        return $this->safeJson(['nodes' => $this->catalog->listFor($tenant, $company, $branch)]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/org-chart/{ref}', name: 'api_hr_org_chart_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->previewContext($branchId, $request);
        $node = $this->catalog->findFor($tenant, $company, $branch, $ref);

        if ($node === null) {
            throw new NotFoundHttpException('Nodo ficticio no encontrado.');
        }

        return $this->safeJson(['node' => $node]);
    }

    /** @return array{Tenant, LegalEntity, Branch} */
    private function previewContext(string $branchId, Request $request): array
    {
        $flag = getenv('HR_REAL_DATA_ENABLED');
        $realMode = $flag !== false && !in_array(strtolower(trim($flag)), ['', '0', 'false', 'off', 'no'], true);
        $environment = $this->getParameter('kernel.environment');
        $devPreview = $environment === 'dev'
            && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion';

        if ($realMode || ($environment !== 'test' && !$devPreview)) {
            throw new NotFoundHttpException('Organigrama sintético no disponible.');
        }

        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $params = $request->query->all();
        if ($params !== [] && (count($params) !== 1 || !array_key_exists('company_id', $params))) {
            throw new BadRequestHttpException('Parámetros de consulta no permitidos.');
        }

        $company = $branch->legalEntity();
        if (!$company instanceof LegalEntity || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }
        if ($params !== [] && (!is_string($params['company_id']) || $params['company_id'] !== $company->id())) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }

        return [$tenant, $company, $branch];
    }

    /** @param array<string,mixed> $data */
    private function safeJson(array $data): JsonResponse
    {
        return $this->json(
            $data + ['synthetic' => true, 'persisted' => false, 'juridically_binding' => false],
            200,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
