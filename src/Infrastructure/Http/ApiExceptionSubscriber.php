<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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
        $details = [];
        if ($status === Response::HTTP_UNPROCESSABLE_ENTITY) {
            $validationMessage = self::safeValidationMessage($error);
            if ($validationMessage !== null) {
                $message = $validationMessage;
                $field = null;
                if (preg_match('/^El campo ([a-z][a-z0-9_]{0,63}) no es válido\.$/u', $message, $matches) === 1) {
                    $field = $matches[1];
                } elseif (preg_match('/^El código del proveedor\\b/u', $message) === 1) {
                    $field = 'code';
                } elseif (preg_match('/^El nombre del proveedor\\b/u', $message) === 1) {
                    $field = 'name';
                } elseif (preg_match('/^El plazo de pago\\b/u', $message) === 1) {
                    $field = 'payment_terms_days';
                }
                if ($field !== null) {
                    $details['field'] = $field;
                }
            }
        }

        $event->setResponse($this->responses->create(
            $event->getRequest(),
            $code,
            $message,
            $status,
            details: $details,
            headers: self::safeHeaders($error->getHeaders()),
        ));
    }

    /**
     * Only bounded application validation messages may cross the API boundary.
     * Direct errors and matching DomainException/InvalidArgumentException wrappers are allowed.
     */
    private static function safeValidationMessage(HttpExceptionInterface $error): ?string
    {
        if (!$error instanceof UnprocessableEntityHttpException) {
            return null;
        }

        $previous = $error->getPrevious();
        if ($previous !== null
            && (!$previous instanceof \\DomainException && !$previous instanceof \\InvalidArgumentException
                || $previous->getMessage() !== $error->getMessage())
        ) {
            return null;
        }

        $raw = $error->getMessage();
        if ($raw === '' || strlen($raw) > 240 || preg_match('/[\\x00-\\x1F\\x7F]/u', $raw) === 1) {
            return null;
        }

        $message = trim($raw);
        if ($message === '' || preg_match('/^[\\p{L}\\p{N}\\s.,;:¿?¡!()_-]+$/u', $message) !== 1) {
            return null;
        }

        $internalPattern = '/\\b(?:'
            .'SQLSTATE|SQL|SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|UNION|JOIN|FROM|WHERE|TABLE|DATABASE|'
            .'PDO|DBAL|MYSQL|MARIADB|DOCTRINE|SYMFONY|ERROR|EXCEPTION|THROWABLE|WARNING|NOTICE|FATAL|STACK|TRACE|'
            .'PASSWORD|SECRET|TOKEN|AUTHORIZATION|COOKIE|CREDENTIALS?|TENANT'
            .')\\b/i';
        if (preg_match($internalPattern, $message) === 1) {
            return null;
        }

        $codeLikePattern = '/\\b[A-Z][a-z]+(?:[A-Z][A-Za-z0-9]+)+\\b'
            .'|\\b[A-Z][A-Za-z0-9_]*(?:Exception|Error|Throwable)\\b'
            .'|\\b[A-Za-z_][A-Za-z0-9_]*\\(\\)/';
        $sourceFilePattern = '/\\.(?:php|sql|env|log|ini|ya?ml)\\b/i';
        if (preg_match($codeLikePattern, $message) === 1
            || preg_match($sourceFilePattern, $message) === 1) {
            return null;
        }

        return $message;
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
