<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Governance\GovernanceApiService;
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

/** Synthetic governance trial, disabled outside explicitly allowed development/test contexts. */
final class GovernanceController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/governance', name: 'api_governance_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $scope = $this->scope($tenant->id(), $branch->id());
        $records = $this->domain(fn (): array => GovernanceApiService::listRecords($this->store($request), $scope));
        $this->recordAudit($tenant, $user, 'governance.synthetic.listed', 'fixture:governance/list');
        return $this->json(['records' => $records]);
    }

    #[Route('/api/v1/branches/{branchId}/governance/{kind}/{slug}', name: 'api_governance_view', methods: ['GET'])]
    public function view(string $branchId, string $kind, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $ref = 'fixture:'.$kind.'/'.$slug;
        $record = $this->domain(fn (): ?array => GovernanceApiService::viewRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Referencia sintética no encontrada.');
        }
        $this->recordAudit($tenant, $user, 'governance.synthetic.viewed', $ref);
        return $this->json(['record' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/governance', name: 'api_governance_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.create');
        $payload = $this->payload($request, ['kind', 'ref']);
        $result = $this->domain(fn (): array => GovernanceApiService::createRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $payload,
        ));
        $request->getSession()->set('condor_governance_synthetic', $result['store']);
        $this->recordAudit($tenant, $user, 'governance.synthetic.created', $result['record']['ref']);
        return $this->json(['record' => $result['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/governance/{kind}/{slug}/state', name: 'api_governance_state', methods: ['PATCH'])]
    public function state(string $branchId, string $kind, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.update');
        $payload = $this->payload($request, ['state']);
        $ref = 'fixture:'.$kind.'/'.$slug;
        $result = $this->domain(fn (): array => GovernanceApiService::changeState(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
            is_string($payload['state'] ?? null) ? $payload['state'] : '',
        ));
        $request->getSession()->set('condor_governance_synthetic', $result['store']);
        $this->recordAudit($tenant, $user, 'governance.synthetic.state_changed', $ref);
        return $this->json(['record' => $result['record']]);
    }

    /** @return array<string,mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get('condor_governance_synthetic', []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Registro sintético no disponible.');
        }
        return $store;
    }

    private function scope(string $tenantId, string $branchId): string
    {
        return hash('sha256', $tenantId.'|'.$branchId);
    }

    private function syntheticOnly(): void
    {
        $environment = (string) $this->getParameter('kernel.environment');
        if ($environment !== 'test'
            && ($environment !== 'dev' || getenv('CONDOR_GOVERNANCE_SYNTHETIC_API') !== '1')
        ) {
            throw new NotFoundHttpException('Gobierno sintético no disponible.');
        }
    }

    private function recordAudit(Tenant $tenant, User $user, string $action, string $ref): void
    {
        $this->audit($tenant, $user, $action, GovernanceApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
