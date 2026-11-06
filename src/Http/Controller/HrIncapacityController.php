<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrIncapacityCatalog;
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

/** A non-persistent HTTP facade for fictional absences only. */
#[Route('/api/v1/branches/{branchId}/hr/incapacities')]
final class HrIncapacityController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrIncapacityCatalog $incapacityCatalog,
    ) {
    }

    #[Route('', name: 'api_hr_incapacities_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        $scope = $this->visibleScope($request, $branchId);

        return $this->respond('incapacities', $this->incapacityCatalog->listFor(...$scope));
    }

    #[Route('/{ref}', name: 'api_hr_incapacities_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        [$tenant, $company, $branch] = $this->visibleScope($request, $branchId);
        $entry = $this->incapacityCatalog->findFor($tenant, $company, $branch, $ref);
        if ($entry === null) {
            throw new NotFoundHttpException('No hay ausencia ficticia con esa referencia.');
        }

        return $this->respond('incapacity', $entry);
    }

    /** @return array{Tenant, LegalEntity, Branch} */
    private function visibleScope(Request $request, string $branchId): array
    {
        if (!$this->isSyntheticPreviewAllowed()) {
            throw new NotFoundHttpException('La vista ficticia no está disponible.');
        }

        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $entity = $branch->legalEntity();
        if (!$entity instanceof LegalEntity || $entity->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Ámbito empresarial no disponible.');
        }

        $filters = $request->query->all();
        if (array_diff(array_keys($filters), ['company_id']) !== []) {
            throw new BadRequestHttpException('Filtro no admitido para el ensayo.');
        }
        if (array_key_exists('company_id', $filters)) {
            $requested = $filters['company_id'];
            if (!is_string($requested) || $requested !== $entity->id()) {
                throw new NotFoundHttpException('Ámbito empresarial no disponible.');
            }
        }

        return [$tenant, $entity, $branch];
    }

    private function isSyntheticPreviewAllowed(): bool
    {
        $realData = strtolower(trim((string) (getenv('HR_REAL_DATA_ENABLED') ?: '')));
        if (!in_array($realData, ['', '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        $environment = (string) $this->getParameter('kernel.environment');
        if ($environment === 'test') {
            return true;
        }

        return $environment === 'dev'
            && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion';
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $body */
    private function respond(string $field, array $body): JsonResponse
    {
        $response = $this->json([
            $field => $body,
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
