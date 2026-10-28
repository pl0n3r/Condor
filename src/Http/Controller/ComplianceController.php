<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Compliance\ComplianceApiService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** HTTP preview for synthetic compliance evidence; no real records or legal effects. */
final class ComplianceController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence', name: 'api_compliance_evidence_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $records = $this->domain(fn (): array => ComplianceApiService::listRecords(
            $this->store($request), $this->scope($tenant->id(), $branch->id()),
        ));
        $this->recordAudit($tenant, $user, 'compliance.synthetic.listed', 'fixture:evidence/list');
        return $this->json(['evidence' => $records]);
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence/{slug}', name: 'api_compliance_evidence_view', methods: ['GET'])]
    public function view(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $ref = 'fixture:evidence/'.$slug;
        $record = $this->domain(fn (): ?array => ComplianceApiService::viewRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Evidencia sintética no encontrada.');
        }
        $this->recordAudit($tenant, $user, 'compliance.synthetic.viewed', $ref);
        return $this->json(['evidence' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence', name: 'api_compliance_evidence_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.create');
        $payload = $this->payload($request, ['evidence_ref', 'obligation_ref', 'type']);
        $result = $this->domain(fn (): array => ComplianceApiService::createRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $payload,
        ));
        $this->recordAudit($tenant, $user, 'compliance.synthetic.created', $result['record']['evidence_ref']);
        $request->getSession()->set('condor_compliance_synthetic', $result['store']);
        return $this->json(['evidence' => $result['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence/{slug}/state', name: 'api_compliance_evidence_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $payload = $this->payload($request, ['state']);
        $ref = 'fixture:evidence/'.$slug;
        $result = $this->domain(fn (): array => ComplianceApiService::changeState(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
            is_string($payload['state'] ?? null) ? $payload['state'] : '',
        ));
        $this->recordAudit($tenant, $user, 'compliance.synthetic.state_changed', $ref);
        $request->getSession()->set('condor_compliance_synthetic', $result['store']);
        return $this->json(['evidence' => $result['record']]);
    }

    /** @return array<string, mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get('condor_compliance_synthetic', []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Evidencias ficticias no disponibles.');
        }
        return $store;
    }

    private function scope(string $tenantId, string $branchId): string
    {
        return hash('sha256', $tenantId.'|'.$branchId);
    }

    private function syntheticOnly(): void
    {
        $raw = $this->getParameter('kernel.environment');
        $env = is_string($raw) ? $raw : '';
        if ($env !== 'test' && ($env !== 'dev'
            || getenv('CONDOR_COMPLIANCE_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion')) {
            throw new NotFoundHttpException('API sintética de cumplimiento no disponible.');
        }
    }

    private function recordAudit(Tenant $tenant, User $user, string $action, string $ref): void
    {
        $this->audit($tenant, $user, $action, ComplianceApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
