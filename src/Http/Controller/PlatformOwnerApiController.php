<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

abstract class PlatformOwnerApiController extends AbstractController
{
    protected function platformOwner(): User
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

    protected function requireManagementCsrf(
        Request $request,
        string $tokenId,
    ): void {
        $token = (string) $request->headers->get('X-CSRF-Token', '');

        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw new AccessDeniedHttpException('Token CSRF inválido.');
        }
    }

    /** @return array<string, mixed> */
    protected function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode(
                (string) $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException(
                'El cuerpo debe ser un objeto JSON.',
            );
        }

        return $payload;
    }

    /** @return array{id: string, expires_at: string, activation_path_once: string, delivery: string} */
    protected function activationInvitationPayload(
        AccountInvitation $invitation,
        string $rawToken,
    ): array {
        return [
            'id' => $invitation->id(),
            'expires_at' => $invitation->expiresAt()->format(DATE_ATOM),
            'activation_path_once' => $this->generateUrl(
                'app_invitation_activate',
                ['token' => $rawToken],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            ),
            'delivery' => 'manual',
        ];
    }
}
