<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Onboarding\PublicTrialEnrollmentService;
use DomainException;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Public route, independent of the platform-owner's manual onboarding. */
final readonly class PublicTrialEnrollmentController
{
    public function __construct(
        private PublicTrialEnrollmentService $enrollment,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/trial/solicitar', name: 'public_trial_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        if (!$this->enrollment->enabled()) {
            return self::json(['error' => 'Servicio no disponible.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $body = $request->getContent();
        if (strlen($body) > 4096 || !str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
            return self::json(['error' => 'Solicitud inválida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                throw new DomainException('Solicitud inválida.');
            }
            $this->enrollment->request($payload);
        } catch (JsonException | DomainException) {
            return self::json(['error' => 'Solicitud inválida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException) {
            return self::json(['error' => 'Servicio no disponible.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Unknown, existing, consumed and eligible emails share this response.
        return self::json([
            'status' => 'pending_verification',
            'message' => 'Si la solicitud procede, recibirás un enlace de verificación.',
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/trial/verificar/{token}', name: 'public_trial_verify', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function verify(string $token, Request $request): Response
    {
        if (!$this->enrollment->enabled()) {
            return self::page('Servicio no disponible.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if (!$this->enrollment->canVerify($token)) {
            return self::page('El enlace ha vencido o ya se utilizó.', Response::HTTP_GONE);
        }

        $csrfId = 'public_trial_verify_'.$token;
        if ($request->isMethod('GET')) {
            $csrf = htmlspecialchars($this->csrf->getToken($csrfId)->getValue(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $action = '/trial/verificar/'.$token;
            $fields = [
                'tenant_name' => 'Nombre de la empresa',
                'tenant_slug' => 'Identificador de la empresa',
                'legal_name' => 'Razón social',
                'nit' => 'NIT (opcional)',
                'branch_name' => 'Sede principal',
                'owner_name' => 'Nombre del propietario',
                'owner_password' => 'Contraseña (mínimo 12 caracteres)',
            ];
            $html = '<h1>Confirmar trial de Condor</h1><form method="post" action="'.$action.'">'
                .'<input type="hidden" name="_csrf_token" value="'.$csrf.'">';
            foreach ($fields as $field => $label) {
                $type = $field === 'owner_password' ? 'password' : 'text';
                $required = $field === 'nit' ? '' : ' required';
                $html .= '<label>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                    .'<input name="'.$field.'" type="'.$type.'" maxlength="'
                    .($field === 'owner_password' ? '4096' : '180').'"'.$required.'></label>';
            }
            $html .= '<button type="submit">Activar trial de 14 días</button></form>';
            return self::page($html, Response::HTTP_OK, true);
        }

        $csrf = $request->request->get('_csrf_token');
        if (!is_string($csrf) || !$this->csrf->isTokenValid(new CsrfToken($csrfId, $csrf))) {
            return self::page('Solicitud inválida.', Response::HTTP_FORBIDDEN);
        }
        $payload = $request->request->all();
        unset($payload['_csrf_token']);
        try {
            if (!$this->enrollment->verify($token, $payload)) {
                return self::page('El enlace ha vencido o ya se utilizó.', Response::HTTP_GONE);
            }
        } catch (DomainException) {
            return self::page('Datos de empresa inválidos.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException) {
            return self::page('Servicio no disponible.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $response = new RedirectResponse('/admin/login', Response::HTTP_SEE_OTHER);
        self::secureHeaders($response);
        return $response;
    }

    /** @param array<string,mixed> $data */
    private static function json(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        self::secureHeaders($response);
        return $response;
    }

    private static function page(string $content, int $status, bool $trustedMarkup = false): Response
    {
        $body = $trustedMarkup ? $content : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $response = new Response('<!doctype html><html lang="es"><meta charset="utf-8"><title>Condor · Trial</title><body>'.$body.'</body></html>', $status);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        self::secureHeaders($response);
        return $response;
    }

    private static function secureHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; form-action 'self'; frame-ancestors 'none'");
    }
}
