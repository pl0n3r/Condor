<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\SocialSecurity\SocialSecurityApiService;
use App\Domain\Identity\Entity\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Owner-only fixture M05 interface: never files, approves, or pays PILA. */
final class SocialSecurityController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialSecurityApiService $socialSecurity,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/social-security/affiliations', name: 'api_social_security_list', methods: ['GET'])]
    public function list(string $branchId): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->secureScope($branchId, 'users.view');
        return $this->locked(['affiliations' => $this->domain(
            fn (): array => $this->socialSecurity->list($tenant, $branch, $actor, true),
        )]);
    }

    #[Route('/api/v1/branches/{branchId}/social-security/affiliations/{slug}', name: 'api_social_security_view', methods: ['GET'])]
    public function view(string $branchId, string $slug): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->secureScope($branchId, 'users.view');
        $record = $this->domain(
            fn (): ?array => $this->socialSecurity->view($tenant, $branch, $actor, true, 'fixture:affiliation/'.$slug),
        );
        if ($record === null) {
            throw new NotFoundHttpException('Afiliación ficticia no encontrada.');
        }
        return $this->locked(['affiliation' => $record]);
    }

    #[Route('/api/v1/branches/{branchId}/social-security/affiliations', name: 'api_social_security_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->secureScope($branchId, 'users.create');
        $input = $this->payload($request, ['affiliation_ref', 'employee_ref', 'plan_ref']);
        return $this->locked(['affiliation' => $this->domain(
            fn (): array => $this->socialSecurity->register($tenant, $branch, $actor, true, $input),
        )], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/social-security/affiliations/{slug}/state', name: 'api_social_security_state', methods: ['PATCH'])]
    public function state(string $branchId, string $slug, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->secureScope($branchId, 'users.update');
        $input = $this->payload($request, ['state', 'expected_version']);
        return $this->locked(['affiliation' => $this->domain(
            fn (): array => $this->socialSecurity->revise(
                $tenant, $branch, $actor, true, 'fixture:affiliation/'.$slug, $input,
            ),
        )]);
    }

    /** @return array{0: \App\Domain\Identity\Entity\User, 1: \App\Domain\Organization\Entity\Tenant, 2: \App\Domain\Organization\Entity\Branch} */
    private function secureScope(string $branchId, string $permission): array
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test'
            && ($environment !== 'dev'
                || getenv('CONDOR_SOCIAL_SECURITY_SYNTHETIC_API') !== '1'
                || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion')) {
            throw new NotFoundHttpException('Seguridad social sintética deshabilitada.');
        }
        [$actor, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, $permission);
        if (!$actor->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('La operación ficticia requiere dueño del tenant.');
        }
        return [$actor, $tenant, $branch];
    }

    /** @param array<string, mixed> $data */
    private function locked(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $reply = $this->json($data, $status);
        $reply->headers->set('Cache-Control', 'private, no-store');
        $reply->headers->set('X-Content-Type-Options', 'nosniff');
        return $reply;
    }
}
