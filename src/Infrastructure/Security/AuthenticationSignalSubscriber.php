<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Registra señales funcionales agregables de autenticación (sin
 * credenciales, correos ni identificadores de usuario) para que el
 * propietario de la plataforma pueda ver actividad real de login.
 */
final readonly class AuthenticationSignalSubscriber
{
    public function __construct(
        private FunctionalSignalRecorder $signals,
        private Connection $connection,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();
        if ($user instanceof User) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            try {
                $this->connection->update(
                    'condor_user',
                    ['last_access_at' => $now->format('Y-m-d H:i:s')],
                    ['id' => $user->id()],
                );
                $user->markAccessedAt($now);
            } catch (Throwable $error) {
                error_log(sprintf(
                    'Condor last_access_at no pudo persistirse para user %s (%s).',
                    $user->id(),
                    $error::class,
                ));
            }
        }

        $this->signals->record(FunctionalSignal::LOGIN_SUCCESS);
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->signals->record(FunctionalSignal::LOGIN_FAILURE);
    }
}
