<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Assets\Entity\AssetRecord;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Offline-only tenant-scoped DBAL persistence. No controller activates it.
 * The composite tenant/asset key and every WHERE clause fail closed.
 */
final readonly class AssetRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Create only a fixture record under the caller's explicit permission.
     * @return array<string, string|null>
     */
    public function create(AssetRecord $record, string $tenantRef, string $actorRef, bool $authorized): array
    {
        $this->assertRecordAuthority($record, $tenantRef, $actorRef, $authorized);
        return $this->connection->transactional(function () use ($record, $tenantRef, $actorRef): array {
            $row = $record->snapshot();
            $this->connection->insert('condor_asset_record', $row);
            $this->audit($tenantRef, $record->assetRef(), $actorRef, 'synthetic.created');
            return $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function forTenant(string $tenantRef, string $actorRef, bool $authorized): array
    {
        AssetRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        return $this->connection->transactional(function () use ($tenantRef, $actorRef): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT tenant_ref, asset_ref, actor_ref, state, evidence_ref, warranty_until, created_at, updated_at '
                .'FROM condor_asset_record WHERE tenant_ref = ? ORDER BY asset_ref',
                [$tenantRef],
            );
            foreach ($rows as $row) {
                if (($row['tenant_ref'] ?? null) !== $tenantRef
                    || !is_string($row['asset_ref'] ?? null)) {
                    throw new DomainException('Consulta de activos fuera del ámbito autorizado.');
                }
                AssetRecord::assertAssetRef($row['asset_ref']);
            }
            $this->audit($tenantRef, 'fixture:asset/list', $actorRef, 'synthetic.listed');
            return $rows;
        });
    }

    /** @return array<string,mixed>|null */
    public function view(string $tenantRef, string $assetRef, string $actorRef, bool $authorized): ?array
    {
        AssetRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        AssetRecord::assertAssetRef($assetRef);
        return $this->connection->transactional(function () use ($tenantRef, $assetRef, $actorRef): ?array {
            $row = $this->connection->fetchAssociative(
                'SELECT tenant_ref, asset_ref, actor_ref, state, evidence_ref, warranty_until, created_at, updated_at '
                .'FROM condor_asset_record WHERE tenant_ref = ? AND asset_ref = ?',
                [$tenantRef, $assetRef],
            );
            if ($row !== false && (($row['tenant_ref'] ?? null) !== $tenantRef
                || ($row['asset_ref'] ?? null) !== $assetRef)) {
                throw new DomainException('Activo fuera de ámbito.');
            }
            $this->audit($tenantRef, $assetRef, $actorRef, 'synthetic.viewed');
            return $row === false ? null : $row;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenantRef,
        string $assetRef,
        string $actorRef,
        bool $authorized,
        string $state,
        ?string $evidenceRef = null,
    ): array {
        AssetRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        AssetRecord::assertAssetRef($assetRef);
        return $this->connection->transactional(function () use (
            $tenantRef, $assetRef, $actorRef, $authorized, $state, $evidenceRef,
        ): array {
            $row = $this->connection->fetchAssociative(
                'SELECT tenant_ref, asset_ref, actor_ref, state, evidence_ref, warranty_until, created_at, updated_at '
                .'FROM condor_asset_record WHERE tenant_ref = ? AND asset_ref = ? FOR UPDATE',
                [$tenantRef, $assetRef],
            );
            if ($row === false || ($row['tenant_ref'] ?? null) !== $tenantRef
                || ($row['asset_ref'] ?? null) !== $assetRef) {
                throw new DomainException('Activo sintético no encontrado en empresa.');
            }
            // Validate allowed, explicit transitions without ever approving or closing.
            $validation = new AssetRecord($tenantRef, $assetRef, $actorRef, $authorized);
            $validation->transition($state, $actorRef, $authorized, $evidenceRef);
            $updated = ['state' => $validation->state(), 'actor_ref' => $actorRef,
                'evidence_ref' => $evidenceRef, 'updated_at' => gmdate('Y-m-d H:i:s')];
            $changed = $this->connection->update(
                'condor_asset_record', $updated, ['tenant_ref' => $tenantRef, 'asset_ref' => $assetRef],
            );
            if ($changed !== 1) {
                throw new DomainException('Concurrencia al actualizar activo sintético.');
            }
            $this->audit($tenantRef, $assetRef, $actorRef, 'synthetic.state_changed');
            return array_replace($row, $updated);
        });
    }

    private function assertRecordAuthority(
        AssetRecord $record, string $tenantRef, string $actorRef, bool $authorized,
    ): void {
        AssetRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef || $record->actorRef() !== $actorRef) {
            throw new DomainException('Registro de activo pertenece a otra empresa o actor.');
        }
    }

    private function audit(string $tenantRef, string $assetRef, string $actorRef, string $event): void
    {
        $this->connection->insert('condor_asset_audit', [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenantRef,
            'asset_ref' => $assetRef,
            'actor_ref' => $actorRef,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
