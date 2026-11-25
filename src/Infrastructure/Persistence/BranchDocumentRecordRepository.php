<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Documents\Entity\DocumentRecord;
use App\Shared\Id\UlidFactory;
use Doctrine\DBAL\Connection;
use DomainException;

/** Construction-only, synthetic branch scope. Never accepts a caller-chosen internal dossier ID. */
final readonly class BranchDocumentRecordRepository
{
    private DocumentRecordRepository $records;

    public function __construct(private Connection $connection)
    {
        $this->records = new DocumentRecordRepository($connection);
    }

    public function createDraft(
        string $tenantRef, string $entityRef, string $branchRef, string $externalRef,
        string $versionRef, string $classification, string $actorRef,
        string $role, bool $branchAuthorized,
    ): DocumentRecord {
        self::authorize($tenantRef, $entityRef, $branchRef, $actorRef, $role, $branchAuthorized);
        DocumentRecord::assertFixture($externalRef, 'dossier');
        DocumentRecord::assertFixture($versionRef, 'version');
        if ($role !== 'editor') {
            throw new DomainException('Only a branch editor can create a draft.');
        }

        // 128 random bits mapped bijectively to letters: valid fixture, no long digit runs.
        $internal = 'fixture:dossier/'.strtr(bin2hex(random_bytes(16)), '0123456789', 'ghijklmnop');
        $record = DocumentRecord::open($tenantRef, $entityRef, $internal,
            $versionRef, $classification, $actorRef);
        $this->connection->transactional(function () use (
            $record, $tenantRef, $entityRef, $branchRef, $externalRef, $actorRef, $role,
        ): void {
            // The legacy repository provides the sole document/version and write-audit logic.
            $this->records->append($record, $tenantRef, $entityRef, $actorRef, $role, true);
            $this->connection->insert('condor_document_branch_link', [
                'tenant_ref' => $tenantRef, 'legal_entity_ref' => $entityRef,
                'branch_ref' => $branchRef, 'external_ref' => $externalRef,
                'record_ref' => $record->recordRef(), 'initial_version' => 1,
            ]);
        });
        return $record;
    }

    /** @return list<array<string,mixed>> */
    public function forBranch(
        string $tenantRef, string $entityRef, string $branchRef,
        string $actorRef, string $role, bool $branchAuthorized,
    ): array {
        self::authorize($tenantRef, $entityRef, $branchRef, $actorRef, $role, $branchAuthorized);
        return $this->connection->transactional(function () use (
            $tenantRef, $entityRef, $branchRef, $actorRef,
        ): array {
            // SQL scope first: never invoke forCompany() or audit a sibling branch/legacy row.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT d.id, d.tenant_ref, d.legal_entity_ref, d.record_ref, d.version_ref, '
                . 'd.classification, d.state, d.version, d.previous_id, d.actor_ref, '
                . 'd.audit_action, d.occurred_at, d.fingerprint, l.external_ref '
                . 'FROM condor_document_branch_link l '
                . 'INNER JOIN condor_document_record d ON d.tenant_ref = l.tenant_ref '
                . 'AND d.legal_entity_ref = l.legal_entity_ref AND d.record_ref = l.record_ref '
                . 'WHERE l.tenant_ref = ? AND l.legal_entity_ref = ? AND l.branch_ref = ? '
                . 'ORDER BY l.external_ref, d.version',
                [$tenantRef, $entityRef, $branchRef],
            );
            foreach ($rows as $row) {
                if ($row['tenant_ref'] !== $tenantRef || $row['legal_entity_ref'] !== $entityRef
                    || !in_array($row['state'], [DocumentRecord::DRAFT, DocumentRecord::REVIEW], true)
                    || !in_array($row['classification'], ['PUBLICA', 'INTERNA', 'RESERVADA'], true)
                    || !is_string($row['fingerprint'])) {
                    throw new DomainException('Corrupt or cross-scope branch document.');
                }
                foreach (['tenant_ref' => 'tenant', 'legal_entity_ref' => 'legal-entity',
                    'record_ref' => 'dossier', 'version_ref' => 'version',
                    'actor_ref' => 'actor', 'external_ref' => 'dossier'] as $key => $kind) {
                    DocumentRecord::assertFixture($row[$key] ?? null, $kind);
                }
                $values = $row;
                unset($values['external_ref'], $values['fingerprint']);
                $values['version'] = (int) $values['version'];
                if (!hash_equals($row['fingerprint'], hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)))) {
                    throw new DomainException('Branch document fingerprint mismatch.');
                }
                $this->connection->insert('condor_document_audit', [
                    'audit_ref' => UlidFactory::new(), 'tenant_ref' => $tenantRef,
                    'legal_entity_ref' => $entityRef, 'record_ref' => $row['record_ref'],
                    'version' => (int) $row['version'], 'actor_ref' => $actorRef,
                    'action' => 'LECTURA', 'occurred_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            return $rows;
        });
    }

    private static function authorize(
        string $tenantRef, string $entityRef, string $branchRef,
        string $actorRef, string $role, bool $branchAuthorized,
    ): void {
        DocumentRecord::assertScope($tenantRef, $entityRef, $actorRef);
        if (strlen($branchRef) > 96
            || preg_match('~\Afixture:branch/[a-z][a-z0-9_-]{0,47}\z~D', $branchRef) !== 1
            || preg_match('/[0-9]{7,}/', $branchRef) === 1
            || !$branchAuthorized || !in_array($role, ['editor', 'revisor', 'auditor'], true)) {
            throw new DomainException('Branch document access denied.');
        }
    }
}
