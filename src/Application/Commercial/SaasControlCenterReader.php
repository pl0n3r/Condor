<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use Closure;
use DomainException;

/**
 * Pure, read-only SaaS control-center projection. Caller owns authenticated scope.
 * Adapters must use the canonical commercial catalog and tenant-scoped ledgers;
 * this class neither loads Doctrine entities nor performs network/database I/O.
 */
final readonly class SaasControlCenterReader
{
    private Closure $catalog;
    private Closure $usage;
    private Closure $entitlements;

    public function __construct(callable $catalog, callable $usage, callable $entitlements)
    {
        $this->catalog = Closure::fromCallable($catalog);
        $this->usage = Closure::fromCallable($usage);
        $this->entitlements = Closure::fromCallable($entitlements);
    }

    /**
     * @return array<string, mixed> An allowlisted, deterministic projection.
     */
    public function read(string $actorRole, string $tenantId, string $authorizedTenantId): array
    {
        // This is a second, server-side scope guard. The future controller must
        // independently check the platform role and the selected tenant as well.
        if ($actorRole !== 'ROLE_PLATFORM_OWNER'
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/D', $tenantId) !== 1
            || !hash_equals($tenantId, $authorizedTenantId)) {
            throw new DomainException('Acceso a tenant no autorizado.');
        }

        try {
            $catalog = ($this->catalog)($tenantId);
        } catch (\Throwable) {
            // Never propagate adapter diagnostics, identifiers or credentials.
            throw new DomainException('Fuente comercial no disponible.');
        }
        if ($catalog === null) {
            return [
                'state' => 'empty', 'tenant_id' => $tenantId,
                'plan' => null, 'add_ons' => [], 'usage' => [], 'entitlements' => [],
            ];
        }
        if (!is_array($catalog) || ($catalog['tenant_id'] ?? null) !== $tenantId) {
            throw new DomainException('Catálogo comercial no disponible.');
        }
        $plan = $catalog['plan'] ?? null;
        $addons = $catalog['add_ons'] ?? null;
        try {
            $usage = ($this->usage)($tenantId);
            $entitlements = ($this->entitlements)($tenantId);
        } catch (\Throwable) {
            throw new DomainException('Fuente comercial no disponible.');
        }
        // Bound cardinality before array unpacking, copying and sorting.
        if (!is_array($plan) || !is_array($addons) || count($addons) > 128
            || !array_is_list($addons) || !is_array($usage) || count($usage) > 128
            || !array_is_list($usage) || !is_array($entitlements)
            || count($entitlements) > 128 || !array_is_list($entitlements)) {
            throw new DomainException('Proyección comercial inválida.');
        }
        foreach ([$plan, $catalog, ...$addons, ...$usage, ...$entitlements] as $row) {
            if (!is_array($row)) {
                throw new DomainException('Proyección comercial inválida.');
            }
        }
        if (($plan['tenant_id'] ?? null) !== $tenantId
            || !self::key($plan['key'] ?? null)
            || !self::key($plan['version'] ?? null)
            || !self::key($plan['currency'] ?? null)
            || !self::nonNegative($plan['price_minor'] ?? null)) {
            throw new DomainException('Plan comercial inválido.');
        }
        $seen = [];
        $safeAddons = [];
        foreach ($addons as $row) {
            if (($row['tenant_id'] ?? null) !== $tenantId
                || !self::key($row['key'] ?? null)
                || !self::nonNegative($row['price_minor'] ?? null)
                || !is_bool($row['active'] ?? null)
                || isset($seen[$row['key']])) {
                throw new DomainException('Add-on comercial inválido.');
            }
            $seen[$row['key']] = true;
            $safeAddons[] = [
                'key' => $row['key'], 'price_minor' => $row['price_minor'],
                'active' => $row['active'],
            ];
        }
        $seen = [];
        $safeUsage = [];
        foreach ($usage as $row) {
            if (($row['tenant_id'] ?? null) !== $tenantId
                || !self::key($row['metric'] ?? null)
                || !self::nonNegative($row['used'] ?? null)
                || !self::nonNegative($row['limit'] ?? null)
                || isset($seen[$row['metric']])) {
                throw new DomainException('Uso comercial inválido.');
            }
            $seen[$row['metric']] = true;
            $safeUsage[] = [
                'metric' => $row['metric'], 'used' => $row['used'],
                'limit' => $row['limit'],
            ];
        }
        $seen = [];
        $safeEntitlements = [];
        foreach ($entitlements as $row) {
            if (($row['tenant_id'] ?? null) !== $tenantId
                || !self::key($row['key'] ?? null)
                || !is_bool($row['allowed'] ?? null)
                || isset($seen[$row['key']])) {
                throw new DomainException('Entitlement comercial inválido.');
            }
            $seen[$row['key']] = true;
            $safeEntitlements[] = ['key' => $row['key'], 'allowed' => $row['allowed']];
        }
        usort($safeAddons, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        usort($safeUsage, static fn (array $a, array $b): int => strcmp($a['metric'], $b['metric']));
        usort($safeEntitlements, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        return [
            'state' => 'ready', 'tenant_id' => $tenantId,
            'plan' => [
                'key' => $plan['key'], 'version' => $plan['version'],
                'currency' => $plan['currency'], 'price_minor' => $plan['price_minor'],
            ],
            'add_ons' => $safeAddons, 'usage' => $safeUsage,
            'entitlements' => $safeEntitlements,
        ];
    }

    private static function key(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}\z/D', $value) === 1;
    }

    private static function nonNegative(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
