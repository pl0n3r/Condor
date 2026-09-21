<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 24)]
final readonly class TenantContextSubscriber
{
    public function __construct(
        private TenantResolver $resolver,
        private TenantContext $context,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->context->reset();

        $request = $event->getRequest();
        $tenant = $this->resolver->resolve($request);
        if (!TenantResolver::isPlatformHost($request->getHost())) {
            $route = $request->attributes->get('_route');
            if ($tenant === null || $route !== 'app_home') {
                throw new NotFoundHttpException('El sitio solicitado no está disponible.');
            }
        }
        if ($tenant !== null) {
            $this->context->set($tenant);
        }
    }
}
