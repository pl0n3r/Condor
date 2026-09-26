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

    #[Route('/admin/recuperar-contrasena', name: 'app_password_reset_request', methods: ['GET', 'POST'])]
    public function requestReset(Request $request, AppVersion $version): Response
    {
        $message = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $startedAt = hrtime(true);
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $ipKey = $request->getClientIp() ?? 'unknown';
            $accountKey = hash('sha256', $email);

            $ipLimit = $this->passwordResetRequestIpLimiter->create($ipKey)->consume(1);
            $accountLimit = $this
                ->passwordResetRequestAccountLimiter
                ->create($accountKey)
                ->consume(1);

            $csrfFailure = $this->csrfFailure(
                $request,
                'password_reset_request',
            );
            if ($csrfFailure instanceof Response) {
                return $csrfFailure;
            }

            if ($ipLimit->isAccepted() && $accountLimit->isAccepted()) {
                $this->requestPasswordReset->request($email);
            }

            // Mínimo común para reducir diferencias de tiempo entre cuenta existente/inexistente.
            $minimumNs = 250_000_000 + random_int(0, 20_000_000);
            $elapsed = hrtime(true) - $startedAt;
            if ($elapsed < $minimumNs) {
                usleep((int) (($minimumNs - $elapsed) / 1000));
            }

            $message = self::GENERIC_RESET_MESSAGE;
        }

        return $this->noReferrer($this->render('security/password_reset_request.html.twig', [
            'app_version' => $version->human(),
            'message' => $message,
            'error' => $error,
        ]));
    }

    #[Route('/admin/restablecer-contrasena', name: 'app_password_reset_form', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, AppVersion $version): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $csrfFailure = $this->csrfFailure(
                $request,
                'password_reset_complete',
            );
            if ($csrfFailure instanceof Response) {
                return $csrfFailure;
            }

            $token = strtolower(trim((string) $request->request->get('token', '')));
            $tokenKey = hash('sha256', $token);
            $ipKey = $request->getClientIp() ?? 'unknown';
            $ipLimit = $this->passwordResetConsumeIpLimiter->create($ipKey)->consume(1);
            $tokenLimit = $this
                ->passwordResetConsumeTokenLimiter
                ->create($tokenKey)
                ->consume(1);

            if (!$ipLimit->isAccepted() || !$tokenLimit->isAccepted()) {
                $error = 'No pudimos completar la recuperación. Solicita un enlace nuevo.';
            } else {
                $password = (string) $request->request->get('password', '');
                $confirmation = (string) $request->request->get('password_confirmation', '');

                if ($password !== $confirmation) {
                    $error = 'Las contraseñas no coinciden.';
                } else {
                    try {
                        $this->completePasswordReset->complete($token, $password);
                        $this->addFlash('success', 'Contraseña actualizada. Ya puedes ingresar.');

                        return $this->redirectToRoute('app_login');
                    } catch (DomainException) {
                        $error = 'No pudimos completar la recuperación. Solicita un enlace nuevo.';
                    }
                }
            }
        }

        return $this->noReferrer($this->render('security/password_reset_form.html.twig', [
            'app_version' => $version->human(),
            'error' => $error,
        ]));
    }

    #[Route('/admin/seguridad/contrasena', name: 'app_password_change', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, AppVersion $version): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $csrfFailure = $this->csrfFailure(
                $request,
                'password_change',
            );
            if ($csrfFailure instanceof Response) {
                return $csrfFailure;
            }

            $limit = $this->passwordChangeLimiter->create($user->id())->consume(1);
            if (!$limit->isAccepted()) {
                $error = 'Demasiados intentos. Espera antes de volver a intentar.';
            } else {
                $newPassword = (string) $request->request->get('new_password', '');
                $confirmation = (string) $request->request->get('password_confirmation', '');
                if ($newPassword !== $confirmation) {
                    $error = 'Las contraseñas no coinciden.';
                } else {
                    try {
                        $this->changeOwnPassword->change(
                            $user,
                            (string) $request->request->get('current_password', ''),
                            $newPassword,
                        );
                        // El hash nuevo invalida sesiones antiguas al refrescar el usuario;
                        // la sesión actual recibe además un id nuevo.
                        $request->getSession()->migrate(true);
                        $this->addFlash('success', 'Contraseña actualizada.');

                        return $this->redirectToRoute('app_password_change');
                    } catch (DomainException $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }
        }

        return $this->noReferrer($this->render('security/password_change.html.twig', [
            'app_version' => $version->human(),
            'error' => $error,
        ]));
    }

    private function csrfFailure(Request $request, string $tokenId): ?Response
    {
        $token = (string) $request->request->get('_csrf_token', '');
        if ($this->isCsrfTokenValid($tokenId, $token)) {
            return null;
        }

        return $this->noReferrer(new Response(
            'Solicitud inválida.',
            Response::HTTP_FORBIDDEN,
        ));
    }

    private function noReferrer(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
