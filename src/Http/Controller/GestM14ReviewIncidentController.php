<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\SecurityReview\SyntheticReviewIncidentStore;
use DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * S03/S04/S06 fixture-only backend: auth, tenant, branch, permission and CSRF
 * are checked on every request. No provider, real incident or database write.
 */
final class GestM14ReviewIncidentController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SyntheticReviewIncidentStore $store,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/m14/synthetic', name: 'api_m14_synthetic_list', methods: ['GET'])]
    public function list(string $branchId, Request $request): JsonResponse
    {
        $this->requirePreview();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'roles.view');

        return $this->json([
            'mode' => 'synthetic',
            'items' => $this->store->items($request->getSession(), $tenant->id(), $branch->id()),
            'audit' => $this->store->audit($request->getSession(), $tenant->id(), $branch->id()),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/v1/branches/{branchId}/m14/synthetic', name: 'api_m14_synthetic_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requirePreview();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'roles.update');
        $payload = $this->payload($request, ['kind']);
        if (!is_string($payload['kind'] ?? null)) {
            throw new UnprocessableEntityHttpException('Tipo sintético inválido.');
        }

        $record = $this->domain(fn (): array => $this->store->create(
            $request->getSession(), $tenant->id(), $branch->id(), $user->id(), $payload['kind'],
        ));

        return $this->json(['mode' => 'synthetic', 'item' => $record],
            Response::HTTP_CREATED, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/v1/branches/{branchId}/m14/synthetic/{id}', name: 'api_m14_synthetic_transition', methods: ['PATCH'])]
    public function transition(string $branchId, string $id, Request $request): JsonResponse
    {
        $this->requirePreview();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'roles.update');
        $payload = $this->payload($request, ['status']);
        if (!is_string($payload['status'] ?? null)) {
            throw new UnprocessableEntityHttpException('Estado sintético inválido.');
        }

        try {
            $record = $this->store->transition(
                $request->getSession(), $tenant->id(), $branch->id(), $user->id(),
                $id, $payload['status'],
            );
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'Simulación no encontrada en el ámbito solicitado.') {
                throw new NotFoundHttpException('Simulación no encontrada.');
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $this->json(['mode' => 'synthetic', 'item' => $record],
            headers: ['Cache-Control' => 'private, no-store']);
    }

    private function requirePreview(): void
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw new NotFoundHttpException('Esta simulación no está habilitada.');
        }
    }
}
