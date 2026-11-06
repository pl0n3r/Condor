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

/** Read-only fake catalogue; accessible only within explicitly authorized scopes. */
#[Route('/api/v1/branches/{branchId}/hr/areas')]
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

    #[Route('', name: 'api_hr_areas_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->scopedRequest($branchId, $request);
        $document = ['areas' => $this->areas->listFor($tenant, $company, $branch)];

        return $this->respond($document);
    }

    #[Route('/{ref}', name: 'api_hr_areas_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->scopedRequest($branchId, $request);
        $found = $this->areas->findFor($tenant, $company, $branch, $ref);

        if ($found === null) {
            throw new NotFoundHttpException('Área ficticia no encontrada.');
        }

        return $this->respond(['area' => $found]);
    }

    /** @return array{Tenant,LegalEntity,Branch} */
    private function scopedRequest(string $branchId, Request $request): array
    {
        $this->ensurePreviewIsSafe();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');

        $params = $request->query->all();
        foreach (array_keys($params) as $key) {
            if ($key !== 'company_id') {
                throw new BadRequestHttpException('Parámetro de consulta no permitido.');
            }
        }

        $company = $branch->legalEntity();
        $requested = $params['company_id'] ?? null;
        if (!$company instanceof LegalEntity
            || $company->tenant()->id() !== $tenant->id()
            || ($requested !== null && (!is_string($requested) || $requested !== $company->id()))) {
            throw new NotFoundHttpException('Empresa no disponible.');
        }

        return [$tenant, $company, $branch];
    }

    private function ensurePreviewIsSafe(): void
    {
        $flag = getenv('HR_REAL_DATA_ENABLED');
        $disabled = $flag === false || in_array(strtolower(trim($flag)), [
            '', 'false', '0', 'off', 'no',
        ], true);
        $environment = $this->getParameter('kernel.environment');
        $allowed = $environment === 'test'
            || ($environment === 'dev' && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
                && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion');

        if (!$disabled || !$allowed) {
            throw new NotFoundHttpException('Vista sintética no disponible.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload): JsonResponse
    {
        $payload['synthetic'] = true;
        $payload['persisted'] = false;
        $payload['juridically_binding'] = false;

        return new JsonResponse($payload, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
