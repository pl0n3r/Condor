<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Domain\Identity\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

final readonly class ErrorIncidentSubscriber
{
    public function __construct(
        private ErrorIncidentRecorder $recorder,
        private ErrorIncidentPresenter $presenter,
        private TokenStorageInterface $tokenStorage,
        private AuthorizationCheckerInterface $authorizationChecker,
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
        $privileged = $this->isPlatformOwner();
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
            $event->setResponse(new JsonResponse(
                $this->presenter->json($incident, $privileged),
                $status,
                $headers,
            ));

            return;
        }

        $event->setResponse(new Response(
            $this->presenter->html($incident, $privileged),
            $status,
            [
                ...$headers,
                'Content-Type' => 'text/html; charset=UTF-8',
            ],
        ));
    }

    private function isPlatformOwner(): bool
    {
        try {
            $user = $this->tokenStorage->getToken()?->getUser();

            return $user instanceof User
                && $user->isActive()
                && $this->authorizationChecker->isGranted(
                    User::ROLE_PLATFORM_OWNER,
                );
        } catch (Throwable) {
            return false;
        }
    }
}
