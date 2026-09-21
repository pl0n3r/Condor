<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\PlatformStaffInvitationResult;
use App\Application\Identity\PlatformStaffManager;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/adminpl0n3r/api/staff')]
final class PlatformStaffController extends AbstractController
{
    public function __construct(
        private readonly PlatformStaffManager $manager,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactory $platformStaffInviteResendLimiter,
    ) {
    }

    #[Route('', name: 'platform_staff_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $owner = $this->owner();

        return $this->json([
            'staff' => $this->manager->overview($owner),
            'catalog' => PermissionCatalog::definitions(),
        ]);
    }

    #[Route(
        '/invitations',
        name: 'platform_staff_invite',
        methods: ['POST'],
    )]
    public function invite(Request $request): JsonResponse
    {
        $owner = $this->owner();
        $this->requireCsrf($request);
        $payload = $this->payload($request);
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
        $owner = $this->owner();
        $this->requireCsrf($request);
        $staff = $this->staff($staffId);
        $payload = $this->payload($request);
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
        $owner = $this->owner();
        $this->requireCsrf($request);
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
        $owner = $this->owner();
        $this->requireCsrf($request);
        $staff = $this->staff($staffId);

        $this->domain(function () use ($owner, $staff): null {
            $this->manager->revokeInvitation($owner, $staff);

            return null;
        });

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (
            !$user instanceof User
            || !$user->isActive()
            || !$user->hasRole(User::ROLE_PLATFORM_OWNER)
        ) {
            throw new AccessDeniedHttpException();
        }

        return $user;
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

    private function requireCsrf(Request $request): void
    {
        $token = (string) $request->headers
            ->get('X-CSRF-Token', '');

        if (
            !$this->isCsrfTokenValid(
                'platform_staff_management',
                $token,
            )
        ) {
            throw new AccessDeniedHttpException(
                'Token CSRF inválido.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        try {
            $payload = json_decode(
                (string) $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException(
                'JSON inválido.',
                $exception,
            );
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException(
                'El cuerpo debe ser un objeto JSON.',
            );
        }

        return $payload;
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
            'invitation' => [
                'id' => $result->invitation->id(),
                'expires_at' => (
                    $result->invitation
                        ->expiresAt()
                        ->format(DATE_ATOM)
                ),
                'activation_path_once' => $this->generateUrl(
                    'app_invitation_activate',
                    ['token' => $result->rawToken],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
                'delivery' => 'manual',
            ],
            'grants' => $this->manager
                ->grantPayloads($result->user),
        ];
    }
}
