<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Quality\QualityApiService;
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

/** M13: quality HTTP API for synthetic fixtures only; disabled in production. */
final class QualityController extends AbstractController
{
    use BranchApiSupport;

    private const SESSION_KEY = 'condor_quality_synthetic';

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/quality/records', name: 'api_quality_records', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.view');
        $records = $this->domain(fn (): array => QualityApiService::listRecords(
            $this->store($request), $this->scope($tenant, $branch),
        ));
        $this->auditSynthetic($tenant, $actor, 'listed', 'fixture:quality-record/list');
        return $this->json(['records' => $records, 'synthetic_only' => true]);
    }

    #[Route('/api/v1/branches/{branchId}/quality/records/{slug}', name: 'api_quality_record_view', methods: ['GET'])]
    public function view(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.view');
        $ref = 'fixture:quality-record/'.$slug;
        $record = $this->domain(fn (): ?array => QualityApiService::viewRecord(
            $this->store($request), $this->scope($tenant, $branch), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Registro de calidad ficticio no encontrado.');
        }
        $this->auditSynthetic($tenant, $actor, 'viewed', $ref);
        return $this->json(['record' => $record, 'synthetic_only' => true]);
    }

    #[Route('/api/v1/branches/{branchId}/quality/records', name: 'api_quality_record_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.create', true);
        $payload = $this->payload($request, ['record_ref', 'source_ref', 'kind']);
        $result = $this->domain(fn (): array => QualityApiService::createRecord(
            $this->store($request), $this->scope($tenant, $branch), $payload,
        ));
        $this->auditSynthetic($tenant, $actor, 'created', $result['record']['record_ref']);
        $request->getSession()->set(self::SESSION_KEY, $result['store']);
        return $this->json(['record' => $result['record'], 'synthetic_only' => true], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/quality/records/{slug}/state', name: 'api_quality_record_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.update', true);
        $payload = $this->payload($request, ['state']);
        $next = is_string($payload['state'] ?? null) ? $payload['state'] : '';
        $ref = 'fixture:quality-record/'.$slug;
        $result = $this->domain(fn (): array => QualityApiService::changeState(
            $this->store($request), $this->scope($tenant, $branch), $ref, $next,
        ));
        $this->auditSynthetic($tenant, $actor, 'state_changed', $ref);
        $request->getSession()->set(self::SESSION_KEY, $result['store']);
        return $this->json(['record' => $result['record'], 'synthetic_only' => true]);
    }

    /** @return array{User,Tenant,Branch} */
    private function access(Request $request, string $branchId, string $permission, bool $write = false): array
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_QUALITY_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        )) {
            throw new NotFoundHttpException('Calidad sintética no disponible.');
        }
        if ($write) {
            $this->requireCsrf($request);
        }
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, $permission);
        return [$actor, $tenant, $branch];
    }

    /** @return array<string,mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get(self::SESSION_KEY, []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Cola de calidad ficticia inválida.');
        }
        return $store;
    }

    private function scope(Tenant $tenant, Branch $branch): string
    {
        return hash('sha256', $tenant->id().'|'.$branch->id());
    }

    private function auditSynthetic(Tenant $tenant, User $actor, string $event, string $ref): void
    {
        $this->audit($tenant, $actor, 'quality.synthetic.'.$event, QualityApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
