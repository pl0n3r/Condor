<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registra duración/memoria/status de cada request en RequestMetrics.
 * kernel.terminate corre después de enviar la respuesta al cliente, así
 * que nunca añade latencia percibida por el usuario.
 */
final readonly class RequestMetricsSubscriber
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        $durationMs = is_numeric($startedAt)
            ? (microtime(true) - (float) $startedAt) * 1000
            : 0.0;

        RequestMetrics::record(
            $this->projectDir,
            $request->attributes->get('_route') ?? $request->getPathInfo(),
            $event->getResponse()->getStatusCode(),
            $durationMs,
            memory_get_peak_usage(true),
        );
    }
}
