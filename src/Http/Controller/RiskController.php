<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Risk\RiskApiService;
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
 * M12 synthetic-only HTTP facade. Live traffic is denied by the environment gate.
 * Session scope is temporary demonstration state, never production persistence.
 */
final class RiskController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/risks', name: 'api_risk_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.view');
        $rows = $this->domain(fn (): array => RiskApiService::listRecords(
            $this->getQueue($request), $this->scope($company, $office),
        ));
        $this->auditPreview($company, $actor, 'listed', 'fixture:risk/list');

        return $this->json(['risks' => $rows]);
    }

    #[Route('/api/v1/branches/{branchId}/risks/{slug}', name: 'api_risk_view', methods: ['GET'])]
    public function view(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.view');
        $ref = 'fixture:risk/'.$slug;
        $row = $this->domain(fn (): ?array => RiskApiService::viewRecord(
            $this->getQueue($request), $this->scope($company, $office), $ref,
        ));
        if ($row === null) {
            throw new NotFoundHttpException('Riesgo ficticio no encontrado.');
        }
        $this->auditPreview($company, $actor, 'viewed', $ref);

        return $this->json(['risk' => $row]);
    }

    #[Route('/api/v1/branches/{branchId}/risks', name: 'api_risk_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.create', true);
        $data = $this->payload($request, ['risk_ref', 'category', 'probability', 'impact']);
        $result = $this->domain(fn (): array => RiskApiService::createRecord(
            $this->getQueue($request), $this->scope($company, $office), $data,
        ));
        $this->auditPreview($company, $actor, 'created', $result['record']['risk_ref']);
        $request->getSession()->set('condor_risk_synthetic', $result['store']);

        return $this->json(['risk' => $result['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/risks/{slug}/state', name: 'api_risk_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $company, $office] = $this->access($request, $branchId, 'settings.update', true);
        $data = $this->payload($request, ['state']);
        $ref = 'fixture:risk/'.$slug;
        $next = is_string($data['state'] ?? null) ? $data['state'] : '';
        $result = $this->domain(fn (): array => RiskApiService::changeState(
            $this->getQueue($request), $this->scope($company, $office), $ref, $next,
        ));
        $this->auditPreview($company, $actor, 'state_changed', $ref);
        $request->getSession()->set('condor_risk_synthetic', $result['store']);

        return $this->json(['risk' => $result['record']]);
    }

    /** @return array{User,Tenant,Branch} */
    private function access(Request $request, string $branchId, string $permission, bool $write = false): array
    {
        $environment = $this->getParameter('kernel.environment');
        $allowTesting = $environment === 'test';
        $allowDevelopment = $environment === 'dev'
            && getenv('CONDOR_RISK_SYNTHETIC_API') === '1'
            && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion';
        if (!$allowTesting && !$allowDevelopment) {
            throw new NotFoundHttpException('Riesgos de prueba no disponibles.');
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
        $queue = $request->getSession()->get('condor_risk_synthetic', []);
        if (is_array($queue)) {
            return $queue;
        }
        throw new NotFoundHttpException('Archivo de prueba no disponible.');
    }

    private function scope(Tenant $company, Branch $office): string
    {
        return hash('sha256', sprintf('%s|%s', $company->id(), $office->id()));
    }

    private function auditPreview(Tenant $company, User $actor, string $event, string $ref): void
    {
        $action = sprintf('risk.synthetic.%s', $event);
        $this->audit($company, $actor, $action, RiskApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
