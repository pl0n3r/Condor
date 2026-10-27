<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\CustomerService\CustomerCaseApiService;
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

/** Development-only synthetic service case API; no real customer data or integrations. */
final class CustomerServiceController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/customer-service', name: 'api_customer_service_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.view');
        $records = $this->domain(fn (): array => CustomerCaseApiService::listRecords(
            $this->store($request), $this->scope($tenant->id(), $branch->id()),
        ));
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.listed', 'fixture:case/list');
        return $this->json(['cases' => $records]);
    }

    #[Route('/api/v1/branches/{branchId}/customer-service/{slug}', name: 'api_customer_service_view', methods: ['GET'])]
    public function view(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.view');
        $ref = 'fixture:case/'.$slug;
        $record = $this->domain(fn (): ?array => CustomerCaseApiService::viewRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
        ));
        if ($record === null) {
            throw new NotFoundHttpException('Caso sintético no encontrado.');
        }
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.viewed', $ref);
        return $this->json(['case' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/customer-service', name: 'api_customer_service_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.create');
        $payload = $this->payload($request, ['case_ref', 'type']);
        $result = $this->domain(fn (): array => CustomerCaseApiService::createRecord(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $payload,
        ));
        $request->getSession()->set('condor_customer_service_synthetic', $result['store']);
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.created', $result['record']['case_ref']);
        return $this->json(['case' => $result['record']], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/customer-service/{slug}/state', name: 'api_customer_service_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'customers.update');
        $payload = $this->payload($request, ['state']);
        $ref = 'fixture:case/'.$slug;
        $result = $this->domain(fn (): array => CustomerCaseApiService::changeState(
            $this->store($request), $this->scope($tenant->id(), $branch->id()), $ref,
            is_string($payload['state'] ?? null) ? $payload['state'] : '',
        ));
        $request->getSession()->set('condor_customer_service_synthetic', $result['store']);
        $this->recordAudit($tenant, $user, 'customer_service.synthetic.state_changed', $ref);
        return $this->json(['case' => $result['record']]);
    }

    /** @return array<string,mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get('condor_customer_service_synthetic', []);
        if (!is_array($store)) {
            throw new NotFoundHttpException('Casos sintéticos no disponibles.');
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
            || getenv('CONDOR_CUSTOMER_SERVICE_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion')) {
            throw new NotFoundHttpException('Servicio al cliente sintético no disponible.');
        }
    }

    private function recordAudit(Tenant $tenant, User $user, string $action, string $ref): void
    {
        $this->audit($tenant, $user, $action, CustomerCaseApiService::class, $ref, [
            'synthetic_only' => true,
        ]);
        $this->entityManager->flush();
    }
}
