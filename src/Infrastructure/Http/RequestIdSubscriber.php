<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Shared\Id\UlidFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestIdSubscriber
{
    private const ATTRIBUTE = '_condor_request_id';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::ATTRIBUTE, UlidFactory::new());
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -250)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (is_string($requestId)) {
            $event->getResponse()->headers->set('X-Request-Id', $requestId);
        }
    }
}
