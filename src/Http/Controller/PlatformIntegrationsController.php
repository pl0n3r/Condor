<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Notification\MailProviderConfiguration;
use App\Application\Platform\PlatformIntegrationSettings;
use App\Infrastructure\Notification\MailerFactory;
use App\Infrastructure\Observability\SentryTestEventSender;
use App\Infrastructure\Payments\Wompi\WompiSandboxHttpTransport;
use DomainException;
use Psr\Log\LoggerInterface;
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
    private const WOMPI_KEYS = [
        'wompi.public_key' => 'pub_test_',
        'wompi.private_key' => 'prv_test_',
        'wompi.integrity_secret' => 'test_integrity_',
        'wompi.events_secret' => 'test_events_',
    ];
    private const SECRET_KEYS = [
        'mail.api_key', 'sentry.dsn', 'wompi.public_key', 'wompi.private_key',
        'wompi.integrity_secret', 'wompi.events_secret',
    ];
    private const PLAIN_KEYS = ['mail.provider', 'mail.from'];
    private const ALL_KEYS = [
        'mail.provider', 'mail.from', 'mail.api_key', 'sentry.dsn',
        'wompi.public_key', 'wompi.private_key', 'wompi.integrity_secret', 'wompi.events_secret',
    ];

    public function __construct(
        private readonly PlatformIntegrationSettings $settings,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        #[Autowire(service: 'limiter.platform_integrations_write')]
        private readonly RateLimiterFactory $writeLimiter,
        #[Autowire(service: 'limiter.platform_integrations_test_email')]
        private readonly RateLimiterFactory $testEmailLimiter,
        private readonly MailProviderConfiguration $mailConfiguration,
        private readonly MailerFactory $mailerFactory,
        private readonly SentryTestEventSender $sentryTestSender,
        private readonly WompiSandboxHttpTransport $wompiSandbox,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $kernelEnvironment,
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
            'sentry_test_enabled' => self::sentryTestEnabled(),
            'wompi_sandbox_enabled' => WompiSandboxHttpTransport::enabled(),
            'sentry_capture_active' => $this->kernelEnvironment === 'prod'
                && $settings['sentry.dsn']['configured'],
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
        // Guardado de secretos solo tras habilitación explícita del dueño, en
        // cualquier fase. No activa envíos, proveedores ni el uso del secreto.
        if (in_array($key, self::SECRET_KEYS, true) && !self::secretWriteEnabled()) {
            return $this->privateJson([
                'error' => 'Guardar claves requiere habilitación explícita del propietario.',
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
                if (isset(self::WOMPI_KEYS[$key])) {
                    $prefix = self::WOMPI_KEYS[$key];
                    if (preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_-]{16,}\z/D', $value) !== 1) {
                        return $this->privateJson([
                            'error' => 'Solo se permiten claves Wompi de pruebas.',
                        ], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
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
                'error' => 'El envío de prueba permanece deshabilitado sin autorización explícita.',
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

    #[Route('/test-sentry', name: 'platform_integrations_test_sentry', methods: ['POST'])]
    public function testSentry(Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF_ID);

        if (!self::sentryTestEnabled()) {
            return $this->privateJson([
                'error' => 'Prueba Sentry deshabilitada por configuración.',
            ], Response::HTTP_LOCKED);
        }

        // Comprobar solo metadatos; el DSN nunca atraviesa esta API.
        if (!$this->safeSetting('sentry.dsn', $owner->id())['configured']) {
            return $this->privateJson(['error' => 'DSN de Sentry ausente.'], Response::HTTP_CONFLICT);
        }

        // Mismo límite de una prueba/minuto, partición independiente del correo.
        if (!$this->testEmailLimiter->create($owner->id().':test-sentry')->consume()->isAccepted()) {
            return $this->privateJson([
                'error' => 'Solo un evento Sentry de prueba por minuto.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $eventId = $this->sentryTestSender->send();
        } catch (\DomainException $exception) {
            $reason = $exception->getMessage();
            $status = match ($reason) {
                'dsn_invalid' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'rejected' => Response::HTTP_BAD_GATEWAY,
                default => Response::HTTP_SERVICE_UNAVAILABLE,
            };
            $this->logger->warning('condor_sentry_test_failed', [
                'reason' => in_array($reason, ['dsn_invalid', 'rejected', 'network'], true)
                    ? $reason : 'network',
            ]);

            return $this->privateJson(['error' => match ($reason) {
                'dsn_invalid' => 'DSN de Sentry inválido o no disponible.',
                'rejected' => 'Sentry rechazó el evento de prueba.',
                default => 'No hay conexión con Sentry.',
            }], $status);
        } catch (Throwable) {
            $this->logger->warning('condor_sentry_test_failed', ['reason' => 'network']);

            return $this->privateJson([
                'error' => 'No hay conexión con Sentry.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->privateJson(['sent' => true, 'event_id' => $eventId]);
    }

    #[Route('/test-wompi', name: 'platform_integrations_test_wompi', methods: ['POST'])]
    public function testWompi(Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF_ID);
        if (!WompiSandboxHttpTransport::enabled()) {
            return $this->privateJson(['error' => 'Wompi sandbox deshabilitado.'], Response::HTTP_LOCKED);
        }
        foreach (array_keys(self::WOMPI_KEYS) as $key) {
            if (!$this->safeSetting($key, $owner->id())['configured']) {
                return $this->privateJson([
                    'error' => 'Configura las cuatro claves Wompi de prueba.',
                ], Response::HTTP_CONFLICT);
            }
        }
        if (!$this->testEmailLimiter->create($owner->id().':test-wompi')->consume()->isAccepted()) {
            return $this->privateJson([
                'error' => 'Solo un pago sandbox por minuto.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $result = $this->wompiSandbox->testPayment();
        } catch (DomainException $exception) {
            $this->logger->warning('condor_wompi_sandbox_test_failed', [
                'reason' => in_array($exception->getMessage(), [
                    'WOMPI_TEST_SECRET_ONLY', 'WOMPI_SANDBOX_KEYS_UNAVAILABLE',
                    'WOMPI_SANDBOX_ACCEPTANCE_UNAVAILABLE', 'WOMPI_SANDBOX_RESPONSE_UNVERIFIED',
                ], true) ? $exception->getMessage() : 'WOMPI_SANDBOX_UNAVAILABLE',
            ]);
            return $this->privateJson([
                'error' => 'No fue posible completar el pago de prueba en Wompi sandbox.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (Throwable) {
            $this->logger->warning('condor_wompi_sandbox_test_failed', ['reason' => 'WOMPI_SANDBOX_UNAVAILABLE']);
            return $this->privateJson([
                'error' => 'No fue posible completar el pago de prueba en Wompi sandbox.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        return $this->privateJson($result);
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
        return getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED') === 'true';
    }

    private static function testEmailEnabled(): bool
    {
        // El guardado de claves nunca habilita el envío de prueba por sí solo.
        return self::secretWriteEnabled()
            && getenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED') === 'true';
    }

    private static function sentryTestEnabled(): bool
    {
        return self::secretWriteEnabled()
            && getenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED') === 'true';
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
