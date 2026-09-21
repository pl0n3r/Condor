<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\AcceptAccountInvitation;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Shared\Version\AppVersion;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationActivationController extends AbstractController
{
    public function __construct(
        private readonly AcceptAccountInvitation $acceptInvitation,
        private readonly RateLimiterFactory $invitationActivationLimiter,
    ) {
    }

    #[Route(
        '/activar-cuenta/{token}',
        name: 'app_invitation_activate',
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(
        string $token,
        Request $request,
        AppVersion $version,
    ): Response {
        $invitation = $this->acceptInvitation->preview($token);

        if (!$invitation instanceof AccountInvitation) {
            return $this->render(
                'security/activate_invitation.html.twig',
                [
                    'app_version' => $version->human(),
                    'invitation' => null,
                    'error' => (
                        'Este enlace ya no está disponible. '
                        .'Solicita una nueva invitación.'
                    ),
                ],
                new Response(status: Response::HTTP_GONE),
            );
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $limit = $this->invitationActivationLimiter
                ->create($invitation->id())
                ->consume(1);

            if (!$limit->isAccepted()) {
                $response = $this->render(
                    'security/activate_invitation.html.twig',
                    [
                        'app_version' => $version->human(),
                        'invitation' => $invitation,
                        'error' => (
                            'Demasiados intentos. Espera antes de '
                            .'volver a intentar.'
                        ),
                    ],
                    new Response(
                        status: Response::HTTP_TOO_MANY_REQUESTS,
                    ),
                );
                $response->headers->set(
                    'Retry-After',
                    (string) max(
                        1,
                        $limit->getRetryAfter()
                            ->getTimestamp() - time(),
                    ),
                );

                return $response;
            }

            $csrf = (string) $request->request
                ->get('_csrf_token', '');

            if (
                !$this->isCsrfTokenValid(
                    'invitation_accept_'.$invitation->id(),
                    $csrf,
                )
            ) {
                return new Response(
                    'Solicitud inválida.',
                    Response::HTTP_FORBIDDEN,
                );
            }

            $password = (string) $request->request
                ->get('password', '');
            $confirmation = (string) $request->request
                ->get('password_confirmation', '');

            if ($password !== $confirmation) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                try {
                    $this->acceptInvitation->accept(
                        $token,
                        $password,
                    );
                    $this->addFlash(
                        'success',
                        'Cuenta activada. Ya puedes ingresar '
                        .'con tu nueva contraseña.',
                    );

                    return $this->redirectToRoute('app_login');
                } catch (DomainException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->render(
            'security/activate_invitation.html.twig',
            [
                'app_version' => $version->human(),
                'invitation' => $invitation,
                'error' => $error,
            ],
        );
    }
}
