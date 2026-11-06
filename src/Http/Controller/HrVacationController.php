<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\HumanResources\HrVacationCatalog;
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

/** HTTP facade for fictional leave dates. Never loads real employee records. */
#[Route('/api/v1/branches/{branchId}/hr/vacations')]
final class HrVacationController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly HrVacationCatalog $vacations,
    ) {
    }

    #[Route('', name: 'api_hr_vacations_index', methods: ['GET'])]
    public function index(string $branchId, Request $request): JsonResponse
    {
        $scope = $this->resolveSafeScope($branchId, $request);
        return $this->calendarResponse('vacations', $this->vacations->listFor(...$scope));
    }

    #[Route('/{ref}', name: 'api_hr_vacations_detail', methods: ['GET'])]
    public function detail(string $branchId, string $ref, Request $request): JsonResponse
    {
        $scope = $this->resolveSafeScope($branchId, $request);
        $entry = $this->vacations->findFor(...[...$scope, $ref]);
        if ($entry === null) {
            throw new NotFoundHttpException('No existe una vacación ficticia con esa referencia.');
        }
        return $this->calendarResponse('vacation', $entry);
    }

    /** @return array{Tenant, LegalEntity, Branch} */
    private function resolveSafeScope(string $branchId, Request $request): array
    {
        $this->guardPreviewMode();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'users.view');

        $parameters = $request->query->all();
        $unknown = array_filter(array_keys($parameters), static fn (string $key): bool => $key !== 'company_id');
        if ($unknown !== []) {
            throw new BadRequestHttpException('Este calendario no admite filtros adicionales.');
        }

        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('La empresa del contexto no está disponible.');
        }

        if (array_key_exists('company_id', $parameters)) {
            $requested = $parameters['company_id'];
            if (!is_string($requested) || $requested !== $company->id()) {
                throw new NotFoundHttpException('No hay empresa autorizada para el filtro solicitado.');
            }
        }
        return [$tenant, $company, $branch];
    }

    private function guardPreviewMode(): void
    {
        $flag = getenv('HR_REAL_DATA_ENABLED');
        $falseValues = ['', '0', 'off', 'no', 'false'];
        $realDataOff = $flag === false || in_array(strtolower(trim($flag)), $falseValues, true);
        $environment = $this->getParameter('kernel.environment');
        $testMode = $environment === 'test';
        $explicitDevelopment = $environment === 'dev'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion'
            && getenv('CONDOR_HR_SYNTHETIC_API') === '1';

        if (!$realDataOff || (!$testMode && !$explicitDevelopment)) {
            throw new NotFoundHttpException('La vista de vacaciones ficticias está deshabilitada.');
        }
    }

    /** @param list<mixed>|array<string, mixed> $rows */
    private function calendarResponse(string $name, array $rows): JsonResponse
    {
        return new JsonResponse([
            $name => $rows,
            'synthetic' => true,
            'persisted' => false,
            'juridically_binding' => false,
        ], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
