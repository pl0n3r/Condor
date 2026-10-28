<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\HumanResources\Entity\EmployeeRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/**
 * Offline M01 ledger. The caller must explicitly authorize the synthetic scope
 * for every read or append; the service exposes no public HTTP entry point.
 */
final readonly class EmployeeRecordRepository
{
    private const RECORDS = 'condor_employee_record';
    private const EVENTS = 'condor_employee_audit';

    public function __construct(private Connection $connection)
    {
    }

    public function append(
        EmployeeRecord $record,
        string $tenantRef,
        string $companyRef,
        string $actorRef,
        bool $authorized,
    ): void {
        self::requireAuthorization($tenantRef, $companyRef, $actorRef, $authorized);
        $sameOwner = $record->tenantRef() === $tenantRef
            && $record->companyRef() === $companyRef
            && $record->actorRef() === $actorRef;
        if (!$sameOwner) {
            throw new DomainException('Talento humano: el registro pertenece a otro ámbito o autor.');
        }

        $this->connection->transactional(function () use (
            $record, $tenantRef, $companyRef, $actorRef
        ): void {
            $previous = $this->latestVersion($tenantRef, $companyRef, $record->employeeRef());
            self::requireNextVersion($previous, $record);
            $this->connection->insert(self::RECORDS, $record->snapshot());
            $this->appendAudit(
                $tenantRef, $companyRef, $record->employeeRef(),
                $actorRef, 'synthetic.saved',
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function forCompany(
        string $tenantRef,
        string $companyRef,
        string $actorRef,
        bool $authorized,
    ): array {
        self::requireAuthorization($tenantRef, $companyRef, $actorRef, $authorized);

        return $this->connection->transactional(function () use (
            $tenantRef, $companyRef, $actorRef
        ): array {
            $sql = 'SELECT id, tenant_ref, company_ref, employee_ref, '
                .'category, state, version, previous_id, previous_state, '
                .'audit_action, actor_ref, occurred_at, fingerprint '
                .'FROM '.self::RECORDS.' WHERE tenant_ref = ? AND company_ref = ? '
                .'ORDER BY employee_ref ASC, version ASC';
            $entries = $this->connection->fetchAllAssociative($sql, [$tenantRef, $companyRef]);

            foreach ($entries as $entry) {
                self::verifyStoredEntry($entry, $tenantRef, $companyRef);
            }
            $this->appendAudit(
                $tenantRef, $companyRef, 'fixture:employee/list',
                $actorRef, 'synthetic.listed',
            );

            return $entries;
        });
    }

    /** @return array<string, mixed>|false */
    private function latestVersion(string $tenant, string $company, string $case): array|false
    {
        $suffix = $this->connection->getDatabasePlatform() instanceof SQLitePlatform
            ? ''
            : ' FOR UPDATE';
        $sql = 'SELECT id, version, state FROM '.self::RECORDS
            .' WHERE tenant_ref = ? AND company_ref = ? AND employee_ref = ?'
            .' ORDER BY version DESC LIMIT 1'.$suffix;

        return $this->connection->fetchAssociative($sql, [$tenant, $company, $case]);
    }

    /** @param array<string, mixed>|false $latest */
    private static function requireNextVersion(array|false $latest, EmployeeRecord $incoming): void
    {
        if ($latest === false) {
            if ($incoming->version() === 1 && $incoming->previousId() === null) {
                return;
            }
            throw new DomainException('Un historial sintético debe comenzar en versión 1.');
        }

        $expected = (int) $latest['version'] + 1;
        $linked = $incoming->previousId() === $latest['id']
            && $incoming->previousState() === $latest['state'];

        if (!$linked || $incoming->version() !== $expected) {
            throw new DomainException('Edición concurrente o evento de talento humano obsoleto.');
        }
    }

    private static function requireAuthorization(
        string $tenant,
        string $company,
        string $actor,
        bool $allowed,
    ): void {
        EmployeeRecord::assertScope($tenant, $company, $actor);
        if (!$allowed) {
            throw new DomainException('Permiso de talento humano no concedido.');
        }
    }

    /** @param array<string, mixed> $entry */
    private static function verifyStoredEntry(array $entry, string $tenant, string $company): void
    {
        if ($entry['tenant_ref'] !== $tenant || $entry['company_ref'] !== $company) {
            throw new DomainException('Se recibió un registro de otra entidad legal.');
        }
        EmployeeRecord::assertScope(
            $entry['tenant_ref'], $entry['company_ref'], $entry['actor_ref'],
        );
        EmployeeRecord::assertFixture($entry['employee_ref'], 'employee');
        if (!in_array($entry['state'], [EmployeeRecord::PENDING, EmployeeRecord::REVIEW], true)
            || !in_array($entry['audit_action'], ['CREADA', 'CAMBIO_EXPLICITO'], true)
            || !in_array($entry['category'], ['vinculacion', 'vacacion', 'novedad', 'evaluacion'], true)
            || !is_string($entry['fingerprint'])
        ) {
            throw new DomainException('Evento de talento humano con estructura inválida.');
        }
        $check = $entry;
        unset($check['fingerprint']);
        $check['version'] = (int) $check['version'];
        $digest = hash('sha256', json_encode($check, JSON_THROW_ON_ERROR));
        if (!hash_equals($entry['fingerprint'], $digest)) {
            throw new DomainException('La huella del evento de talento humano no coincide.');
        }
    }

    private function appendAudit(
        string $tenant,
        string $company,
        string $case,
        string $actor,
        string $event,
    ): void {
        $this->connection->insert(self::EVENTS, [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenant,
            'company_ref' => $company,
            'employee_ref' => $case,
            'actor_ref' => $actor,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
