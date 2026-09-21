<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 32)]
final readonly class ApiExceptionSubscriber
{
    public function __construct(private ApiErrorResponseFactory $responses)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !ApiRequest::matches($event->getRequest())) {
            return;
        }

        $error = $event->getThrowable();
        if (!$error instanceof HttpExceptionInterface) {
            return;
        }

        $status = $error->getStatusCode();
        if ($status < 400 || $status >= 500) {
            return;
        }

        [$code, $message] = self::contractFor($status);
        $event->setResponse($this->responses->create(
            $event->getRequest(),
            $code,
            $message,
            $status,
            headers: self::safeHeaders($error->getHeaders()),
        ));
    }

    /** @return array{0: string, 1: string} */
    private static function contractFor(int $status): array
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => [
                'bad_request',
                'La solicitud no es válida.',
            ],
            Response::HTTP_UNAUTHORIZED => [
                'unauthenticated',
                'Debes iniciar sesión para continuar.',
            ],
            Response::HTTP_FORBIDDEN => [
                'forbidden',
                'No tienes permiso para realizar esta operación.',
            ],
            Response::HTTP_NOT_FOUND => [
                'not_found',
                'El recurso solicitado no existe.',
            ],
            Response::HTTP_CONFLICT => [
                'conflict',
                'La operación entra en conflicto con el estado actual.',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY => [
                'validation_error',
                'Los datos enviados no son válidos.',
            ],
            Response::HTTP_TOO_MANY_REQUESTS => [
                'rate_limited',
                'Se excedió el límite de solicitudes.',
            ],
            default => [
                'client_error',
                'No fue posible completar la solicitud.',
            ],
        };
    }

    /**
     * Only transport headers defined by an HttpException are propagated.
     * Symfony controls these values; application data never becomes a header.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function safeHeaders(array $headers): array
    {
        $allowed = ['Allow', 'Retry-After', 'WWW-Authenticate'];
        $safe = [];

        foreach ($allowed as $name) {
            if (isset($headers[$name]) && is_string($headers[$name])) {
                $safe[$name] = $headers[$name];
            }
        }

        return $safe;
    }
}
