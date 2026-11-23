<?php

declare(strict_types=1);

namespace App\Http\EventSubscriber;

use App\Application\Identity\CurrentTenantForUser;
use App\Application\Platform\ModuleAccess;
use App\Application\Platform\ModuleCatalog;
use App\Domain\Identity\Entity\User;
use App\Infrastructure\Http\ApiErrorResponseFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforce every registered management API on the server before its controller
 * runs. Does not replace branch/role checks inside individual controllers.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 15)]
final readonly class ModuleAccessSubscriber
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private CurrentTenantForUser $tenantForUser,
        private ModuleAccess $access,
        private ApiErrorResponseFactory $responses,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $module = ModuleCatalog::forApiPath($event->getRequest()->getPathInfo());
        if ($module === null) {
            return;
        }
        $actor = $this->tokens->getToken()?->getUser();
        if (!$actor instanceof User || !$actor->isActive()) {
            throw new AccessDeniedHttpException('Se requiere una sesión activa.');
        }
        // Deny this capability before owner overrides or support sessions.
        // Respond through the standard private API envelope, with no data leak.
        if (!ModuleCatalog::isAvailable($module)) {
            $request = $event->getRequest();
            $event->setController(fn () => $this->responses->create(
                $request,
                'module_unavailable',
                'El módulo Empleados y asistencia no está disponible porque no tiene persistencia autorizada.',
                Response::HTTP_FORBIDDEN,
            ));
            return;
        }
        try {
            $tenant = $this->tenantForUser->resolve($actor);
            if ($this->access->disabledByOwner($actor, $tenant, $module)) {
                throw new AccessDeniedHttpException('El módulo está deshabilitado para esta empresa o usuario.');
            }
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('No tienes acceso a esta empresa.', $exception);
        }
    }
}
