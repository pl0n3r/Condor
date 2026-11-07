<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrPortalCatalog;
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

/** Portal de muestra M01 S11: no ofrece escrituras ni identifica empleados reales. */
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
        return $this->renderPortal($request, $branchId, null);
    }

    #[Route('/api/v1/branches/{branchId}/hr/employee-portal/{ref}', name: 'api_hr_employee_portal_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        return $this->renderPortal($request, $branchId, $ref);
    }

    private function renderPortal(Request $request, string $branchId, ?string $reference): JsonResponse
    {
        $this->assertFixtureMode();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');
        $entity = $branch->legalEntity();
        if (!($entity instanceof LegalEntity) || $tenant->id() !== $entity->tenant()->id()) {
            throw new NotFoundHttpException('Ámbito del portal no disponible.');
        }

        $query = $request->query->all();
        if (array_diff(array_keys($query), ['company_id']) !== []) {
            throw new BadRequestHttpException('Consulta del portal no reconocida.');
        }
        if (array_key_exists('company_id', $query)
            && (!is_string($query['company_id']) || !hash_equals($entity->id(), $query['company_id']))) {
            throw new NotFoundHttpException('Ámbito del portal no disponible.');
        }

        $listing = $reference === null;
        $records = $listing
            ? $this->portal->listFor($tenant, $entity, $branch)
            : $this->portal->findFor($tenant, $entity, $branch, $reference);
        if ($records === null) {
            throw new NotFoundHttpException('Elemento de muestra no encontrado.');
        }

        $payload = [
            $listing ? 'items' : 'item' => $records,
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ];

        return new JsonResponse($payload, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertFixtureMode(): void
    {
        $flag = strtolower(trim((string) getenv('HR_REAL_DATA_ENABLED')));
        if (!in_array($flag, ['', 'false', '0', 'no', 'off'], true)) {
            throw new NotFoundHttpException('Portal real no habilitado.');
        }

        if ($this->getParameter('kernel.environment') === 'test') {
            return;
        }

        $devPreview = $this->getParameter('kernel.environment') === 'dev'
            && getenv('CONDOR_HR_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion';
        if (!$devPreview) {
            throw new NotFoundHttpException('El portal de ejemplo no está habilitado.');
        }
    }
}
