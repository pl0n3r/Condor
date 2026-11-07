<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Notification\MailProviderConfiguration;
use App\Application\Platform\PlatformIntegrationSettings;
use App\Infrastructure\Notification\MailerFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

/**
 * Plataforma, nunca tenant: únicamente se exponen metadatos de secretos.
 * Las claves reales se escriben por la API cifrada de #1726; jamás se leen.
 */
#[Route('/adminpl0n3r/api/integrations')]
final class PlatformIntegrationsController extends PlatformOwnerApiController
{
    private const CSRF_ID = 'platform_integrations_management';
    private const SECRET_KEYS = ['mail.api_key', 'sentry.dsn'];
    private const PLAIN_KEYS = ['mail.provider', 'mail.from'];
    private const ALL_KEYS = ['mail.provider', 'mail.from', 'mail.api_key', 'sentry.dsn'];

    public function __construct(
        private readonly PlatformIntegrationSettings $settings,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        #[Autowire(service: 'limiter.platform_integrations_write')]
        private readonly RateLimiterFactory $writeLimiter,
        #[Autowire(service: 'limiter.platform_integrations_test_email')]
        private readonly RateLimiterFactory $testEmailLimiter,
        private readonly MailProviderConfiguration $mailConfiguration,
        private readonly MailerFactory $mailerFactory,
    ) {
    }

    #[Route('', name: 'platform_integrations_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $owner = $this->platformOwner();

        $settings = [];
        foreach (self::ALL_KEYS as $key) {
            $settings[$key] = $this->safeSetting($key, $owner->id());
        }

        return $this->privateJson([
            'settings' => $settings,
            'csrf_token' => $this->csrfTokens->getToken(self::CSRF_ID)->getValue(),
            'test_email_enabled' => self::testEmailEnabled(),
            'secret_write_enabled' => self::secretWriteEnabled(),
        ]);
    }

    #[Route('', name: 'platform_integrations_save', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF_ID);
        $payload = $this->jsonPayload($request);

        $key = $payload['key'] ?? null;
        $value = $payload['value'] ?? null;
        if (!is_string($key) || !in_array($key, self::ALL_KEYS, true)
            || !is_string($value) || count($payload) !== 2) {
            return $this->privateJson(['error' => 'Ajuste no válido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // El resolver de correo y el bridge de Sentry consumen ajustes persistidos.
        // Evitar activación indirecta en construcción: no admitir nuevos secretos
        // hasta que el dueño haya autorizado expresamente live y configuración.
        if (in_array($key, self::SECRET_KEYS, true) && !self::secretWriteEnabled()) {
            return $this->privateJson([
                'error' => 'Las claves reales permanecen bloqueadas hasta la autorización de live.',
            ], Response::HTTP_LOCKED);
        }
        if (!$this->writeLimiter->create($owner->id().':save:'.$key)->consume()->isAccepted()) {
            return $this->privateJson(['error' => 'Demasiadas solicitudes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            if (in_array($key, self::SECRET_KEYS, true)) {
                if ($key === 'sentry.dsn' && (!filter_var($value, FILTER_VALIDATE_URL)
                    || parse_url($value, PHP_URL_SCHEME) !== 'https')) {
                    return $this->privateJson(['error' => 'DSN no válido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->settings->saveSecret($key, $value, $owner->id(), true);
            } else {
                if (($key === 'mail.provider' && $value !== 'resend')
                    || ($key === 'mail.from' && (strlen($value) > 254
                        || filter_var($value, FILTER_VALIDATE_EMAIL) === false))) {
                    return $this->privateJson(['error' => 'Configuración no válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->settings->saveValue($key, $value, $owner->id(), true);
            }
        } catch (Throwable) {
            // Nunca devolver valores, excepciones SQL, ciphertext o contenido de un secreto.
            return $this->privateJson(['error' => 'No se pudo guardar el ajuste.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->privateJson(['key' => $key, 'setting' => $this->safeSetting($key, $owner->id())]);
    }

    #[Route('/secret/{key}', name: 'platform_integrations_delete_secret', methods: ['DELETE'])]
    public function deleteSecret(string $key, Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF_ID);
        if (!in_array($key, self::SECRET_KEYS, true)) {
            return $this->privateJson(['error' => 'Ajuste no válido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->writeLimiter->create($owner->id().':delete:'.$key)->consume()->isAccepted()) {
            return $this->privateJson(['error' => 'Demasiadas solicitudes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            // Borrado seguro siempre permitido para desactivar una clave previa.
            $this->settings->delete($key, $owner->id(), true);
        } catch (Throwable) {
            return $this->privateJson(['error' => 'No se pudo eliminar el secreto.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->privateJson(['key' => $key, 'setting' => $this->safeSetting($key, $owner->id())]);
    }

    #[Route('/test-email', name: 'platform_integrations_test_email', methods: ['POST'])]
    public function testEmail(Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF_ID);

        // Límite en servidor, particionado por identidad; nunca confiar en el botón.
        if (!$this->testEmailLimiter->create($owner->id().':test-email')->consume()->isAccepted()) {
            return $this->privateJson(['error' => 'Solo un correo de prueba por minuto.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!self::testEmailEnabled()) {
            return $this->privateJson([
                'error' => 'El envío real está deshabilitado hasta la autorización de live.',
            ], Response::HTTP_LOCKED);
        }
        $payload = $this->jsonPayload($request);
        $recipient = $payload['email'] ?? null;
        if (!is_string($recipient) || count($payload) !== 1 || strlen($recipient) > 254
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return $this->privateJson(['error' => 'Dirección de destino no válida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // El envío requiere los tres ajustes explícitos del propietario, no fallback
        // silencioso a un DSN externo, y jamás devuelve el token ni el DSN.
        $provider = $this->settings->get('mail.provider', $owner->id(), true);
        $sender = $this->settings->get('mail.from', $owner->id(), true);
        $secret = $this->settings->get('mail.api_key', $owner->id(), true);
        if (($provider['value'] ?? null) !== 'resend'
            || !is_string($sender['value'] ?? null)
            || ($secret['is_secret'] ?? false) !== true) {
            return $this->privateJson(['error' => 'Configura primero el correo.'], Response::HTTP_CONFLICT);
        }

        try {
            $resolved = $this->mailConfiguration->resolve();
            if ($resolved === null || $resolved['provider'] !== 'resend') {
                return $this->privateJson(['error' => 'El correo no está disponible.'], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            $this->mailerFactory->create($resolved['dsn'])->send(
                (new Email())->from($resolved['from'])->to($recipient)
                    ->subject('Prueba de correo · Condor')
                    ->text('Este es un correo de prueba solicitado desde Integraciones de Condor.'),
            );
        } catch (Throwable) {
            return $this->privateJson(['error' => 'No se pudo entregar el correo de prueba.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->privateJson(['sent' => true]);
    }

    /** @return array<string,mixed> */
    private function safeSetting(string $key, string $actor): array
    {
        $data = $this->settings->get($key, $actor, true);
        $safe = [
            'configured' => $data !== null,
            'last4' => $data['last4'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
        if (in_array($key, self::PLAIN_KEYS, true)) {
            $safe['value'] = $data['value'] ?? '';
        }

        return $safe;
    }

    private static function secretWriteEnabled(): bool
    {
        return getenv('CONDOR_PHASE') === 'live'
            && getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED') === 'true';
    }

    private static function testEmailEnabled(): bool
    {
        // Fail-closed por defecto. Construcción no habilita tráfico externo.
        return self::secretWriteEnabled()
            && getenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED') === 'true';
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
