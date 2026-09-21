<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\PlatformTenantInvitationResult;
use App\Application\Identity\PlatformTenantManager;
use App\Application\Onboarding\ProvisionTenantInput;
use App\Domain\Identity\Entity\User;
use DomainException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[Route('/adminpl0n3r/api/tenants')]
final class PlatformTenantController extends AbstractController
{
    public function __construct(
        private readonly PlatformTenantManager $manager,
    ) {
    }

    #[Route('', name: 'platform_tenant_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $owner = $this->owner();
        $this->requireCsrf($request);
        $payload = $this->payload($request);

        $result = $this->domain(
            fn (): PlatformTenantInvitationResult => (
                $this->manager->createAndInviteOwner(
                    $owner,
                    new ProvisionTenantInput(
                        (string) ($payload['name'] ?? ''),
                        strtolower(trim((string) ($payload['slug'] ?? ''))),
                        (string) ($payload['legal_name'] ?? ''),
                        self::nullableString($payload['nit'] ?? null),
                        (string) ($payload['branch_name'] ?? ''),
                    ),
                    (string) ($payload['owner_email'] ?? ''),
                    (string) ($payload['owner_name'] ?? ''),
                )
            ),
        );

        return $this->json(
            $this->resultPayload($result),
            Response::HTTP_CREATED,
        );
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

    private function requireCsrf(Request $request): void
    {
        $token = (string) $request->headers
            ->get('X-CSRF-Token', '');

        if (
            !$this->isCsrfTokenValid(
                'platform_tenant_management',
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
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function domain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationFailedException $exception) {
            $first = $exception->getViolations()->get(0);
            throw new UnprocessableEntityHttpException(
                $first !== null
                    ? (string) $first->getMessage()
                    : 'Los datos de la empresa no son válidos.',
                $exception,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function resultPayload(
        PlatformTenantInvitationResult $result,
    ): array {
        return [
            'tenant' => [
                'id' => $result->provisioning->tenant->id(),
                'name' => $result->provisioning->tenant->name(),
                'slug' => $result->provisioning->tenant->slug(),
            ],
            'branch' => [
                'id' => $result->provisioning->branch->id(),
                'name' => $result->provisioning->branch->name(),
                'slug' => $result->provisioning->branch->slug(),
            ],
            'owner' => [
                'id' => $result->owner->id(),
                'name' => $result->owner->displayName(),
                'email' => $result->owner->email(),
                'active' => $result->owner->isActive(),
            ],
            'invitation' => [
                'id' => $result->invitation->id(),
                'expires_at' => (
                    $result->invitation->expiresAt()->format(DATE_ATOM)
                ),
                'activation_path_once' => $this->generateUrl(
                    'app_invitation_activate',
                    ['token' => $result->rawToken],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
                'delivery' => 'manual',
            ],
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
