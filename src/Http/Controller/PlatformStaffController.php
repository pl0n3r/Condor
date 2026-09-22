<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\PlatformOwnerTenantContext;
use App\Application\Identity\PlatformStaffInvitationResult;
use App\Application\Identity\PlatformStaffManager;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/adminpl0n3r/api/staff')]
final class PlatformStaffController extends PlatformOwnerApiController
{
    public function __construct(
        private readonly PlatformStaffManager $manager,
        private readonly PlatformOwnerTenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactory $platformStaffInviteResendLimiter,
    ) {
    }

    #[Route('', name: 'platform_staff_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $owner = $this->platformOwner();

        $tenantPage = $this->tenantContext->tenantPage(
            1,
            PlatformOwnerTenantContext::MAX_PAGE_SIZE,
        );

        return $this->json([
            'staff' => $this->manager->overview($owner),
            'catalog' => PermissionCatalog::definitions(),
            'tenant_options' => array_map(
                static fn (array $tenant): array => [
                    'id' => $tenant['id'],
                    'name' => $tenant['name'],
                    'slug' => $tenant['slug'],
                ],
                $tenantPage['items'],
            ),
            'tenant_options_truncated' => $tenantPage['has_next'],
        ]);
    }

    #[Route(
        '/invitations',
        name: 'platform_staff_invite',
        methods: ['POST'],
    )]
    public function invite(Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, 'platform_staff_management');
        $payload = $this->jsonPayload($request);
        $grants = $this->grantDefinitions($payload['grants'] ?? null);

        $result = $this->domain(
            fn (): PlatformStaffInvitationResult => $this->manager->invite(
                $owner,
                (string) ($payload['email'] ?? ''),
                (string) ($payload['display_name'] ?? ''),
                $grants,
            ),
        );

        return $this->json(
            $this->invitationPayload($result),
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{staffId}/grants',
        name: 'platform_staff_grants_replace',
        methods: ['PUT'],
    )]
    public function replaceGrants(
        string $staffId,
        Request $request,
    ): JsonResponse {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, 'platform_staff_management');
        $staff = $this->staff($staffId);
        $payload = $this->jsonPayload($request);
        $grants = $this->grantDefinitions($payload['grants'] ?? null);

        $this->domain(
            fn (): array => $this->manager->replaceGrants(
                $owner,
                $staff,
                $grants,
            ),
        );

        return $this->json([
            'staff_id' => $staff->id(),
            'grants' => $this->manager->grantPayloads($staff),
        ]);
    }

    #[Route(
        '/{staffId}/invitations/resend',
        name: 'platform_staff_invite_resend',
        methods: ['POST'],
    )]
    public function resend(
        string $staffId,
        Request $request,
    ): JsonResponse {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, 'platform_staff_management');
        $staff = $this->staff($staffId);

        $limit = $this->platformStaffInviteResendLimiter
            ->create($owner->id().':'.$staff->id())
            ->consume(1);
        if (!$limit->isAccepted()) {
            $response = $this->json([
                'error' => (
                    'Espera antes de reenviar otra invitación a esta cuenta.'
                ),
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = $limit->getRetryAfter();
            $response->headers->set(
                'Retry-After',
                (string) max(
                    1,
                    $retryAfter->getTimestamp() - time(),
                ),
            );

            return $response;
        }

        $result = $this->domain(
            fn (): PlatformStaffInvitationResult => (
                $this->manager->reissueInvitation($owner, $staff)
            ),
        );

        return $this->json($this->invitationPayload($result));
    }

    #[Route(
        '/{staffId}/invitations',
        name: 'platform_staff_invite_revoke',
        methods: ['DELETE'],
    )]
    public function revoke(
        string $staffId,
        Request $request,
    ): Response {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, 'platform_staff_management');
        $staff = $this->staff($staffId);

        $this->domain(function () use ($owner, $staff): null {
            $this->manager->revokeInvitation($owner, $staff);

            return null;
        });

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function staff(string $staffId): User
    {
        $staff = $this->entityManager
            ->getRepository(User::class)
            ->find($staffId);

        if (
            !$staff instanceof User
            || !$staff->hasRole(User::ROLE_PLATFORM_STAFF)
        ) {
            throw new NotFoundHttpException(
                'Staff de plataforma no encontrado.',
            );
        }

        return $staff;
    }

    /**
     * @return list<array{
     *   tenant_id: string|null,
     *   module: string,
     *   actions: array<mixed>
     * }>
     */
    private function grantDefinitions(mixed $value): array
    {
        if (!is_array($value)) {
            throw new UnprocessableEntityHttpException(
                'Los permisos deben enviarse como una lista.',
            );
        }

        return array_values($value);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function domain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function invitationPayload(
        PlatformStaffInvitationResult $result,
    ): array {
        return [
            'staff' => [
                'id' => $result->user->id(),
                'name' => $result->user->displayName(),
                'email' => $result->user->email(),
                'active' => $result->user->isActive(),
            ],
            'invitation' => $this->activationInvitationPayload(
                $result->invitation,
                $result->rawToken,
            ),
            'grants' => $this->manager
                ->grantPayloads($result->user),
        ];
    }
}
