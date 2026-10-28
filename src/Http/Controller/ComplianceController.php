<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Compliance\ComplianceApiService;
use App\Application\Compliance\OccupationalApiService;
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

/** Construction-only API for a session-backed synthetic compliance review queue. */
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
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.view');
        $rows = $this->domain(fn (): array => ComplianceApiService::listRecords(
            $this->getQueue($request), $this->scope($company, $office),
        ));
        $this->auditPreview($company, $actor, 'listed', 'fixture:evidence/list');
        return $this->json(['evidence' => $rows]);
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence/{slug}', name: 'api_compliance_evidence_view', methods: ['GET'])]
    public function view(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.view');
        $fixture = 'fixture:evidence/'.$slug;
        $row = $this->domain(fn (): ?array => ComplianceApiService::viewRecord(
            $this->getQueue($request), $this->scope($company, $office), $fixture,
        ));
        if ($row === null) {
            throw new NotFoundHttpException('Evidencia ficticia no encontrada.');
        }
        $this->auditPreview($company, $actor, 'viewed', $fixture);
        return $this->json(['evidence' => $row]);
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence', name: 'api_compliance_evidence_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.create', true);
        $data = $this->payload($request, ['evidence_ref', 'obligation_ref', 'type']);
        $outcome = $this->domain(fn (): array => ComplianceApiService::createRecord(
            $this->getQueue($request), $this->scope($company, $office), $data,
        ));
        $this->auditPreview($company, $actor, 'created', $outcome['record']['evidence_ref']);
        $request->getSession()->set('condor_compliance_synthetic', $outcome['store']);
        return $this->json(['evidence' => $outcome['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/compliance/evidence/{slug}/state', name: 'api_compliance_evidence_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.update', true);
        $data = $this->payload($request, ['state']);
        $fixture = 'fixture:evidence/'.$slug;
        $newState = is_string($data['state'] ?? null) ? $data['state'] : '';
        $outcome = $this->domain(fn (): array => ComplianceApiService::changeState(
            $this->getQueue($request), $this->scope($company, $office), $fixture, $newState,
        ));
        $this->auditPreview($company, $actor, 'state_changed', $fixture);
        $request->getSession()->set('condor_compliance_synthetic', $outcome['store']);
        return $this->json(['evidence' => $outcome['record']]);
    }

    /** @return array{User,Tenant,Branch} */
    private function access(Request $request, string $branchId, string $permission, bool $write = false): array
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_COMPLIANCE_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        )) {
            throw new NotFoundHttpException('Cumplimiento sintético no disponible.');
        }
        if ($write) {
            $this->requireCsrf($request);
        }
        [$actor, $company, $office] = $this->authorizedBranchScope($branchId, $permission);
        return [$actor, $company, $office];
    }

    /** @return array<string,mixed> */
    private function getQueue(Request $request): array
    {
        $value = $request->getSession()->get('condor_compliance_synthetic', []);
        if (!is_array($value)) {
            throw new NotFoundHttpException('Archivo de prueba no disponible.');
        }
        return $value;
    }

    private function scope(Tenant $company, Branch $office): string
    {
        return hash('sha256', $company->id().'|'.$office->id());
    }

    private function auditPreview(Tenant $company, User $actor, string $event, string $fixture): void
    {
        $this->audit($company, $actor, 'compliance.synthetic.'.$event, ComplianceApiService::class, $fixture, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }

    // SG-SST intentionally uses a distinct synthetic session store and strict environment gate.
    #[Route('/api/v1/branches/{branchId}/sgsst/records', name: 'api_sgsst_record_list', methods: ['GET'])]
    public function listOccupational(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->occupationalAccess($request, $branchId, 'settings.view');
        $rows = $this->domain(fn (): array => OccupationalApiService::listRecords(
            $this->occupationalStore($request), $this->occupationalScope($tenant, $branch),
        ));
        $this->occupationalAudit($tenant, $actor, 'listed', 'fixture:sgsst-record/list');
        return $this->json(['records' => $rows, 'synthetic_only' => true]);
    }

    #[Route('/api/v1/branches/{branchId}/sgsst/records/{slug}', name: 'api_sgsst_record_view', methods: ['GET'])]
    public function viewOccupational(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->occupationalAccess($request, $branchId, 'settings.view');
        $ref = 'fixture:sgsst-record/'.$slug;
        $row = $this->domain(fn (): ?array => OccupationalApiService::viewRecord(
            $this->occupationalStore($request), $this->occupationalScope($tenant, $branch), $ref,
        ));
        if ($row === null) {
            throw new NotFoundHttpException('Registro SG-SST ficticio no encontrado.');
        }
        $this->occupationalAudit($tenant, $actor, 'viewed', $ref);
        return $this->json(['record' => $row, 'synthetic_only' => true]);
    }

    #[Route('/api/v1/branches/{branchId}/sgsst/records', name: 'api_sgsst_record_create', methods: ['POST'])]
    public function createOccupational(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->occupationalAccess($request, $branchId, 'settings.create', true);
        $payload = $this->payload($request, ['record_ref', 'source_ref', 'kind']);
        $outcome = $this->domain(fn (): array => OccupationalApiService::createRecord(
            $this->occupationalStore($request), $this->occupationalScope($tenant, $branch), $payload,
        ));
        $this->occupationalAudit($tenant, $actor, 'created', $outcome['record']['record_ref']);
        $request->getSession()->set('condor_sgsst_synthetic', $outcome['store']);
        return $this->json(['record' => $outcome['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/sgsst/records/{slug}/state', name: 'api_sgsst_record_state', methods: ['PATCH'])]
    public function changeOccupationalState(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->occupationalAccess($request, $branchId, 'settings.update', true);
        $payload = $this->payload($request, ['state']);
        $state = is_string($payload['state'] ?? null) ? $payload['state'] : '';
        $ref = 'fixture:sgsst-record/'.$slug;
        $outcome = $this->domain(fn (): array => OccupationalApiService::changeState(
            $this->occupationalStore($request), $this->occupationalScope($tenant, $branch), $ref, $state,
        ));
        $this->occupationalAudit($tenant, $actor, 'state_changed', $ref);
        $request->getSession()->set('condor_sgsst_synthetic', $outcome['store']);
        return $this->json(['record' => $outcome['record']]);
    }

    /** @return array{User,Tenant,Branch} */
    private function occupationalAccess(Request $request, string $branchId, string $permission, bool $write = false): array
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_OCCUPATIONAL_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        )) {
            throw new NotFoundHttpException('SG-SST sintético no disponible.');
        }
        if ($write) {
            $this->requireCsrf($request);
        }
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, $permission);
        return [$actor, $tenant, $branch];
    }

    /** @return array<string,mixed> */
    private function occupationalStore(Request $request): array
    {
        $store = $request->getSession()->get('condor_sgsst_synthetic', []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Archivo SG-SST ficticio no disponible.');
        }
        return $store;
    }

    private function occupationalScope(Tenant $tenant, Branch $branch): string
    {
        return hash('sha256', $tenant->id().'|'.$branch->id());
    }

    private function occupationalAudit(Tenant $tenant, User $actor, string $event, string $ref): void
    {
        $this->audit($tenant, $actor, 'sgsst.synthetic.'.$event, OccupationalApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }

}
