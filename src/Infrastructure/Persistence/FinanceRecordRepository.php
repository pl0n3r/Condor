<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Finance\Entity\FinanceRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/**
 * Fixture-only M07 adapter. Authorization is explicit and enforced for reads
 * and writes. Every read is scoped by both tenant and legal entity.
 */
final readonly class FinanceRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(
        FinanceRecord $record, string $tenantRef, string $legalEntityRef,
        string $actorRef, bool $authorized,
    ): void {
        self::access($tenantRef, $legalEntityRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef
            || $record->legalEntityRef() !== $legalEntityRef
            || $record->actorRef() !== $actorRef
        ) {
            throw new DomainException('El registro no pertenece al ámbito financiero autorizado.');
        }

        $this->connection->transactional(function () use ($record, $tenantRef, $legalEntityRef): void {
            $latest = $this->connection->fetchAssociative(
                'SELECT id, state, version FROM condor_finance_record '
                .'WHERE tenant_ref = ? AND legal_entity_ref = ? AND record_ref = ? '
                .'ORDER BY version DESC LIMIT 1'.$this->lockClause(),
                [$tenantRef, $legalEntityRef, $record->recordRef()],
            );

            if ($latest === false) {
                if ($record->version() !== 1 || $record->previousId() !== null) {
                    throw new DomainException('Falta el evento inicial del registro.');
                }
            } elseif ($record->version() !== (int) $latest['version'] + 1
                || $record->previousId() !== $latest['id']
                || $record->previousState() !== $latest['state']
                || $latest['state'] !== FinanceRecord::DRAFT
                || $record->state() !== FinanceRecord::REVIEW
            ) {
                throw new DomainException('Transición obsoleta o sin auditoría legítima.');
            }

            $this->connection->insert('condor_finance_record', $record->snapshot());
        });
    }

    /** @return list<array<string,mixed>> */
    public function forCompany(
        string $tenantRef, string $legalEntityRef, string $actorRef, bool $authorized,
    ): array {
        self::access($tenantRef, $legalEntityRef, $actorRef, $authorized);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_ref, legal_entity_ref, record_ref, kind, amount_decimal, '
            .'state, version, previous_id, previous_state, audit_action, actor_ref, '
            .'occurred_at, fingerprint FROM condor_finance_record '
            .'WHERE tenant_ref = ? AND legal_entity_ref = ? ORDER BY record_ref, version',
            [$tenantRef, $legalEntityRef],
        );

        foreach ($rows as $row) {
            if ($row['tenant_ref'] !== $tenantRef || $row['legal_entity_ref'] !== $legalEntityRef
                || !is_string($row['fingerprint'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $row['fingerprint']) !== 1
            ) {
                throw new DomainException('Historial financiero externo o corrupto.');
            }

            FinanceRecord::assertScope($row['tenant_ref'], $row['legal_entity_ref'], $row['actor_ref']);
            $kind = match ($row['kind']) {
                'ENTRY' => 'finance-entry',
                'ACCOUNT' => 'finance-account',
                'BANK' => 'finance-movement',
                'BUDGET' => 'finance-budget',
                'PERIOD' => 'finance-period',
                default => throw new DomainException('Tipo del historial desconocido.'),
            };
            FinanceRecord::assertFixture($row['record_ref'], $kind);
            if (!in_array($row['state'], [FinanceRecord::DRAFT, FinanceRecord::REVIEW], true)
                || !in_array($row['audit_action'], ['CREADA', 'CAMBIO_EXPLICITO'], true)
                || !is_string($row['amount_decimal'])
                || preg_match('/\A(?:0|[1-9][0-9]{0,11})\.[0-9]{2}\z/D', $row['amount_decimal']) !== 1
            ) {
                throw new DomainException('Valor o estado del historial inválido.');
            }
            $values = $row;
            unset($values['fingerprint']);
            $values['version'] = (int) $values['version'];
            if (!hash_equals(
                $row['fingerprint'], hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
            )) {
                throw new DomainException('El historial financiero ha sido alterado.');
            }
        }

        return $rows;
    }

    private function lockClause(): string
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private static function access(string $tenant, string $entity, string $actor, bool $authorized): void
    {
        FinanceRecord::assertScope($tenant, $entity, $actor);
        if (!$authorized) {
            throw new DomainException('Acceso al registro financiero denegado.');
        }
    }
}
