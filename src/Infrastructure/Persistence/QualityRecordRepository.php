<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Quality\Entity\QualityRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/** DBAL boundary for M13 synthetic quality records, not wired to customer traffic. */
final readonly class QualityRecordRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function append(
        QualityRecord $record, string $tenantRef, string $companyRef,
        string $actorRef, bool $authorized,
    ): void {
        self::checkAccess($tenantRef, $companyRef, $actorRef, $authorized);
        if ([$record->tenantRef(), $record->companyRef(), $record->actorRef()]
            !== [$tenantRef, $companyRef, $actorRef]
        ) {
            throw new DomainException('No se admite una escritura fuera de la empresa o el actor.');
        }

        $this->db->transactional(function () use ($record, $tenantRef, $companyRef, $actorRef): void {
            $history = $this->db->fetchAssociative(
                'SELECT id, state, version FROM condor_quality_record '
                .'WHERE tenant_ref = ? AND company_ref = ? AND quality_ref = ? '
                .'ORDER BY version DESC LIMIT 1'.$this->lockClause(),
                [$tenantRef, $companyRef, $record->qualityRef()],
            );
            $newCase = $history === false;
            if ($newCase && ($record->version() !== 1 || $record->previousId() !== null)) {
                throw new DomainException('Debe existir la apertura inicial de Calidad.');
            }
            if (!$newCase && (
                $record->version() !== (int) $history['version'] + 1
                || $record->previousId() !== $history['id']
                || $record->previousState() !== $history['state']
            )) {
                throw new DomainException('Historial obsoleto o inconsistente.');
            }

            $this->db->insert('condor_quality_record', $record->snapshot());
            $this->journal($tenantRef, $companyRef, $record->qualityRef(), $actorRef, 'synthetic.saved');
        });
    }

    /** @return list<array<string, mixed>> */
    public function forCompany(
        string $tenantRef, string $companyRef, string $actorRef, bool $authorized,
    ): array {
        self::checkAccess($tenantRef, $companyRef, $actorRef, $authorized);

        return $this->db->transactional(function () use ($tenantRef, $companyRef, $actorRef): array {
            $entries = $this->db->fetchAllAssociative(
                'SELECT id, tenant_ref, company_ref, quality_ref, category, state, version, '
                .'previous_id, previous_state, audit_action, actor_ref, occurred_at, fingerprint '
                .'FROM condor_quality_record WHERE tenant_ref = ? AND company_ref = ? '
                .'ORDER BY quality_ref, version',
                [$tenantRef, $companyRef],
            );
            foreach ($entries as $entry) {
                if ($entry['tenant_ref'] !== $tenantRef || $entry['company_ref'] !== $companyRef
                    || !is_string($entry['fingerprint'])
                    || !in_array($entry['state'], [QualityRecord::PENDING, QualityRecord::REVIEW], true)
                    || !in_array($entry['audit_action'], ['CREADA', 'CAMBIO_EXPLICITO'], true)
                ) {
                    throw new DomainException('Lectura de Calidad no íntegra o ajena al tenant.');
                }
                QualityRecord::assertScope($entry['tenant_ref'], $entry['company_ref'], $entry['actor_ref']);
                QualityRecord::assertFixture($entry['quality_ref'], 'quality');
                $payload = $entry;
                unset($payload['fingerprint']);
                $payload['version'] = (int) $payload['version'];
                $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                if (!hash_equals($entry['fingerprint'], $digest)) {
                    throw new DomainException('El histórico de Calidad fue alterado.');
                }
            }
            $this->journal($tenantRef, $companyRef, 'fixture:quality/list', $actorRef, 'synthetic.listed');
            return $entries;
        });
    }

    private function lockClause(): string
    {
        return $this->db->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private static function checkAccess(
        string $tenantRef, string $companyRef, string $actorRef, bool $authorized,
    ): void {
        QualityRecord::assertScope($tenantRef, $companyRef, $actorRef);
        if ($authorized !== true) {
            throw new DomainException('Acceso al módulo Calidad denegado.');
        }
    }

    private function journal(
        string $tenantRef, string $companyRef, string $caseRef, string $actorRef, string $event,
    ): void {
        $this->db->insert('condor_quality_audit', [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenantRef,
            'company_ref' => $companyRef,
            'quality_ref' => $caseRef,
            'actor_ref' => $actorRef,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
