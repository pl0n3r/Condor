<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Finance\FinanceApiService;
use App\Application\Finance\FinanceEntryService;
use App\Application\Finance\FinanceReconciliationBudget;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        private readonly FinanceEntryService $financeEntries,
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

    /**
     * Review-only, never a real financial close. No bank data or persistence.
     * The fixed local fixture identities cannot select another real tenant:
     * access() resolves and authorizes the actual tenant/branch first.
     */
    #[Route('/api/v1/branches/{branchId}/finance/closures/preview', name: 'api_finance_closure_preview', methods: ['POST'])]
    public function previewClosure(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant] = $this->access($request, $branchId, 'settings.update', true);
        if (strlen($request->getContent()) > 65536) {
            throw new BadRequestHttpException('Fixture de cierre sintético demasiado grande.');
        }
        $payload = $this->payload($request, [
            'fixture_ref', 'tenant_ref', 'legal_entity_ref', 'as_of',
            'ledger_entries', 'bank_movements', 'budgets', 'periods',
        ]);
        // The shared JSON boundary preserves nested objects as stdClass;
        // only adapt the four synthetic fixture lists for the pure projector.
        // Unknown fields, non-lists and invalid states still fail closed there.
        foreach (['ledger_entries', 'bank_movements', 'budgets', 'periods'] as $section) {
            if (is_array($payload[$section] ?? null)) {
                $payload[$section] = array_map(
                    static fn (mixed $record): mixed => $record instanceof \stdClass
                        ? get_object_vars($record)
                        : $record,
                    $payload[$section],
                );
            }
        }
        $projection = FinanceReconciliationBudget::project(
            $payload, 'fixture:tenant/current', 'fixture:legal-entity/current',
        );
        $this->auditPreview($tenant, $actor, 'closure_preview', 'fixture:finance-reconciliation/preview');

        return $this->json(['closure_preview' => $projection, 'synthetic_only' => true]);
    }


    /**
     * Persisted review cases are separate from session-only financial previews.
     * The default state is OFF, including missing or malformed flags.
     */
    #[Route('/api/v1/branches/{branchId}/finance/real-records', name: 'api_finance_real_list', methods: ['GET'])]
    public function realList(string $branchId): JsonResponse
    {
        if (($off = $this->financeRealDisabled()) !== null) { return $off; }
        [, $tenant, $company] = $this->financeRealScope($branchId, 'settings.view');
        $rows = $this->domain(fn (): array => $this->financeEntries->all($tenant, $company));
        return $this->safeJson(['records' => $rows]);
    }

    #[Route('/api/v1/branches/{branchId}/finance/real-records/{ref}', name: 'api_finance_real_detail', methods: ['GET'])]
    public function realView(string $branchId, string $ref): JsonResponse
    {
        if (($off = $this->financeRealDisabled()) !== null) { return $off; }
        [, $tenant, $company] = $this->financeRealScope($branchId, 'settings.view');
        $row = $this->domain(fn (): ?array => $this->financeEntries->one($tenant, $company, $ref));
        if ($row === null) { throw new NotFoundHttpException('Registro financiero no encontrado.'); }
        return $this->safeJson(['record' => $row]);
    }

    #[Route('/api/v1/branches/{branchId}/finance/real-records', name: 'api_finance_real_create', methods: ['POST'])]
    public function realCreate(string $branchId, Request $request): JsonResponse
    {
        if (($off = $this->financeRealDisabled()) !== null) { return $off; }
        $this->requireCsrf($request);
        [$actor, $tenant, $company] = $this->financeRealScope($branchId, 'settings.create');
        $data = $this->payload($request, ['entry_ref', 'kind']);
        $row = $this->domain(fn (): array => $this->financeEntries->add($tenant, $company, $actor, $data));
        return $this->safeJson(['record' => $row], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/finance/real-records/{ref}/state', name: 'api_finance_real_state', methods: ['PATCH'])]
    public function realState(string $branchId, string $ref, Request $request): JsonResponse
    {
        if (($off = $this->financeRealDisabled()) !== null) { return $off; }
        $this->requireCsrf($request);
        $data = $this->payload($request, ['state', 'expected_version']);
        if (!is_string($data['state'] ?? null) || !is_int($data['expected_version'] ?? null)) {
            throw new BadRequestHttpException('Estado y versión válidos son obligatorios.');
        }
        [$actor, $tenant, $company] = $this->financeRealScope($branchId, 'settings.update');
        $row = $this->domain(fn (): array => $this->financeEntries->transition(
            $tenant, $company, $actor, $ref, $data['state'], $data['expected_version'],
        ));
        return $this->safeJson(['record' => $row]);
    }

    /** @return array{string,string,string} */
    private function financeRealScope(string $branchId, string $permission): array
    {
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if (!$actor->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Solo el propietario puede gestionar Finanzas.');
        }
        $entity = $branch->legalEntity();
        if ($entity === null || $entity->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social no disponible.');
        }
        return [$actor->id(), $tenant->id(), $entity->id()];
    }

    private function financeRealDisabled(): ?JsonResponse
    {
        if (getenv('FINANCE_REAL_DATA_ENABLED') === 'true') { return null; }
        return $this->safeJson(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
    }

    /** @param array<string,mixed> $payload */
    private function safeJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
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
