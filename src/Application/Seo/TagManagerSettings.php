<?php

declare(strict_types=1);

namespace App\Application\Seo;

use Closure;
use DomainException;

/**
 * Tenant/channel-scoped GTM configuration boundary (D-045).
 *
 * Persistence is injected explicitly. The caller must back the read/write
 * callbacks with a tenant-scoped durable store; this class does not create a
 * table, modify CSP headers, send requests, or wire a live Twig template.
 * A trusted public controller must validate tenant/domain resolution and
 * actual consent/CSP before passing all three render gates as true.
 */
final class TagManagerSettings
{
    /** @var Closure(string, string): mixed */
    private Closure $load;

    /** @var Closure(string, string, array<string, mixed>): void */
    private Closure $save;

    public function __construct(callable $load, callable $save)
    {
        $this->load = Closure::fromCallable($load);
        $this->save = Closure::fromCallable($save);
    }

    /**
     * Update the ID, always resetting activation to its safe default.
     *
     * @return array{tenant_id:string, channel_id:string, container_id:string, enabled:bool}
     */
    public function configure(string $tenantId, string $channelId, string $containerId): array
    {
        self::validateScope($tenantId, $channelId);
        $record = [
            'tenant_id' => $tenantId,
            'channel_id' => $channelId,
            'container_id' => self::normalizeContainerId($containerId),
            'enabled' => false,
        ];
        ($this->save)($tenantId, $channelId, $record);

        return $record;
    }

    /**
     * Explicit activation is separate from configuration and consent.
     * Consent must still be checked at render time, for each request.
     */
    public function setEnabled(string $tenantId, string $channelId, bool $enabled): void
    {
        $record = $this->snapshot($tenantId, $channelId);
        if ($record['container_id'] === null) {
            throw new DomainException('No existe configuración GTM para este tenant y canal.');
        }
        $record['enabled'] = $enabled;
        ($this->save)($tenantId, $channelId, $record);
    }

    /**
     * @return array{tenant_id:string, channel_id:string, container_id:?string, enabled:bool}
     */
    public function snapshot(string $tenantId, string $channelId): array
    {
        self::validateScope($tenantId, $channelId);
        $record = ($this->load)($tenantId, $channelId);
        if ($record === null) {
            return [
                'tenant_id' => $tenantId,
                'channel_id' => $channelId,
                'container_id' => null,
                'enabled' => false,
            ];
        }
        if (!is_array($record)
            || count($record) !== 4
            || !isset($record['tenant_id'], $record['channel_id'], $record['container_id'], $record['enabled'])
            || !is_string($record['tenant_id'])
            || !is_string($record['channel_id'])
            || !is_string($record['container_id'])
            || !is_bool($record['enabled'])
            || $record['tenant_id'] !== $tenantId
            || $record['channel_id'] !== $channelId
            || self::normalizeContainerId($record['container_id']) !== $record['container_id']
        ) {
            throw new DomainException('Configuración GTM inconsistente o de otro tenant.');
        }

        return $record;
    }

    /**
     * Inert render descriptor, intentionally NOT wired to any template.
     * Defaults deny external scripts, including when a container is enabled.
     * $publicSurface, $consentGranted and $cspExplicitlyAllowsGtm must come
     * from verified server-side public-route, consent and CSP decisions.
     */
    public function renderPublicTag(
        string $tenantId,
        string $channelId,
        bool $publicSurface = false,
        bool $consentGranted = false,
        bool $cspExplicitlyAllowsGtm = false,
    ): string {
        if (!$publicSurface || !$consentGranted || !$cspExplicitlyAllowsGtm) {
            return '';
        }
        $record = $this->snapshot($tenantId, $channelId);
        if (!$record['enabled'] || $record['container_id'] === null) {
            return '';
        }
        $containerId = htmlspecialchars($record['container_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<template data-gtm-container="'.$containerId.'"></template>';
    }

    public static function normalizeContainerId(string $containerId): string
    {
        $normalized = strtoupper(trim($containerId));
        if (strlen($normalized) > 64
            || preg_match('/\AGTM-[A-Z0-9]+\z/D', $normalized) !== 1) {
            throw new DomainException('Identificador GTM inválido.');
        }

        return $normalized;
    }

    private static function validateScope(string $tenantId, string $channelId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $tenantId) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $channelId) !== 1) {
            throw new DomainException('Tenant o canal inválido.');
        }
    }
}
