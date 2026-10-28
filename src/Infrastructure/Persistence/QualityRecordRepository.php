<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Quality\Entity\QualityRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/** M13 fixture-only repository. Never wired into a live customer endpoint. */
final readonly class QualityRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(
        QualityRecord $record, string $tenantRef, string $companyRef, string $actorRef, bool $authorized,
    ): void {
        self::permit($tenantRef, $companyRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef || $record->companyRef() !== $companyRef
            || $record->actorRef() !== $actorRef
        ) {
            throw new DomainException('Calidad externo a la empresa autorizada.');
        }

        $this->connection->transactional(function () use ($record, $tenantRef, $companyRef, $actorRef): void {
            $previous = $this->connection->fetchAssociative(
                'SELECT id, state, version FROM condor_quality_record '
                .'WHERE tenant_ref = ? AND company_ref = ? AND quality_ref = ? '
                .'ORDER BY version DESC LIMIT 1'.$this->lockClause(),
                [$tenantRef, $companyRef, $record->qualityRef()],
            );
            if ($previous === false) {
                if ($record->version() !== 1 || $record->previousId() !== null) {
                    throw new DomainException('Falta registro inicial del calidad.');
                }
            } elseif ($record->version() !== (int) $previous['version'] + 1
                || $record->previousId() !== $previous['id']
                || $record->previousState() !== $previous['state']
            ) {
                throw new DomainException('Versión obsoleta o auditoría de calidad inválida.');
            }
            $this->connection->insert('condor_quality_record', $record->snapshot());
            $this->audit($tenantRef, $companyRef, $record->qualityRef(), $actorRef, 'synthetic.saved');
        });
    }

    /** @return list<array<string, mixed>> */
    public function forCompany(
        string $tenantRef, string $companyRef, string $actorRef, bool $authorized,
    ): array {
        self::permit($tenantRef, $companyRef, $actorRef, $authorized);

        return $this->connection->transactional(function () use ($tenantRef, $companyRef, $actorRef): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, tenant_ref, company_ref, quality_ref, category, state, version, '
                .'previous_id, previous_state, audit_action, actor_ref, occurred_at, fingerprint '
                .'FROM condor_quality_record WHERE tenant_ref = ? AND company_ref = ? '
                .'ORDER BY quality_ref, version',
                [$tenantRef, $companyRef],
            );
            foreach ($rows as $row) {
                if ($row['tenant_ref'] !== $tenantRef || $row['company_ref'] !== $companyRef
                    || !is_string($row['fingerprint'])
                    || preg_match('/\A[a-f0-9]{64}\z/D', $row['fingerprint']) !== 1
                    || !in_array($row['state'], [QualityRecord::PENDING, QualityRecord::REVIEW], true)
                    || !in_array($row['audit_action'], ['CREADA', 'CAMBIO_EXPLICITO'], true)
                ) {
                    throw new DomainException('Historial de calidad externo o corrupto.');
                }
                QualityRecord::assertScope($row['tenant_ref'], $row['company_ref'], $row['actor_ref']);
                QualityRecord::assertFixture($row['quality_ref'], 'quality');
                $values = $row;
                unset($values['fingerprint']);
                $values['version'] = (int) $values['version'];
                if (!hash_equals(
                    $row['fingerprint'], hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))
                )) {
                    throw new DomainException('La auditoría de calidad fue alterada.');
                }
            }
            $this->audit($tenantRef, $companyRef, 'fixture:quality/list', $actorRef, 'synthetic.listed');
            return $rows;
        });
    }

    private function lockClause(): string
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private static function permit(string $tenant, string $company, string $actor, bool $authorized): void
    {
        QualityRecord::assertScope($tenant, $company, $actor);
        if (!$authorized) {
            throw new DomainException('Acceso a calidads denegado.');
        }
    }

    private function audit(
        string $tenant, string $company, string $quality, string $actor, string $event,
    ): void {
        $this->connection->insert('condor_quality_audit', [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenant,
            'company_ref' => $company,
            'quality_ref' => $quality,
            'actor_ref' => $actor,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
