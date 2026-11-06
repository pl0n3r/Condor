<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\AreaCatalog;
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

/** Synthetic, authorized M01 S03 areas. Never reads actual HR records. */
final class HumanResourcesAreaController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly AreaCatalog $areas,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/hr/areas', name: 'api_hr_areas_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        $this->requireSynthetic();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $company = $this->companyFor($tenant, $branch, $request);

        return $this->privateJson([
            'areas' => $this->areas->listFor($tenant, $company, $branch),
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/areas/{ref}', name: 'api_hr_areas_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        $this->requireSynthetic();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $company = $this->companyFor($tenant, $branch, $request);
        $area = $this->areas->findFor($tenant, $company, $branch, $ref);
        if ($area === null) {
            throw new NotFoundHttpException('Área ficticio no encontrado.');
        }

        return $this->privateJson([
            'area' => $area,
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ]);
    }

    private function requireSynthetic(): void
    {
        $real = getenv('HR_REAL_DATA_ENABLED');
        if ($real !== false && !in_array(strtolower(trim($real)), ['', 'false', '0', 'off', 'no'], true)) {
            throw new NotFoundHttpException('Catálogo sintético no disponible.');
        }
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_HR_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion')) {
            throw new NotFoundHttpException('Catálogo sintético no disponible.');
        }
    }

    private function companyFor(Tenant $tenant, Branch $branch, Request $request): LegalEntity
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['company_id']) !== []) {
            throw new BadRequestHttpException('Parámetros de consulta no permitidos.');
        }
        $company = $branch->legalEntity();
        if (!$company instanceof LegalEntity || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }
        $provided = $query['company_id'] ?? null;
        if ($provided !== null && (!is_string($provided) || $provided !== $company->id())) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }

        return $company;
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload): JsonResponse
    {
        $response = $this->json($payload);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
