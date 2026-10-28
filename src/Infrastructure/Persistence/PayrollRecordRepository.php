<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Payroll\Entity\PayrollRecord;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Offline-only synthetic payroll repository. No HTTP controller or scheduled
 * job instantiates it: D-063 keeps actual employee data disabled.
 */
final readonly class PayrollRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Create a review-only payroll row with immutable append-only audit.
     * Authorization is mandatory even for fixtures. Identical replay is
     * idempotent; differing replay of the same reference is rejected.
     */
    public function record(
        PayrollRecord $record,
        string $tenantRef,
        string $entityRef,
        string $actorRef,
        bool $authorized,
    ): bool {
        self::authorize($tenantRef, $entityRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef
            || $record->legalEntityRef() !== $entityRef
            || $record->actorRef() !== $actorRef) {
            throw new DomainException('Cross-tenant or cross-actor payroll write denied.');
        }
        $row = $record->snapshot();

        return $this->connection->transactional(function () use ($row, $tenantRef, $entityRef): bool {
            $existing = $this->connection->fetchAssociative(
                'SELECT fingerprint FROM condor_payroll_record '
                . 'WHERE tenant_ref = ? AND legal_entity_ref = ? AND record_ref = ? FOR UPDATE',
                [$tenantRef, $entityRef, $row['record_ref']],
            );
            if ($existing !== false) {
                if (!is_string($existing['fingerprint'] ?? null)
                    || !hash_equals($existing['fingerprint'], $row['fingerprint'])) {
                    throw new DomainException('Conflicting synthetic payroll replay.');
                }

                return false;
            }

            $this->connection->insert('condor_payroll_record', $row);
            $this->connection->insert('condor_payroll_audit', [
                'audit_ref' => hash('sha256', $tenantRef . '|' . $entityRef . '|' . $row['record_ref']),
                'tenant_ref' => $tenantRef,
                'legal_entity_ref' => $entityRef,
                'record_ref' => $row['record_ref'],
                'actor_ref' => $row['actor_ref'],
                'action' => 'CREATED_FOR_REVIEW',
                'occurred_at' => $row['created_at'],
            ]);

            return true;
        });
    }

    /**
     * A missing or invalid tenant/permission never produces an unscoped read.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(
        string $tenantRef,
        string $entityRef,
        string $actorRef,
        bool $authorized,
    ): array {
        self::authorize($tenantRef, $entityRef, $actorRef, $authorized);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT tenant_ref, legal_entity_ref, record_ref, employee_ref, '
            . 'period_ref, actor_ref, amount_hundredths, state, created_at, fingerprint '
            . 'FROM condor_payroll_record WHERE tenant_ref = ? AND legal_entity_ref = ? '
            . 'ORDER BY created_at, record_ref',
            [$tenantRef, $entityRef],
        );
        foreach ($rows as $row) {
            if (($row['tenant_ref'] ?? null) !== $tenantRef
                || ($row['legal_entity_ref'] ?? null) !== $entityRef
                || ($row['state'] ?? null) !== 'REQUIERE_REVISION'
                || !is_string($row['fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['fingerprint']) !== 1) {
                throw new DomainException('Stored payroll fixture violates review-only scope.');
            }
            PayrollRecord::fixtureRef($row['record_ref'] ?? null, 'payroll');
            PayrollRecord::fixtureRef($row['employee_ref'] ?? null, 'employee');
        }

        return $rows;
    }

    private static function authorize(
        string $tenantRef,
        string $entityRef,
        string $actorRef,
        bool $authorized,
    ): void {
        PayrollRecord::fixtureRef($tenantRef, 'tenant');
        PayrollRecord::fixtureRef($entityRef, 'entity');
        PayrollRecord::fixtureRef($actorRef, 'actor');
        if (!$authorized) {
            throw new DomainException('Payroll operation not authorized.');
        }
    }
}
