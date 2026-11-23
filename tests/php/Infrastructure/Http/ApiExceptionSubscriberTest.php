<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\ApiErrorResponseFactory;
use App\Infrastructure\Http\ApiExceptionSubscriber;
use App\Infrastructure\Http\RequestIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use RuntimeException;

final class ApiExceptionSubscriberTest extends TestCase
{
    public function testClientErrorCodesUseStablePublicContractsWithoutLeakingExceptionDetails(): void
    {
        foreach ([
            400 => ['bad_request', 'La solicitud no es válida.'],
            401 => ['unauthenticated', 'Debes iniciar sesión para continuar.'],
            403 => ['forbidden', 'No tienes permiso para realizar esta operación.'],
            404 => ['not_found', 'El recurso solicitado no existe.'],
            409 => ['conflict', 'La operación entra en conflicto con el estado actual.'],
            422 => ['validation_error', 'Los datos enviados no son válidos.'],
            429 => ['rate_limited', 'Se excedió el límite de solicitudes.'],
            418 => ['client_error', 'No fue posible completar la solicitud.'],
        ] as $status => [$code, $message]) {
            $event = $this->event('/api/v1/orders', new HttpException(
                $status,
                '/srv/private/config.ini stack trace SQL password=secret',
            ));
            (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($event);
            $response = $event->getResponse();
            self::assertNotNull($response, "HTTP $status must be handled");
            self::assertSame($status, $response->getStatusCode());
            self::assertSame('application/json', $response->headers->get('Content-Type'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($code, $payload['error']);
            self::assertSame($message, $payload['message']);
            self::assertSame($status, $payload['status']);
            self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $payload['request_id']);
            self::assertSame($payload['request_id'], $response->headers->get('X-Request-Id'));
            self::assertArrayNotHasKey('trace', $payload);
            self::assertArrayNotHasKey('exception', $payload);
            self::assertStringNotContainsString('/srv/private', (string) $response->getContent());
            self::assertStringNotContainsString('secret', (string) $response->getContent());
        }
    }

    public function testSafeApplicationValidationMessagesArePreservedAndExposeOnlyAnExplicitField(): void
    {
        foreach ([
            ['El campo code no es válido.', ['field' => 'code'], null],
            ['El campo name no es válido.', ['field' => 'name'], null],
            [
                'El nombre del proveedor es obligatorio (máximo 160 caracteres).',
                ['field' => 'name'],
                new \DomainException('El nombre del proveedor es obligatorio (máximo 160 caracteres).'),
            ],
            [
                'El código del proveedor debe ser alfanumérico (máximo 64 caracteres).',
                ['field' => 'code'],
                new \DomainException('El código del proveedor debe ser alfanumérico (máximo 64 caracteres).'),
            ],
            [
                'El plazo de pago debe estar entre 0 y 365 días.',
                ['field' => 'payment_terms_days'],
                new \InvalidArgumentException('El plazo de pago debe estar entre 0 y 365 días.'),
            ],
        ] as [$message, $details, $previous]) {
            $event = $this->event(
                '/api/v1/purchasing/suppliers',
                new UnprocessableEntityHttpException($message, $previous),
            );
            (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($event);
            $response = $event->getResponse();
            self::assertNotNull($response);
            self::assertSame(422, $response->getStatusCode());

            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('validation_error', $payload['error']);
            self::assertSame($message, $payload['message']);
            self::assertSame($details, $payload['details'] ?? []);
        }
    }

    public function testUnsafeOrWrappedValidationMessagesKeepTheGenericContract(): void
    {
        $exceptions = array_map(
            static fn (string $message): UnprocessableEntityHttpException =>
                new UnprocessableEntityHttpException($message),
            [
                'SQLSTATE[HY000]: SELECT * FROM users',
                'No se pudo leer /srv/app/private.php',
                'App\\Infrastructure\\Security\\SafeResponsePolicy::apply',
                'Stack trace #0 /var/www/app.php',
                'RuntimeException: password=secret',
            ],
        );
        $exceptions[] = new UnprocessableEntityHttpException(
            'El campo code no es válido.',
            new RuntimeException('private internal detail'),
        );
        $exceptions[] = new UnprocessableEntityHttpException(
            'El proveedor pertenece a otro tenant.',
            new \DomainException('El proveedor pertenece a otro tenant.'),
        );
        $exceptions[] = new UnprocessableEntityHttpException(
            'La variante no tiene precio en la lista seleccionada.',
            new \DomainException('La variante no tiene precio en la lista seleccionada.'),
        );

        foreach ($exceptions as $exception) {
            $event = $this->event('/api/v1/purchasing/suppliers', $exception);
            (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($event);
            $response = $event->getResponse();
            self::assertNotNull($response);
            self::assertSame(422, $response->getStatusCode());

            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Los datos enviados no son válidos.', $payload['message']);
            self::assertArrayNotHasKey('details', $payload);
            foreach ([
                'SQLSTATE',
                '/srv/app',
                'SafeResponsePolicy',
                'Stack trace',
                'password',
                'private internal detail',
            ] as $secret) {
                self::assertStringNotContainsString($secret, (string) $response->getContent());
            }
        }
    }

    public function testOnlyApprovedTransportHeadersArePropagated(): void
    {
        $event = $this->event('/adminpl0n3r/api/requests', new HttpException(
            429,
            'INTERNAL_PATH',
            null,
            [
                'Retry-After' => '120',
                'WWW-Authenticate' => 'Bearer realm="test"',
                'Allow' => 'GET',
                'X-Debug-Trace' => '/var/private/error.log',
                'X-Internal-Token' => 'secret',
            ],
        ));
        (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('120', $response->headers->get('Retry-After'));
        self::assertSame('Bearer realm="test"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('GET', $response->headers->get('Allow'));
        self::assertFalse($response->headers->has('X-Debug-Trace'));
        self::assertFalse($response->headers->has('X-Internal-Token'));
        self::assertStringNotContainsString('INTERNAL_PATH', (string) $response->getContent());
    }

    public function testNonApiRequestsAndSubRequestsRemainUntouched(): void
    {
        foreach (['/dashboard', '/adminpl0n3r/other', '/api'] as $path) {
            $event = $this->event($path, new HttpException(404, 'private'));
            (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($event);
            self::assertNull($event->getResponse());
        }
        $subRequest = $this->event(
            '/api/v1/orders',
            new HttpException(422, 'private'),
            HttpKernelInterface::SUB_REQUEST,
        );
        (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($subRequest);
        self::assertNull($subRequest->getResponse());
    }

    public function testUnexpectedExceptionsAndServerErrorsRemainForTheNormalExceptionPipeline(): void
    {
        foreach ([new RuntimeException('private'), new HttpException(500, '/srv/error')] as $throwable) {
            $event = $this->event('/api/v1/orders', $throwable);
            (new ApiExceptionSubscriber(new ApiErrorResponseFactory()))($event);
            self::assertNull($event->getResponse());
        }
    }

    private function event(string $path, \Throwable $exception, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        $request = Request::create($path);
        $request->attributes->set(RequestIdSubscriber::ATTRIBUTE, '01ARZ3NDEKTSV4RRFFQ69G5FAV');

        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            $type,
            $exception,
        );
    }
}
