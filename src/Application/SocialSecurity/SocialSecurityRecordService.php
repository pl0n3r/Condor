<?php

declare(strict_types=1);

namespace App\Application\SocialSecurity;

use App\Domain\SocialSecurity\Entity\SocialSecurityRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/** Append-only, tenant/company-scoped social-security record store. */
final readonly class SocialSecurityRecordService
{
    private const TABLE = 'condor_social_security_real_record';
    public function __construct(private Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listRecords(string $tenant, string $company): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.* FROM '.self::TABLE.' r WHERE r.tenant_id = ? AND r.company_id = ?'
            .' AND r.version = (SELECT MAX(v.version) FROM '.self::TABLE.' v'
            .' WHERE v.tenant_id = r.tenant_id AND v.company_id = r.company_id'
            .' AND v.record_ref = r.record_ref) ORDER BY r.record_ref LIMIT 100',
            [$tenant, $company],
        );
        return array_map(
            static fn (array $r): array => SocialSecurityRecord::fromRealRow($r, $tenant, $company),
            $rows,
        );
    }

    /** @return array<string,mixed>|null */
    public function detail(string $tenant, string $company, string $ref): ?array
    {
        self::ref($ref);
        $row = $this->db->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = ? AND company_id = ? AND record_ref = ?'
            .' ORDER BY version DESC LIMIT 1', [$tenant, $company, $ref],
        );
        return $row === false ? null : SocialSecurityRecord::fromRealRow($row, $tenant, $company);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(string $tenant, string $company, string $actor, array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['category', 'record_ref']
            || !is_string($payload['category']) || !is_string($payload['record_ref'])) {
            throw new DomainException('Campos de seguridad social inválidos.');
        }
        $row = SocialSecurityRecord::openReal(
            $tenant, $company, $payload['record_ref'], $payload['category'], $actor,
        );
        return $this->atomic(function () use ($tenant, $company, $row): array {
            if ($this->detail($tenant, $company, $row['record_ref']) !== null) {
                throw new DomainException('Referencia social duplicada.');
            }
            $this->db->insert(self::TABLE, $row);
            return $row;
        });
    }

    /** @return array<string,mixed> */
    public function changeState(
        string $tenant, string $company, string $actor, string $ref,
        string $next, int $expectedVersion,
    ): array {
        self::ref($ref);
        return $this->atomic(function () use ($tenant, $company, $actor, $ref, $next, $expectedVersion): array {
            $old = $this->detail($tenant, $company, $ref);
            if ($old === null) {
                throw new DomainException('Registro social no encontrado.');
            }
            $row = SocialSecurityRecord::transitionReal(
                $old, $tenant, $company, $actor, $next, $expectedVersion,
            );
            $this->db->insert(self::TABLE, $row);
            return $row;
        });
    }

    /** @param \Closure():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function atomic(\Closure $operation): array
    {
        try {
            return $this->db->transactional($operation);
        } catch (UniqueConstraintViolationException $error) {
            throw new DomainException('Versión concurrente social rechazada.', 0, $error);
        }
    }

    private static function ref(string $ref): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) !== 1) {
            throw new DomainException('Referencia social inválida.');
        }
    }
}
