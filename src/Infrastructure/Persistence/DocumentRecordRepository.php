<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Documents\Entity\DocumentRecord;
use App\Shared\Id\UlidFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/** Synthetic-only M08 DBAL adapter: no controller, live user or file bytes. */
final readonly class DocumentRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(
        DocumentRecord $record, string $tenantRef, string $entityRef,
        string $actorRef, string $role, bool $authorized,
    ): void {
        self::authorize($tenantRef, $entityRef, $actorRef, $role, $authorized);
        if ($record->tenantRef() !== $tenantRef || $record->legalEntityRef() !== $entityRef
            || $record->actorRef() !== $actorRef
            || ($record->version() === 1 && $role !== 'editor')
            || ($record->version() > 1 && $role !== 'revisor')) {
            throw new DomainException('Document write does not match authorized scope/role.');
        }
        $row = $record->snapshot();
        $this->connection->transactional(function () use ($record, $row, $tenantRef, $entityRef): void {
            $previous = $this->connection->fetchAssociative(
                'SELECT id, state, version, actor_ref FROM condor_document_record '
                . 'WHERE tenant_ref = ? AND legal_entity_ref = ? AND record_ref = ? '
                . 'ORDER BY version DESC LIMIT 1' . $this->lockClause(),
                [$tenantRef, $entityRef, $record->recordRef()],
            );
            if ($previous === false) {
                if ($record->version() !== 1 || $record->previousId() !== null
                    || $record->state() !== DocumentRecord::DRAFT) {
                    throw new DomainException('First document event must be an explicit draft.');
                }
            } elseif ($record->version() !== (int) $previous['version'] + 1
                || $record->previousId() !== $previous['id']
                || $previous['state'] !== DocumentRecord::DRAFT
                || $record->state() !== DocumentRecord::REVIEW
                || $record->actorRef() === $previous['actor_ref']) {
                throw new DomainException('Stale or unapproved document transition.');
            }
            $this->connection->insert('condor_document_record', $row);
            $this->connection->insert('condor_document_audit', [
                'audit_ref' => UlidFactory::new(),
                'tenant_ref' => $tenantRef, 'legal_entity_ref' => $entityRef,
                'record_ref' => $record->recordRef(), 'version' => $record->version(),
                'actor_ref' => $record->actorRef(), 'action' => $record->auditAction(),
                'occurred_at' => $row['occurred_at'],
            ]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function forCompany(
        string $tenantRef, string $entityRef, string $actorRef,
        string $role, bool $authorized,
    ): array {
        self::authorize($tenantRef, $entityRef, $actorRef, $role, $authorized);
        return $this->connection->transactional(function () use ($tenantRef, $entityRef, $actorRef): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, tenant_ref, legal_entity_ref, record_ref, version_ref, classification, '
                . 'state, version, previous_id, actor_ref, audit_action, occurred_at, fingerprint '
                . 'FROM condor_document_record WHERE tenant_ref = ? AND legal_entity_ref = ? '
                . 'ORDER BY record_ref, version',
                [$tenantRef, $entityRef],
            );
            foreach ($rows as $row) {
                if (($row['tenant_ref'] ?? null) !== $tenantRef
                    || ($row['legal_entity_ref'] ?? null) !== $entityRef
                    || !in_array($row['state'] ?? null, [DocumentRecord::DRAFT, DocumentRecord::REVIEW], true)
                    || !in_array($row['classification'] ?? null, ['PUBLICA', 'INTERNA', 'RESERVADA'], true)
                    || !is_string($row['fingerprint'] ?? null)) {
                    throw new DomainException('Corrupted or cross-company document event.');
                }
                foreach (['tenant_ref' => 'tenant', 'legal_entity_ref' => 'legal-entity',
                    'record_ref' => 'dossier', 'version_ref' => 'version', 'actor_ref' => 'actor'] as $field => $kind) {
                    DocumentRecord::assertFixture($row[$field] ?? null, $kind);
                }
                $values = $row;
                unset($values['fingerprint']);
                $values['version'] = (int) $values['version'];
                if (!hash_equals($row['fingerprint'], hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)))) {
                    throw new DomainException('Document audit fingerprint mismatch.');
                }
                $this->connection->insert('condor_document_audit', [
                    'audit_ref' => UlidFactory::new(),
                    'tenant_ref' => $tenantRef, 'legal_entity_ref' => $entityRef,
                    'record_ref' => $row['record_ref'], 'version' => (int) $row['version'],
                    'actor_ref' => $actorRef, 'action' => 'LECTURA',
                    'occurred_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            return $rows;
        });
    }

    private function lockClause(): string
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private static function authorize(
        string $tenantRef, string $entityRef, string $actorRef,
        string $role, bool $authorized,
    ): void {
        DocumentRecord::assertScope($tenantRef, $entityRef, $actorRef);
        if (!$authorized || !in_array($role, ['editor', 'revisor', 'auditor'], true)) {
            throw new DomainException('Document access denied.');
        }
    }
}
