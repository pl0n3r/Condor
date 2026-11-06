<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Platform\Entity\PlatformIntegrationSetting;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Only platform-level records: no tenant_id, HTTP endpoint or secret reads.
 * Every write and delete atomically appends a value-free audit event.
 */
final readonly class PlatformIntegrationSettingRepository
{
    private const SAFE_COLUMNS = <<<'SQL'
        setting_key,
        CASE WHEN encrypted_value IS NULL THEN plain_value ELSE NULL END AS public_value,
        CASE WHEN encrypted_value IS NULL THEN 0 ELSE 1 END AS is_secret,
        secret_suffix, updated_at,
        CASE WHEN (encrypted_value IS NULL AND
            (plain_value IS NULL OR nonce IS NOT NULL OR key_version IS NOT NULL OR secret_suffix IS NOT NULL))
           OR (encrypted_value IS NOT NULL AND
            (plain_value IS NOT NULL OR nonce IS NULL OR key_version IS NULL OR secret_suffix IS NULL))
             THEN 1 ELSE 0 END AS invalid
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function put(
        string $name, ?string $plain, ?string $ciphertext, ?string $nonce,
        ?int $keyVersion, ?string $suffix, string $actor,
    ): void {
        PlatformIntegrationSetting::assertKey($name);
        if (($ciphertext === null) === ($plain === null)
            || ($ciphertext !== null && (
                $nonce === null || strlen($nonce) !== 32 || $keyVersion !== 1
                || $suffix === null || preg_match('/\A.{4}\z/usD', $suffix) !== 1
            ))
            || ($ciphertext === null && ($nonce !== null || $keyVersion !== null || $suffix !== null))
        ) {
            throw new DomainException('Campos de ajuste cifrado inconsistentes.');
        }

        $this->acquireSchemaLock();
        try {
            $this->connection->transactional(function () use (
                $name, $plain, $ciphertext, $nonce, $keyVersion, $suffix, $actor,
            ): void {
            $this->connection->executeStatement(
                'INSERT INTO condor_platform_integration_setting '
                .'(setting_key, plain_value, encrypted_value, nonce, key_version, secret_suffix, updated_at, updated_by) '
                .'VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?) '
                .'ON DUPLICATE KEY UPDATE plain_value = VALUES(plain_value), '
                .'encrypted_value = VALUES(encrypted_value), nonce = VALUES(nonce), '
                .'key_version = VALUES(key_version), secret_suffix = VALUES(secret_suffix), '
                .'updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
                [$name, $plain, $ciphertext, $nonce, $keyVersion, $suffix, $actor],
            );
            $this->audit($name, $actor, 'UPSERT');
            });
        } finally {
            $this->releaseSchemaLock();
        }
    }

    public function delete(string $name, string $actor): void
    {
        PlatformIntegrationSetting::assertKey($name);
        $this->acquireSchemaLock();
        try {
            $this->connection->transactional(function () use ($name, $actor): void {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM condor_platform_integration_setting WHERE setting_key = ?',
                [$name],
            );
            if ($deleted > 0) {
                $this->audit($name, $actor, 'DELETE');
            }
            });
        } finally {
            $this->releaseSchemaLock();
        }
    }

    /** @return array<string,mixed>|null */
    public function getSafe(string $name): ?array
    {
        PlatformIntegrationSetting::assertKey($name);
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::SAFE_COLUMNS.' FROM condor_platform_integration_setting WHERE setting_key = ?',
            [$name],
        );
        return $row === false ? null : self::redact($row);
    }

    /** @return list<array<string,mixed>> */
    public function allSafe(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::SAFE_COLUMNS.' FROM condor_platform_integration_setting ORDER BY setting_key',
        );
        return array_map(self::redact(...), $rows);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private static function redact(array $row): array
    {
        if ((int) ($row['invalid'] ?? 1) !== 0
            || !is_string($row['setting_key'] ?? null)
            || !is_string($row['updated_at'] ?? null)
            || !in_array((string) ($row['is_secret'] ?? ''), ['0', '1'], true)
        ) {
            throw new DomainException('Ajuste persistido inconsistente.');
        }

        $secret = (int) $row['is_secret'] === 1;
        if ($secret && !is_string($row['secret_suffix'])) {
            throw new DomainException('Metadatos secretos dañados.');
        }

        $result = [
            'key' => $row['setting_key'],
            'configured' => true,
            'is_secret' => $secret,
            'updated_at' => $row['updated_at'],
        ];
        if ($secret) {
            $result['last4'] = '••••'.$row['secret_suffix'];
        } else {
            $result['value'] = $row['public_value'];
        }
        return $result;
    }

    private function acquireSchemaLock(): void
    {
        if ((int) $this->connection->fetchOne("SELECT GET_LOCK('condor_platform_settings_schema', 10)") !== 1) {
            throw new DomainException('No se obtuvo exclusión para escribir ajustes de plataforma.');
        }
    }

    private function releaseSchemaLock(): void
    {
        $this->connection->fetchOne("SELECT RELEASE_LOCK('condor_platform_settings_schema')");
    }

    private function audit(string $name, string $actor, string $action): void
    {
        // Explicit safe column list: no plaintext, ciphertext or nonce.
        $this->connection->executeStatement(
            'INSERT INTO condor_platform_setting_audit '
            .'(setting_key, action, actor_id, occurred_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$name, $action, $actor],
        );
    }
}
