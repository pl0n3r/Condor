<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Seo\TagManagerSettings;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Durable SEO configuration with a composite tenant/branch/channel key.
 * No visitor data, GTM scripts or IndexNow network operations are stored.
 */
final readonly class SeoSettingsRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{indexnow_enabled:bool,gtm_container_id:?string,gtm_enabled:bool} */
    public function snapshot(string $tenantId, string $branchId, string $channelId): array
    {
        self::scope($tenantId, $branchId, $channelId);
        $row = $this->connection->fetchAssociative(
            'SELECT tenant_id, branch_id, channel_id, indexnow_enabled, gtm_container_id, gtm_enabled FROM condor_seo_settings WHERE tenant_id = ? AND branch_id = ? AND channel_id = ?',
            [$tenantId, $branchId, $channelId],
        );
        if ($row === false) {
            return [
                'indexnow_enabled' => false,
                'gtm_container_id' => null,
                'gtm_enabled' => false,
            ];
        }
        if ($row['tenant_id'] !== $tenantId || $row['branch_id'] !== $branchId
            || $row['channel_id'] !== $channelId
            || !in_array((string) $row['indexnow_enabled'], ['0', '1'], true)
            || !in_array((string) $row['gtm_enabled'], ['0', '1'], true)
            || (!is_string($row['gtm_container_id']) && $row['gtm_container_id'] !== null)
        ) {
            throw new DomainException('Ajustes SEO persistidos inconsistentes.');
        }
        $gtm = $row['gtm_container_id'];
        if ($gtm !== null && TagManagerSettings::normalizeContainerId($gtm) !== $gtm) {
            throw new DomainException('Ajustes GTM persistidos inválidos.');
        }
        if ($gtm === null && (int) $row['gtm_enabled'] === 1) {
            throw new DomainException('GTM no puede estar activo sin contenedor.');
        }

        return [
            'indexnow_enabled' => (int) $row['indexnow_enabled'] === 1,
            'gtm_container_id' => $gtm,
            'gtm_enabled' => (int) $row['gtm_enabled'] === 1,
        ];
    }

    /**
     * Lock the tenant row to serialize even the first insert; no read-modify-
     * write races or cross-tenant conflict resolution by a bare branch ID.
     *
     * @param callable(array{indexnow_enabled:bool,gtm_container_id:?string,gtm_enabled:bool}):array<string, mixed> $mutation
     * @return array{indexnow_enabled:bool,gtm_container_id:?string,gtm_enabled:bool}
     */
    public function update(
        string $tenantId,
        string $branchId,
        string $channelId,
        callable $mutation,
    ): array {
        self::scope($tenantId, $branchId, $channelId);

        return $this->connection->transactional(function () use ($tenantId, $branchId, $channelId, $mutation): array {
            $locked = $this->connection->fetchOne(
                'SELECT id FROM condor_tenant WHERE id = ? FOR UPDATE',
                [$tenantId],
            );
            if ($locked === false) {
                throw new DomainException('Tenant no disponible.');
            }

            $next = $mutation($this->snapshot($tenantId, $branchId, $channelId));
            if (!is_bool($next['indexnow_enabled'] ?? null)
                || !is_bool($next['gtm_enabled'] ?? null)
                || !array_key_exists('gtm_container_id', $next)
                || (!is_string($next['gtm_container_id']) && $next['gtm_container_id'] !== null)
                || ($next['gtm_container_id'] === null && $next['gtm_enabled'])
            ) {
                throw new DomainException('Configuración SEO inconsistente.');
            }
            if ($next['gtm_container_id'] !== null
                && TagManagerSettings::normalizeContainerId($next['gtm_container_id']) !== $next['gtm_container_id']
            ) {
                throw new DomainException('Identificador GTM inconsistente.');
            }

            $this->connection->executeStatement(
                'INSERT INTO condor_seo_settings (tenant_id, branch_id, channel_id, indexnow_enabled, gtm_container_id, gtm_enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE indexnow_enabled = VALUES(indexnow_enabled), gtm_container_id = VALUES(gtm_container_id), gtm_enabled = VALUES(gtm_enabled), updated_at = VALUES(updated_at)',
                [$tenantId, $branchId, $channelId, (int) $next['indexnow_enabled'], $next['gtm_container_id'], (int) $next['gtm_enabled']],
            );

            return [
                'indexnow_enabled' => $next['indexnow_enabled'],
                'gtm_container_id' => $next['gtm_container_id'],
                'gtm_enabled' => $next['gtm_enabled'],
            ];
        });
    }

    private static function scope(string $tenantId, string $branchId, string $channelId): void
    {
        foreach ([$tenantId, $branchId] as $identifier) {
            if (strlen($identifier) < 1 || strlen($identifier) > 26
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $identifier) !== 1) {
                throw new DomainException('Identidad de tenant o sede inválida.');
            }
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $channelId) !== 1) {
            throw new DomainException('Canal SEO inválido.');
        }
    }
}
