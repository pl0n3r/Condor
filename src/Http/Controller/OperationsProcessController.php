<?php
declare(strict_types=1);
namespace App\Http\Controller;
use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\OperationsProcessService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
/** Synthetic-only, tenant-authorized operational rehearsal, never a task runner. */
final class OperationsProcessController extends AbstractController
{
    use BranchApiSupport;
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionEntitlementContextFactory $entitlementContexts,
        private readonly EntitlementResolver $entitlementResolver,
    ) {
    }
    #[Route('/api/v1/branches/{branchId}/operations/processes', name: 'api_operations_processes', methods: ['GET'])]
    public function index(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $this->requirePreview($tenant->id());
        return $this->json(['branch_id' => $branch->id(),
            'processes' => OperationsProcessService::catalog(), 'persisted' => false]);
    }
    #[Route('/api/v1/branches/{branchId}/operations/processes/preview', name: 'api_operations_processes_preview', methods: ['POST'])]
    public function preview(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.update');
        $this->requirePreview($tenant->id());
        if (strlen((string) $request->getContent()) > 2048) {
            throw new UnprocessableEntityHttpException('Simulación demasiado extensa.');
        }
        $input = $this->payload($request, ['process_id', 'procedure_id', 'task_id', 'state']);
        return $this->json([
            'branch_id' => $branch->id(),
            'preview' => $this->domain(fn (): array => OperationsProcessService::preview($input)),
        ]);
    }
    private function requirePreview(string $tenantId): void
    {
        if (!in_array($this->getParameter('kernel.environment'), ['dev', 'test'], true)) {
            throw new NotFoundHttpException('Simulación no disponible.');
        }
        $entitlements = $this->domain(fn (): EntitlementSnapshot =>
            $this->entitlementResolver->resolve($this->entitlementContexts->forTenant(
                $tenantId,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ))
        );
        if (!$this->domain(fn (): bool => $entitlements->addOn('production-lite'))) {
            throw new UnprocessableEntityHttpException('Módulo de producción no habilitado.');
        }
    }
}
