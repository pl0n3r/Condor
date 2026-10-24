<?php

declare(strict_types=1);

namespace App\Application\Seo;

use Closure;
use Throwable;

/**
 * Adapts published tenant URLs into idempotent, deferred IndexNow jobs.
 *
 * The caller supplies a tenant-scoped public-origin resolver (D-044) and an
 * atomic enqueue-if-absent queue implementation. Neither this class nor
 * queuePublication() performs HTTP requests. Only a worker calls deliver().
 * In CI, enqueue, resolver and transport are injected in-memory fakes.
 */
final readonly class IndexNowNotifier
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @param Closure(string):(?string) $resolvePublicOrigin
     * @param Closure(array{tenant:string,url:string,revision:string,key:string}):bool $enqueueIfAbsent
     * @param Closure(string,string,int):bool $transport
     */
    public function __construct(
        private Closure $resolvePublicOrigin,
        private Closure $enqueueIfAbsent,
        private Closure $transport,
    ) {
    }

    /**
     * Queue only public/indexable publications. The revision identifies the
     * concrete change; duplicates of that change cannot enqueue twice.
     *
     * @return ?string SHA-256 operation key, or null for a rejected event
     */
    public function queuePublication(
        string $tenantId,
        string $publicPath,
        string $revision,
        bool $published,
        bool $indexable,
        bool $noindex = false,
    ): ?string {
        if (!$published || !$indexable || $noindex || $tenantId === '' || $revision === '') {
            return null;
        }

        $origin = $this->publicOrigin($tenantId);
        if ($origin === null || !$this->isPublicPath($publicPath)) {
            return null;
        }

        $url = $origin.$publicPath;
        $key = IndexNowOperationKey::fromPublication($tenantId, $url, $revision);
        $job = ['tenant' => $tenantId, 'url' => $url, 'revision' => $revision, 'key' => $key];

        return ($this->enqueueIfAbsent)($job) ? $key : null;
    }

    /**
     * Invoke only from a background job. A bad/stale/cross-tenant job is
     * rejected before transport; a transient failure gets at most 3 attempts.
     *
     * @param array<string,mixed> $job
     */
    public function deliver(array $job): bool
    {
        $tenantId = $job['tenant'] ?? null;
        $url = $job['url'] ?? null;
        $revision = $job['revision'] ?? null;
        $key = $job['key'] ?? null;
        if (!is_string($tenantId) || !is_string($url)
            || !is_string($revision) || !is_string($key)
            || $tenantId === '' || $revision === ''
        ) {
            return false;
        }

        $origin = $this->publicOrigin($tenantId);
        if ($origin === null || !str_starts_with($url, $origin.'/')) {
            return false;
        }
        $path = substr($url, strlen($origin));
        if (!$this->isPublicPath($path)
            || !hash_equals(IndexNowOperationKey::fromPublication($tenantId, $url, $revision), $key)
        ) {
            return false;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                if (($this->transport)($tenantId, $url, $attempt) === true) {
                    return true;
                }
            } catch (Throwable) {
                // A failed attempt remains bounded; no message/body is logged.
            }
        }

        return false;
    }

    private function publicOrigin(string $tenantId): ?string
    {
        $raw = ($this->resolvePublicOrigin)($tenantId);
        if (!is_string($raw) || $raw === '' || $raw !== trim($raw)) {
            return null;
        }
        $parts = parse_url($raw);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || !is_string($parts['host'])
            || ($parts['host'] === '')
            || isset($parts['user'], $parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            return null;
        }
        // Reject credentials (including a username without password), IPs,
        // private/internal hosts and nonstandard ports for public indexing.
        if (isset($parts['user']) || isset($parts['port'])
            || filter_var($parts['host'], FILTER_VALIDATE_IP)
            || filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || !str_contains($parts['host'], '.')
            || preg_match('/(?:\.local|\.internal|\.test)$/i', $parts['host']) === 1
        ) {
            return null;
        }

        return 'https://'.strtolower($parts['host']);
    }

    private function isPublicPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')
            || strlen($path) > 2048
            || preg_match('/[\x00-\x20\x7f?#\\\\]/', $path) === 1
        ) {
            return false;
        }
        $decoded = rawurldecode($path);
        if (str_contains($decoded, '//') || str_contains($decoded, '\\')
            || str_contains($decoded, '?') || str_contains($decoded, '#')
            || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1
        ) {
            return false;
        }
        $segments = explode('/', strtolower($decoded));
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return false;
        }
        $first = $segments[1] ?? '';
        // No account, CMS preview, admin, API, or authentication paths.
        return !in_array($first, ['admin', 'api', 'interno', 'internal', 'private',
            'preview', 'borrador', 'draft', 'cuenta', 'account', 'login', 'auth',
            'perfil', 'profile', '_'], true);
    }
}
