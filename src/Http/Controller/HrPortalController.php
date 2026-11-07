<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrPortalCatalog;
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

/** M01 S11: únicamente lectura de un portal ficticio, con permisos y scopes del servidor. */
final class HrPortalController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrPortalCatalog $portal,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/hr/employee-portal', name: 'api_hr_employee_portal_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->scopeFor($branchId, $request);

        return $this->privateResult([
            'items' => $this->portal->listFor($tenant, $company, $branch),
            'synthetic' => true, 'persisted' => false, 'juridically_binding' => false,
        ]);
    }

    #[Route('/api/v1/branches/{branchId}/hr/employee-portal/{ref}', name: 'api_hr_employee_portal_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->scopeFor($branchId, $request);
        $item = $this->portal->findFor($tenant, $company, $branch, $ref);
        if ($item === null) {
            throw new NotFoundHttpException('Elemento ficticio no disponible.');
        }

        return $this->privateResult([
            'item' => $item,
            'synthetic' => true, 'persisted' => false, 'juridically_binding' => false,
        ]);
    }

    /** @return array{Tenant,LegalEntity,Branch} */
    private function scopeFor(string $branchId, Request $request): array
    {
        $this->ensureSyntheticOnly();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $company = $branch->legalEntity();
        if (!$company instanceof LegalEntity || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }

        foreach ($request->query->all() as $key => $value) {
            if ($key !== 'company_id') {
                throw new BadRequestHttpException('Parámetros de consulta no permitidos.');
            }
            if (!is_string($value) || !hash_equals($company->id(), $value)) {
                throw new NotFoundHttpException('Empresa no disponible.');
            }
        }

        return [$tenant, $company, $branch];
    }

    private function ensureSyntheticOnly(): void
    {
        $enabled = getenv('HR_REAL_DATA_ENABLED');
        if (is_string($enabled)
            && !in_array(strtolower(trim($enabled)), ['', 'false', '0', 'off', 'no'], true)) {
            throw new NotFoundHttpException('Portal sintético no disponible.');
        }

        $runtime = $this->getParameter('kernel.environment');
        if ($runtime === 'test') {
            return;
        }
        if ($runtime !== 'dev' || getenv('CONDOR_HR_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion') {
            throw new NotFoundHttpException('Portal sintético no disponible.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function privateResult(array $payload): JsonResponse
    {
        $result = $this->json($payload);
        $result->headers->add([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $result;
    }
}
