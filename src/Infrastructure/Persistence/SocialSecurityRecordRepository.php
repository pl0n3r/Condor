<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\SocialSecurity\Entity\SocialSecurityRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/**
 * Offline M05 ledger. The caller must explicitly authorize the synthetic scope
 * for every read or append; the service exposes no public HTTP entry point.
 */
final readonly class SocialSecurityRecordRepository
{
    private const RECORDS = 'condor_social_security_record';
    private const EVENTS = 'condor_social_security_audit';

    public function __construct(private Connection $connection)
    {
    }

    public function append(
        SocialSecurityRecord $record,
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
            throw new DomainException('Seguridad social: el registro pertenece a otro ámbito o autor.');
        }

        $this->connection->transactional(function () use (
            $record, $tenantRef, $companyRef, $actorRef
        ): void {
            $previous = $this->latestVersion($tenantRef, $companyRef, $record->social_securityRef());
            self::requireNextVersion($previous, $record);
            $this->connection->insert(self::RECORDS, $record->snapshot());
            $this->appendAudit(
                $tenantRef, $companyRef, $record->social_securityRef(),
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
            $sql = 'SELECT id, tenant_ref, company_ref, social_security_ref, '
                .'category, state, version, previous_id, previous_state, '
                .'audit_action, actor_ref, occurred_at, fingerprint '
                .'FROM '.self::RECORDS.' WHERE tenant_ref = ? AND company_ref = ? '
                .'ORDER BY social_security_ref ASC, version ASC';
            $entries = $this->connection->fetchAllAssociative($sql, [$tenantRef, $companyRef]);

            foreach ($entries as $entry) {
                self::verifyStoredEntry($entry, $tenantRef, $companyRef);
            }
            $this->appendAudit(
                $tenantRef, $companyRef, 'fixture:social_security/list',
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
            .' WHERE tenant_ref = ? AND company_ref = ? AND social_security_ref = ?'
            .' ORDER BY version DESC LIMIT 1'.$suffix;

        return $this->connection->fetchAssociative($sql, [$tenant, $company, $case]);
    }

    /** @param array<string, mixed>|false $latest */
    private static function requireNextVersion(array|false $latest, SocialSecurityRecord $incoming): void
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
            throw new DomainException('Edición concurrente o evento de seguridad social obsoleto.');
        }
    }

    private static function requireAuthorization(
        string $tenant,
        string $company,
        string $actor,
        bool $allowed,
    ): void {
        SocialSecurityRecord::assertScope($tenant, $company, $actor);
        if (!$allowed) {
            throw new DomainException('Permiso de seguridad social no concedido.');
        }
    }

    /** @param array<string, mixed> $entry */
    private static function verifyStoredEntry(array $entry, string $tenant, string $company): void
    {
        if ($entry['tenant_ref'] !== $tenant || $entry['company_ref'] !== $company) {
            throw new DomainException('Se recibió un registro de otra entidad legal.');
        }
        SocialSecurityRecord::assertScope(
            $entry['tenant_ref'], $entry['company_ref'], $entry['actor_ref'],
        );
        SocialSecurityRecord::assertFixture($entry['social_security_ref'], 'social_security');
        if (!in_array($entry['state'], [SocialSecurityRecord::PENDING, SocialSecurityRecord::REVIEW], true)
            || !in_array($entry['audit_action'], ['CREADA', 'CAMBIO_EXPLICITO'], true)
            || !in_array($entry['category'], ['afiliacion', 'aporte', 'novedad', 'soporte'], true)
            || !is_string($entry['fingerprint'])
        ) {
            throw new DomainException('Evento de seguridad social con estructura inválida.');
        }
        $check = $entry;
        unset($check['fingerprint']);
        $check['version'] = (int) $check['version'];
        $digest = hash('sha256', json_encode($check, JSON_THROW_ON_ERROR));
        if (!hash_equals($entry['fingerprint'], $digest)) {
            throw new DomainException('La huella del evento de seguridad social no coincide.');
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
            'social_security_ref' => $case,
            'actor_ref' => $actor,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
