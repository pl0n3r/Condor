<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commercial\Entity\ManualCommercialDecision;
use Doctrine\DBAL\Connection;
use DomainException;

/** Additive, tenant-scoped, append-only synthetic manual terms audit. */
final readonly class ManualCommercialDecisionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{recorded:bool,fingerprint:string,effective_monthly_amount:int} */
    public function record(
        ManualCommercialDecision $decision,
        string $tenantId,
        string $actorId,
        bool $authorized,
    ): array {
        $row = $decision->snapshot();
        if (!$authorized || $tenantId !== $row['tenant_id'] || $actorId !== $row['actor_ref']) {
            throw new DomainException('Actor/tenant no autorizados para término comercial.');
        }

        return $this->connection->transactional(function () use ($row, $tenantId): array {
            $owner = $this->connection->fetchOne(
                'SELECT id FROM condor_tenant WHERE id = ? FOR UPDATE', [$tenantId],
            );
            if ($owner !== $tenantId) {
                throw new DomainException('Tenant no disponible.');
            }
            $subscription = $this->connection->fetchAssociative(
                'SELECT id, tenant_id FROM condor_commercial_subscription WHERE id = ? AND tenant_id = ? FOR UPDATE',
                [$row['subscription_id'], $tenantId],
            );
            if ($subscription === false || ($subscription['id'] ?? null) !== $row['subscription_id']
                || ($subscription['tenant_id'] ?? null) !== $tenantId) {
                throw new DomainException('Suscripción fuera de la empresa.');
            }
            $plan = $this->connection->fetchAssociative(
                'SELECT id, monthly_amount, quote_required FROM condor_commercial_plan_version WHERE id = ?',
                [$row['plan_version_id']],
            );
            if ($plan === false || ($plan['id'] ?? null) !== $row['plan_version_id']
                || (int) ($plan['quote_required'] ?? 1) !== 0
                || !is_numeric($plan['monthly_amount'] ?? null)) {
                throw new DomainException('PlanVersion no acredita un precio canónico.');
            }
            $monthly = (int) $plan['monthly_amount'];
            if ($monthly < 0 || $monthly > 1000000000) {
                throw new DomainException('Tarifa canónica no representable.');
            }
            $vertical = $this->connection->fetchOne(
                'SELECT vertical_id FROM condor_commercial_plan_version_vertical '
                .'WHERE plan_version_id = ? AND vertical_id = ?',
                [$row['plan_version_id'], $row['vertical_id']],
            );
            if ($vertical !== $row['vertical_id']) {
                throw new DomainException('Vertical no permitida por el catálogo.');
            }
            $effective = intdiv($monthly * (10000 - $row['discount_bps']), 10000);
            $identity = [...$row, 'catalog_monthly_amount' => $monthly, 'effective_monthly_amount' => $effective];
            $fingerprint = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            $existing = $this->connection->fetchAssociative(
                'SELECT fingerprint, effective_monthly_amount FROM condor_commercial_manual_decision '
                .'WHERE tenant_id = ? AND event_key = ? FOR UPDATE',
                [$tenantId, $row['event_key']],
            );
            if ($existing !== false) {
                if (!is_string($existing['fingerprint'] ?? null)
                    || !hash_equals($existing['fingerprint'], $fingerprint)
                    || (int) $existing['effective_monthly_amount'] !== $effective) {
                    throw new DomainException('Conflicto de idempotencia de decisión comercial.');
                }
                return ['recorded' => false, 'fingerprint' => $fingerprint, 'effective_monthly_amount' => $effective];
            }
            $this->connection->insert('condor_commercial_manual_decision', [
                'id' => bin2hex(random_bytes(16)),
                ...$identity,
                'fingerprint' => $fingerprint,
                'recorded_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return ['recorded' => true, 'fingerprint' => $fingerprint, 'effective_monthly_amount' => $effective];
        });
    }

    /** @return list<array<string,mixed>> */
    public function forTenant(string $tenantId, string $actorId, bool $authorized): array
    {
        if (!$authorized || preg_match('/^fixture-[a-z][a-z0-9-]{1,61}$/D', $tenantId) !== 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $actorId) !== 1) {
            throw new DomainException('Consulta de términos no autorizada.');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT tenant_id, subscription_id, plan_version_id, vertical_id, event_key, '
            .'discount_bps, starts_at, renews_at, approval_expires_at, payment_state, '
            .'effective_monthly_amount FROM condor_commercial_manual_decision '
            .'WHERE tenant_id = ? ORDER BY recorded_at, id', [$tenantId],
        );
        foreach ($rows as $row) {
            if (($row['tenant_id'] ?? null) !== $tenantId) {
                throw new DomainException('Auditoría con tenant ajeno.');
            }
        }
        return $rows;
    }
}
