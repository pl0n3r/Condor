<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\HumanResources\Entity\EmployeeRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/** Almacén M01 no expuesto por HTTP; toda operación exige autorización explícita. */
final readonly class EmployeeRecordRepository
{
    private const TABLE = 'condor_employee_record';
    private const JOURNAL = 'condor_employee_audit';

    public function __construct(private Connection $connection)
    {
    }

    private static function guard(
        string $tenant, string $company, string $actor, bool $authorized
    ): void {
        EmployeeRecord::assertScope($tenant, $company, $actor);
        if (!$authorized) {
            throw new DomainException('Talento humano: acceso sintético denegado.');
        }
    }

    public function append(
        EmployeeRecord $record, string $tenantRef, string $companyRef,
        string $actorRef, bool $authorized
    ): void {
        self::guard($tenantRef, $companyRef, $actorRef, $authorized);
        if ([$tenantRef, $companyRef, $actorRef] !==
            [$record->tenantRef(), $record->companyRef(), $record->actorRef()]) {
            throw new DomainException('El evento no pertenece a la entidad legal y actor autorizados.');
        }

        $this->connection->transactional(function () use ($record, $tenantRef, $companyRef, $actorRef): void {
            $lock = $this->connection->getDatabasePlatform() instanceof SQLitePlatform
                ? '' : ' FOR UPDATE';
            $sql = 'SELECT id, state, version FROM '.self::TABLE
                .' WHERE tenant_ref = ? AND company_ref = ? AND employee_ref = ?'
                .' ORDER BY version DESC LIMIT 1'.$lock;
            $latest = $this->connection->fetchAssociative(
                $sql, [$tenantRef, $companyRef, $record->employeeRef()]
            );

            if ($latest === false) {
                if ($record->version() !== 1 || $record->previousId() !== null) {
                    throw new DomainException('El historial de empleado debe comenzar por versión 1.');
                }
            } elseif (
                $record->version() !== (int) $latest['version'] + 1 ||
                $record->previousId() !== $latest['id'] ||
                $record->previousState() !== $latest['state']
            ) {
                throw new DomainException('Historial obsoleto o transición concurrente rechazada.');
            }

            $this->connection->insert(self::TABLE, $record->snapshot());
            $this->audit($tenantRef, $companyRef, $actorRef, $record->employeeRef(), 'synthetic.saved');
        });
    }

    /** @return list<array<string, mixed>> */
    public function forCompany(
        string $tenantRef, string $companyRef, string $actorRef, bool $authorized
    ): array {
        self::guard($tenantRef, $companyRef, $actorRef, $authorized);

        return $this->connection->transactional(function () use ($tenantRef, $companyRef, $actorRef): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM '.self::TABLE
                    .' WHERE tenant_ref = ? AND company_ref = ? ORDER BY employee_ref, version',
                [$tenantRef, $companyRef]
            );

            foreach ($rows as $row) {
                if ($row['tenant_ref'] !== $tenantRef ||
                    $row['company_ref'] !== $companyRef ||
                    !in_array($row['state'], [EmployeeRecord::PENDING, EmployeeRecord::REVIEW], true) ||
                    !in_array($row['category'], ['vinculacion', 'novedad', 'vacacion', 'evaluacion'], true) ||
                    !in_array($row['audit_action'], ['CREADA', 'CAMBIO_EXPLICITO'], true) ||
                    !is_string($row['fingerprint'])) {
                    throw new DomainException('La lectura M01 no verifica la entidad o estructura.');
                }
                EmployeeRecord::assertFixture($row['employee_ref'], 'employee');
                EmployeeRecord::assertScope($row['tenant_ref'], $row['company_ref'], $row['actor_ref']);
                if (!hash_equals($row['fingerprint'], EmployeeRecord::fingerprintFromRow($row))) {
                    throw new DomainException('El historial M01 fue alterado.');
                }
            }
            $this->audit($tenantRef, $companyRef, $actorRef, 'fixture:employee/list', 'synthetic.listed');
            return $rows;
        });
    }

    private function audit(
        string $tenant, string $company, string $actor, string $employee, string $event
    ): void {
        $this->connection->insert(self::JOURNAL, [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenant,
            'company_ref' => $company,
            'employee_ref' => $employee,
            'actor_ref' => $actor,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
