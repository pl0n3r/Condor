<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/** SQL is tenant-scoped even for global owner operations. */
final readonly class ModuleAccessRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, bool> */
    public function tenantOverrides(string $tenantId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT module_key, enabled FROM condor_tenant_module WHERE tenant_id = ?',
            [$tenantId],
        ) as $row) {
            $result[(string) $row['module_key']] = (bool) $row['enabled'];
        }

        return $result;
    }

    /** @return array<string, bool> */
    public function userOverrides(string $tenantId, string $userId): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT module_key, enabled FROM condor_user_module WHERE tenant_id = ? AND user_id = ?',
            [$tenantId, $userId],
        ) as $row) {
            $result[(string) $row['module_key']] = (bool) $row['enabled'];
        }

        return $result;
    }

    public function setTenant(string $tenantId, string $module, bool $enabled, string $actorId): void
    {
        $this->db->executeStatement(
            'INSERT INTO condor_tenant_module (tenant_id, module_key, enabled, updated_by, updated_at) '
            .'VALUES (?, ?, ?, ?, UTC_TIMESTAMP()) '
            .'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), '
            .'updated_at = VALUES(updated_at)',
            [$tenantId, $module, (int) $enabled, $actorId],
        );
    }

    public function setUser(
        string $tenantId,
        string $userId,
        string $module,
        bool $enabled,
        string $actorId,
    ): void {
        $this->db->executeStatement(
            'INSERT INTO condor_user_module (tenant_id, user_id, module_key, enabled, updated_by, updated_at) '
            .'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP()) '
            .'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), '
            .'updated_at = VALUES(updated_at)',
            [$tenantId, $userId, $module, (int) $enabled, $actorId],
        );
    }
}
