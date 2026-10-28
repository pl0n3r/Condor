<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Finance\FinanceApiService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finance API is an isolated test/dev preview. Never exposes real financial data.
 */
final class FinanceController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/finance/records', name: 'api_finance_records', methods: ['GET'])]
    public function listRecords(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.view');
        $records = $this->domain(fn (): array => FinanceApiService::listRecords(
            $this->store($request), $this->scope($tenant, $branch),
        ));
        $this->auditPreview($tenant, $actor, 'listed', 'fixture:finance-entry/list');
        return $this->json(['records' => $records, 'synthetic_only' => true]);
    }

    #[Route('/api/v1/branches/{branchId}/finance/records/{slug}', name: 'api_finance_record_view', methods: ['GET'])]
    public function viewRecord(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.view');
        $ref = 'fixture:finance-entry/'.$slug;
        $record = $this->domain(fn (): ?array => FinanceApiService::viewRecord(
            $this->store($request), $this->scope($tenant, $branch), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Registro financiero ficticio no encontrado.');
        }
        $this->auditPreview($tenant, $actor, 'viewed', $ref);
        return $this->json(['record' => $record, 'synthetic_only' => true]);
    }

    #[Route('/api/v1/branches/{branchId}/finance/records', name: 'api_finance_record_create', methods: ['POST'])]
    public function createRecord(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.create', true);
        $payload = $this->payload($request, ['entry_ref', 'concept_ref', 'amount_ref', 'kind']);
        $result = $this->domain(fn (): array => FinanceApiService::createRecord(
            $this->store($request), $this->scope($tenant, $branch), $payload,
        ));
        $this->auditPreview($tenant, $actor, 'created', $result['record']['entry_ref']);
        $request->getSession()->set('condor_finance_synthetic', $result['store']);
        return $this->json(['record' => $result['record'], 'synthetic_only' => true], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/finance/records/{slug}/state', name: 'api_finance_record_state', methods: ['PATCH'])]
    public function changeState(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.update', true);
        $payload = $this->payload($request, ['state']);
        $state = is_string($payload['state'] ?? null) ? $payload['state'] : '';
        $ref = 'fixture:finance-entry/'.$slug;
        $result = $this->domain(fn (): array => FinanceApiService::changeState(
            $this->store($request), $this->scope($tenant, $branch), $ref, $state,
        ));
        $this->auditPreview($tenant, $actor, 'state_changed', $ref);
        $request->getSession()->set('condor_finance_synthetic', $result['store']);
        return $this->json(['record' => $result['record'], 'synthetic_only' => true]);
    }

    /** @return array{User,Tenant,Branch} */
    private function access(Request $request, string $branchId, string $permission, bool $write = false): array
    {
        if (!$this->financePreviewEnabled()) {
            throw new NotFoundHttpException('Finanzas sintéticas no disponibles.');
        }
        if ($write) {
            $this->requireCsrf($request);
        }
        return $this->authorizedBranchScope($branchId, $permission);
    }

    private function financePreviewEnabled(): bool
    {
        $runtime = $this->getParameter('kernel.environment');
        if ($runtime === 'test') {
            return true;
        }
        if ($runtime !== 'dev') {
            return false;
        }
        return getenv('CONDOR_FINANCE_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion';
    }

    /** @return array<string,mixed> */
    private function store(Request $request): array
    {
        $snapshot = $request->getSession()->get('condor_finance_synthetic', []);
        return is_array($snapshot)
            ? $snapshot
            : throw new NotFoundHttpException('Archivo financiero ficticio no disponible.');
    }

    private function scope(Tenant $tenant, Branch $branch): string
    {
        return hash('sha256', $tenant->id().'|'.$branch->id());
    }

    private function auditPreview(Tenant $tenant, User $actor, string $event, string $ref): void
    {
        $this->audit($tenant, $actor, 'finance.synthetic.'.$event, FinanceApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
