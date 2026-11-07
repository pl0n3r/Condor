<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Contracts\ContractLifecycleProjection;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** S04–S08: preview without signature, legal settlement, persistence or notifications. */
final class ContractsLifecyclePreviewController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/contracts/lifecycle/preview',
        name: 'api_contracts_lifecycle_preview', methods: ['POST'])]
    public function preview(string $branchId, Request $request): JsonResponse
    {
        $this->requireSyntheticEnvironment();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $this->requireCsrf($request);
        $data = $this->payload($request, [
            'tenant_ref', 'company_ref', 'reference_date', 'contracts',
            'signature_evidence', 'renewals', 'terminations', 'settlement_drafts',
        ]);
        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Empresa no disponible para la sede.');
        }
        // The synthetic fixture namespace is scoped to the authenticated tenant,
        // legal entity AND branch. Never trust arbitrary client-supplied scope IDs.
        $tenantRef = 'tenant-test-t'.substr(hash('sha256', $tenant->id()), 0, 15);
        $companyRef = 'fixture:company/c'.substr(hash('sha256',
            $tenant->id().'|'.$company->id().'|'.$branch->id()), 0, 15);
        if (($data['tenant_ref'] ?? null) !== $tenantRef
            || ($data['company_ref'] ?? null) !== $companyRef) {
            throw new UnprocessableEntityHttpException('Ámbito de prueba inválido.');
        }
        $preview = ContractLifecycleProjection::project($data);
        if (!$preview['valid']) {
            throw new UnprocessableEntityHttpException('Referencias sintéticas inválidas.');
        }
        return $this->json([
            'lifecycle_preview' => $preview, 'synthetic_only' => true,
            'requires_human_review' => true, 'legal_value' => false,
            'signature_validated' => false, 'notification_sent' => false,
        ]);
    }

    private function requireSyntheticEnvironment(): void
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test'
            && ($environment !== 'dev' || getenv('CONDOR_CONTRACTS_SYNTHETIC_API') !== '1')) {
            throw new NotFoundHttpException('Vista previa sintética deshabilitada.');
        }
    }
}
