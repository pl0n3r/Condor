<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * One policy for every publicly writable route, including future routes.
 *
 * Runs after routing (priority 32) and before Symfony firewall (priority 8).
 * It never trusts caller-provided forwarding headers, never logs identifiers,
 * and never falls back to "allow" after a cache/limiter exception.
 *
 * Existing controller-specific limits remain in place as defense in depth.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 9)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: 0)]
final readonly class PublicAbuseGuard
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH'];
    private const EMAIL_ROUTES = [
        'public_trial_request',
        'app_password_reset_request',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.public_abuse_ip')]
        private RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.public_abuse_identifier')]
        private RateLimiterFactory $identifierLimiter,
        #[Autowire(service: 'limiter.public_email_recipient')]
        private RateLimiterFactory $recipientLimiter,
        #[Autowire('%kernel.secret%')]
        private ?string $signingKey = null,
        #[Autowire('%kernel.environment%')]
        private string $environment = 'test',
    ) {
    }

    /** Called after Symfony matches the route, before controllers or authentication. */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!is_string($route)
            || !in_array($request->getMethod(), self::MUTATING_METHODS, true)
            || !self::isPublicMutationPath($request->getPathInfo())
        ) {
            return;
        }

        $ip = $request->getClientIp() ?? 'unknown';
        // A global IP window prevents an attacker from rotating public endpoints.
        $ipLimit = $this->ipLimiter->create(hash('sha256', $ip))->consume();
        // An independent identity window stops distributed requests for one victim.
        $identity = self::identifier($request);
        $identityLimit = $this->identifierLimiter
            ->create(hash('sha256', $route.':'.$identity))->consume();
        $limits = [$ipLimit, $identityLimit];

        if (in_array($route, self::EMAIL_ROUTES, true)) {
            $recipient = self::recipient($request);
            if ($recipient !== null) {
                // One hourly budget for a recipient across every email route.
                $limits[] = $this->recipientLimiter
                    ->create(hash('sha256', $recipient))->consume();
            }
        }

        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) {
                $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                $event->setResponse(new JsonResponse(
                    ['error' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) $seconds, 'Cache-Control' => 'no-store'],
                ));
                return;
            }
        }

        // Trap fields in HTML forms are always rejected before any side effects.
        // Never trust a repeated or array-valued input.
        $trap = $request->request->all()['_condor_website'] ?? '';
        if ($trap !== '' && $trap !== null) {
            $event->setResponse(self::invalidForm());
            return;
        }

        // Production HTML forms must be submitted after a short, signed dwell
        // on the GET form. Do not accept caller-supplied clocks or missing
        // proof; app-test skips dwell for legacy HTTP fixtures (strict mode is
        // exercised independently with the production setting).
        if ($this->environment !== 'test'
            && self::isHtmlPost($request)
            && !$this->validFormProof($request)
        ) {
            $event->setResponse(self::invalidForm());
        }
    }

    /** Inject signed proof and an inert honeypot in public HTML POST forms. */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getMethod() !== 'GET') {
            return;
        }
        $request = $event->getRequest();
        if (!self::isPublicMutationPath($request->getPathInfo())) {
            return;
        }
        $response = $event->getResponse();
        $body = $response->getContent();
        if (!is_string($body)
            || !str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
            || !str_contains(strtolower($body), '<form')
            || !is_string($this->signingKey) || strlen($this->signingKey) < 16
        ) {
            return;
        }

        $issued = time();
        $changed = false;
        $html = preg_replace_callback(
            '~<form\b(?=[^>]*\bmethod\s*=\s*["\x27]?post(?:["\x27\s>]))[^>]*>~i',
            function (array $match) use ($request, $issued, &$changed): string {
                $form = $match[0];
                $targetPath = $request->getPathInfo();
                if (preg_match('~\baction\s*=\s*(["\x27])([^"\x27]*)\1~i', $form, $actions) === 1) {
                    $action = parse_url(html_entity_decode($actions[2], ENT_QUOTES), PHP_URL_PATH);
                    if (is_string($action) && str_starts_with($action, '/')) {
                        $targetPath = $action;
                    }
                }
                if (!self::isPublicMutationPath($targetPath)) {
                    return $form;
                }
                $digest = hash_hmac('sha256', $issued.'|'.$targetPath, $this->signingKey ?? '');
                $proof = $issued.'.'.$digest;
                $changed = true;
                return $form
                    .'<input type="hidden" name="_condor_form_proof" value="'.$proof.'">'
                    .'<input type="text" name="_condor_website" value="" hidden'
                    .' autocomplete="off" tabindex="-1" aria-hidden="true">';
            },
            $body,
        );
        if ($changed && is_string($html)) {
            $response->setContent($html);
            $response->headers->remove('Content-Length');
            $response->headers->set('Cache-Control', 'private, no-store');
        }
    }

    private static function invalidForm(): JsonResponse
    {
        return new JsonResponse(['error' => 'Solicitud inválida.'],
            Response::HTTP_UNPROCESSABLE_ENTITY, ['Cache-Control' => 'no-store']);
    }

    private static function isHtmlPost(Request $request): bool
    {
        $type = strtolower((string) $request->headers->get('Content-Type'));
        return str_contains($type, 'application/x-www-form-urlencoded')
            || str_contains($type, 'multipart/form-data');
    }

    private function validFormProof(Request $request): bool
    {
        if (!is_string($this->signingKey) || strlen($this->signingKey) < 16) {
            return false;
        }
        $proof = $request->request->all()['_condor_form_proof'] ?? null;
        if (!is_string($proof)
            || preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/D', $proof, $parts) !== 1) {
            return false;
        }
        $issued = (int) $parts[1];
        $age = time() - $issued;
        if ($age < 2 || $age > 600) {
            return false;
        }
        $signed = hash_hmac('sha256', $issued.'|'.$request->getPathInfo(), $this->signingKey);
        return hash_equals($signed, $parts[2]);
    }

    /**
     * Paths owned by Symfony security are not public endpoints, even when a
     * request arrives without credentials. Explicit public auth endpoints
     * remain throttled (login, password reset and invitation acceptance).
     *
     * This default-deny classification for authenticated areas is independent
     * of a route-name allowlist, so a new public controller route is protected.
     */
    public static function isPublicMutationPath(string $path): bool
    {
        if (str_starts_with($path, '/api/v1/')
            || $path === '/api/v1'
            || str_starts_with($path, '/adminpl0n3r')
        ) {
            return false;
        }

        if (str_starts_with($path, '/admin')) {
            return in_array($path, [
                '/admin/login',
                '/admin/recuperar-contrasena',
                '/admin/restablecer-contrasena',
            ], true);
        }

        return !str_starts_with($path, '/_')
            && !str_starts_with($path, '/_profiler');
    }

    private static function recipient(Request $request): ?string
    {
        $value = self::formOrJson($request, 'email');
        if (!is_string($value) || strlen($value) > 254) {
            return null;
        }
        $email = strtolower(trim($value));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private static function identifier(Request $request): string
    {
        foreach (['email', 'username', 'account', 'identifier', 'phone', 'token',
            'idempotency_key', 'customer_id'] as $key) {
            $value = self::formOrJson($request, $key);
            if (is_string($value) && $value !== '' && strlen($value) <= 256) {
                return strtolower(trim($value));
            }
        }

        $token = $request->attributes->get('token');
        if (is_string($token) && $token !== '' && strlen($token) < 256) {
            return $token;
        }
        // Fallback remains bounded and rate-limited by the global IP window.
        return 'anonymous';
    }

    private static function formOrJson(Request $request, string $key): mixed
    {
        if ($request->request->has($key)) {
            return $request->request->all()[$key] ?? null;
        }
        if (!str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return null;
        }

        $raw = $request->getContent();
        if (strlen($raw) > 16384) {
            return null;
        }
        $payload = json_decode($raw, true, 8);
        return is_array($payload) ? ($payload[$key] ?? null) : null;
    }
}
