<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\ChangeOwnPassword;
use App\Application\Identity\CompletePasswordReset;
use App\Application\Identity\RequestPasswordReset;
use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordSecurityController extends AbstractController
{
    private const GENERIC_RESET_MESSAGE = (
        'Si el correo corresponde a una cuenta activa, '
        .'enviaremos instrucciones para continuar.'
    );

    public function __construct(
        private readonly RequestPasswordReset $requestPasswordReset,
        private readonly CompletePasswordReset $completePasswordReset,
        private readonly ChangeOwnPassword $changeOwnPassword,
        private readonly RateLimiterFactory $passwordResetRequestIpLimiter,
        private readonly RateLimiterFactory $passwordResetRequestAccountLimiter,
        private readonly RateLimiterFactory $passwordResetConsumeIpLimiter,
        private readonly RateLimiterFactory $passwordResetConsumeTokenLimiter,
        private readonly RateLimiterFactory $passwordChangeLimiter,
    ) {
    }

    #[Route(
        '/admin/recuperar-contrasena',
        name: 'app_password_reset_request',
        methods: ['GET', 'POST'],
    )]
    public function requestReset(Request $request, AppVersion $version): Response
    {
        $message = null;

        if ($request->isMethod('POST')) {
            $csrfFailure = $this->csrfFailure($request, 'password_reset_request');
            if ($csrfFailure instanceof Response) {
                return $csrfFailure;
            }

            $startedAt = hrtime(true);
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $ipKey = $request->getClientIp() ?? 'unknown';

            $ipLimit = $this->passwordResetRequestIpLimiter
                ->create($ipKey)
                ->consume();
            $accountLimit = $this->passwordResetRequestAccountLimiter
                ->create(hash('sha256', $email))
                ->consume();

            if ($ipLimit->isAccepted() && $accountLimit->isAccepted()) {
                $this->requestPasswordReset->request($email);
            }

            self::padResetResponse($startedAt);
            $message = self::GENERIC_RESET_MESSAGE;
        }

        return $this->privateResponse($this->render(
            'security/password_reset_request.html.twig',
            [
                'app_version' => $version->human(),
                'message' => $message,
            ],
        ));
    }

    #[Route(
        '/admin/restablecer-contrasena',
        name: 'app_password_reset_form',
        methods: ['GET', 'POST'],
    )]
    public function resetPassword(Request $request, AppVersion $version): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $csrfFailure = $this->csrfFailure($request, 'password_reset_complete');
            if ($csrfFailure instanceof Response) {
                return $csrfFailure;
            }

            $token = strtolower(trim((string) $request->request->get('token', '')));
            $ipLimit = $this->passwordResetConsumeIpLimiter
                ->create($request->getClientIp() ?? 'unknown')
                ->consume();
            $tokenLimit = $this->passwordResetConsumeTokenLimiter
                ->create(hash('sha256', $token))
                ->consume();

            if (!$ipLimit->isAccepted() || !$tokenLimit->isAccepted()) {
                $error = self::resetFailureMessage();
            } else {
                $password = (string) $request->request->get('password', '');
                $confirmation = (string) $request->request->get('password_confirmation', '');

                if ($password !== $confirmation) {
                    $error = 'Las contraseñas no coinciden.';
                } else {
                    try {
                        $this->completePasswordReset->complete($token, $password);
                        $this->addFlash(
                            'success',
                            'Contraseña actualizada. Ya puedes ingresar.',
                        );

                        return $this->privateResponse(
                            $this->redirectToRoute('app_login'),
                        );
                    } catch (DomainException) {
                        $error = self::resetFailureMessage();
                    }
                }
            }
        }

        return $this->privateResponse($this->render(
            'security/password_reset_form.html.twig',
            [
                'app_version' => $version->human(),
                'error' => $error,
            ],
        ));
    }

    #[Route(
        '/admin/seguridad/contrasena',
        name: 'app_password_change',
        methods: ['GET', 'POST'],
    )]
    public function changePassword(Request $request, AppVersion $version): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $csrfFailure = $this->csrfFailure($request, 'password_change');
            if ($csrfFailure instanceof Response) {
                return $csrfFailure;
            }

            $limit = $this->passwordChangeLimiter
                ->create($user->id())
                ->consume();

            if (!$limit->isAccepted()) {
                $error = 'Demasiados intentos. Espera antes de volver a intentar.';
            } else {
                $newPassword = (string) $request->request->get('new_password', '');
                $confirmation = (string) $request->request->get(
                    'password_confirmation',
                    '',
                );

                if ($newPassword !== $confirmation) {
                    $error = 'Las contraseñas no coinciden.';
                } else {
                    try {
                        $this->changeOwnPassword->change(
                            $user,
                            (string) $request->request->get('current_password', ''),
                            $newPassword,
                        );
                        $request->getSession()->migrate(true);
                        $this->addFlash('success', 'Contraseña actualizada.');

                        return $this->privateResponse(
                            $this->redirectToRoute('app_password_change'),
                        );
                    } catch (DomainException $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }
        }

        return $this->privateResponse($this->render(
            'security/password_change.html.twig',
            [
                'app_version' => $version->human(),
                'error' => $error,
            ],
        ));
    }

    private function csrfFailure(Request $request, string $tokenId): ?Response
    {
        $token = (string) $request->request->get('_csrf_token', '');
        if ($this->isCsrfTokenValid($tokenId, $token)) {
            return null;
        }

        return $this->privateResponse(new Response(
            'Solicitud inválida.',
            Response::HTTP_FORBIDDEN,
        ));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private static function padResetResponse(int $startedAt): void
    {
        $minimumNs = 250_000_000 + random_int(0, 20_000_000);
        $elapsedNs = hrtime(true) - $startedAt;
        if ($elapsedNs < $minimumNs) {
            usleep((int) (($minimumNs - $elapsedNs) / 1000));
        }
    }

    private static function resetFailureMessage(): string
    {
        return 'No pudimos completar la recuperación. Solicita un enlace nuevo.';
    }
}
