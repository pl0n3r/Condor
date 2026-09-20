<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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

        $tenant = $this->resolver->resolve($event->getRequest());
        if ($tenant !== null) {
            $this->context->set($tenant);
        }
    }
}
