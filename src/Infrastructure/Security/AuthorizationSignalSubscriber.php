<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Registra un rechazo de autorización (identidad válida sin permiso
 * suficiente) como señal funcional agregable, sin ruta, mensaje ni
 * datos de la solicitud original. Cubre tanto superficies API como web
 * administrativa; el 401 sin sesión no cuenta como este tipo de señal.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final readonly class AuthorizationSignalSubscriber
{
    public function __construct(private FunctionalSignalRecorder $signals)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $error = $event->getThrowable();
        if (
            !$error instanceof AccessDeniedException
            && !$error instanceof AccessDeniedHttpException
        ) {
            return;
        }

        $this->signals->record(FunctionalSignal::AUTHORIZATION_DENIED);
    }
}
