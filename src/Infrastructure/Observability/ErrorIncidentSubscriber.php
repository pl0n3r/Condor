<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ErrorIncidentSubscriber
{
    public function __construct(
        private ErrorIncidentRecorder $recorder,
        private string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface
            ? $error->getStatusCode()
            : 500;

        if ($status < 500) {
            return;
        }

        $incident = $this->recorder->record($event->getRequest(), $error, $status);

        if ($this->environment !== 'prod') {
            return;
        }

        $request = $event->getRequest();
        $headers = [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Condor-Error-Id' => $incident->id(),
        ];

        if (
            $request->getRequestFormat() === 'json'
            || $request->getPreferredFormat() === 'json'
        ) {
            $event->setResponse(new JsonResponse([
                'error' => 'internal_error',
                'message' => 'No pudimos completar esta solicitud.',
                'error_id' => $incident->id(),
            ], $status, $headers));

            return;
        }

        $reference = htmlspecialchars(
            $incident->id(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $body = '<!doctype html><html lang="es-CO"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex,nofollow">'
            .'<title>Condor App — error interno</title></head><body>'
            .'<main><h1>No pudimos completar esta solicitud.</h1>'
            .'<p>El error quedó registrado de forma segura.</p>'
            .'<p>Referencia: <code>'.$reference.'</code></p>'
            .'</main></body></html>';

        $response = new Response($body, $status, [
            ...$headers,
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
        $event->setResponse($response);
    }
}
