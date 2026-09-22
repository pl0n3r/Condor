<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -200)]
final readonly class SecurityHeadersSubscriber
{
    private const PRIVATE_PATH_PREFIXES = [
        '/admin',
        '/adminpl0n3r',
        '/api/v1',
        '/support/diagnostics',
        '/activar-cuenta',
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        if (self::isPrivatePath($event->getRequest()->getPathInfo())) {
            // Nunca reutilizar información administrativa ni diagnósticos temporales.
            $headers->set('Cache-Control', 'private, no-store');
            $headers->remove('Surrogate-Control');
            $headers->remove('Expires');
        }

        $headers->set(
            'Content-Security-Policy',
            "default-src 'self'; "
            ."base-uri 'self'; "
            ."form-action 'self'; "
            ."frame-ancestors 'none'; "
            ."object-src 'none'; "
            ."img-src 'self' data:; "
            ."style-src 'self'; "
            ."script-src 'self'",
        );
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Los diagnósticos con token requieren una política todavía más estricta.
        if ($headers->get('Referrer-Policy') !== 'no-referrer') {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        );

        if ($this->environment === 'prod' && $event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }

    private static function isPrivatePath(string $path): bool
    {
        foreach (self::PRIVATE_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
