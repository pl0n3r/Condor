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
    private const CUSTOM_DOMAIN_ROUTES = [
        'app_home',
        'app_tenant_public',
    ];

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
            if (
                $tenant === null
                || !is_string($route)
                || !in_array($route, self::CUSTOM_DOMAIN_ROUTES, true)
            ) {
                throw new NotFoundHttpException('El sitio solicitado no está disponible.');
            }
        }
        if ($tenant !== null) {
            $this->context->set($tenant);
        }
    }
}
