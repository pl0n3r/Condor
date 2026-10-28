<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Compliance\ComplianceApiService;
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
}
