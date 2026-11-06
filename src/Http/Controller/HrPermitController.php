<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrPermitCatalog;
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

/** A read-only synthetic preview. Never touches payroll or real personnel. */
final class HrPermitController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrPermitCatalog $catalog,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/hr/permits', name: 'api_hr_permits_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->readScope($branchId, $request);
        return $this->result('permits', $this->catalog->listFor($tenant, $company, $branch));
    }

    #[Route('/api/v1/branches/{branchId}/hr/permits/{ref}', name: 'api_hr_permits_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->readScope($branchId, $request);
        $permit = $this->catalog->findFor($tenant, $company, $branch, $ref);
        if ($permit === null) {
            throw new NotFoundHttpException('Solicitud ficticia de permiso no encontrada.');
        }
        return $this->result('permit', $permit);
    }

    /** @return array{Tenant,LegalEntity,Branch} */
    private function readScope(string $branchId, Request $request): array
    {
        $this->rejectUnsafeContext();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $company = $branch->legalEntity();
        if (!$company instanceof LegalEntity || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa ficticia no disponible.');
        }
        $query = $request->query->all();
        foreach (array_keys($query) as $key) {
            if ($key !== 'company_id') {
                throw new BadRequestHttpException('Consulta no admitida.');
            }
        }
        if (array_key_exists('company_id', $query)
            && (!is_string($query['company_id']) || $query['company_id'] !== $company->id())) {
            throw new NotFoundHttpException('Empresa ficticia no disponible.');
        }
        return [$tenant, $company, $branch];
    }

    private function rejectUnsafeContext(): void
    {
        $enabled = getenv('HR_REAL_DATA_ENABLED');
        $off = $enabled === false || in_array(strtolower(trim($enabled)), ['', 'false', '0', 'off', 'no'], true);
        $environment = $this->getParameter('kernel.environment');
        $allowed = $environment === 'test' || (
            $environment === 'dev'
            && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion'
        );
        if (!$off || !$allowed) {
            throw new NotFoundHttpException('Vista de permisos ficticios deshabilitada.');
        }
    }

    /** @param array<string,mixed>|list<array<string,mixed>> $content */
    private function result(string $key, array $content): JsonResponse
    {
        return new JsonResponse([
            $key => $content,
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
