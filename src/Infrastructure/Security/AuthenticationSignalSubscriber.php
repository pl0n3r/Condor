<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Registra señales funcionales agregables de autenticación (sin
 * credenciales, correos ni identificadores de usuario) para que el
 * propietario de la plataforma pueda ver actividad real de login.
 */
final readonly class AuthenticationSignalSubscriber
{
    public function __construct(private FunctionalSignalRecorder $signals)
    {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->signals->record(FunctionalSignal::LOGIN_SUCCESS);
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->signals->record(FunctionalSignal::LOGIN_FAILURE);
    }
}
