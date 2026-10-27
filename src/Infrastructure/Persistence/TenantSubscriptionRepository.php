<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Commercial\Entity\TenantSubscriptionRecord;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Additive, OFFLINE-ONLY usage-to-subscription index. The existing Subscription
 * and UsageObservation tables remain canonical; this is not a billing gateway.
 * No publicly reachable controller calls this repository.
 */
final readonly class TenantSubscriptionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * A platform authorization decision must be made by the caller. This
     * repository still enforces its own tenant, actor, fixture and FK boundary.
     * A duplicate event with identical content is idempotent; conflicting
     * duplicates fail closed.
     *
     * @return bool True if inserted; false for an exact replay.
     */
    public function record(
        TenantSubscriptionRecord $record,
        string $tenantId,
        string $actorId,
        bool $authorized,
    ): bool {
        self::scope($record, $tenantId, $actorId, $authorized);
        $row = $record->snapshot();

        return $this->connection->transactional(function () use ($row, $tenantId): bool {
            $subscription = $this->connection->fetchAssociative(
                'SELECT id, tenant_id FROM condor_commercial_subscription '
                .'WHERE id = ? AND tenant_id = ? FOR UPDATE',
                [$row['subscription_id'], $tenantId],
            );
            if (
                $subscription === false
                || ($subscription['id'] ?? null) !== $row['subscription_id']
                || ($subscription['tenant_id'] ?? null) !== $tenantId
            ) {
                throw new DomainException('Suscripción no pertenece a la empresa autorizada.');
            }
            $existing = $this->connection->fetchAssociative(
                'SELECT fingerprint FROM condor_commercial_tenant_subscription_usage '
                .'WHERE tenant_id = ? AND event_key = ? FOR UPDATE',
                [$tenantId, $row['event_key']],
            );
            if ($existing !== false) {
                if (!is_string($existing['fingerprint'] ?? null)
                    || !hash_equals($existing['fingerprint'], $row['fingerprint'])) {
                    throw new DomainException('Conflicto de idempotencia del evento comercial.');
                }

                return false;
            }
            $this->connection->insert(
                'condor_commercial_tenant_subscription_usage',
                $row,
            );

            return true;
        });
    }

    /**
     * Read only the caller's own synthetic usage references. An absent scope
     * never falls back to an unfiltered query.
     *
     * @return list<array<string,mixed>>
     */
    public function forSubscription(
        string $tenantId,
        string $subscriptionId,
        string $actorId,
        bool $authorized,
    ): array {
        TenantSubscriptionRecord::assertFixtureScope($tenantId, $actorId);
        if (!$authorized || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $subscriptionId) !== 1) {
            throw new DomainException('Consulta de uso no autorizada.');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT tenant_id, subscription_id, event_key, metric, quantity, '
            .'window_start, window_end, observed_at, actor_id, recorded_at, fingerprint '
            .'FROM condor_commercial_tenant_subscription_usage '
            .'WHERE tenant_id = ? AND subscription_id = ? ORDER BY recorded_at, event_key',
            [$tenantId, $subscriptionId],
        );
        foreach ($rows as $row) {
            if (($row['tenant_id'] ?? null) !== $tenantId
                || ($row['subscription_id'] ?? null) !== $subscriptionId
                || !is_string($row['fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['fingerprint']) !== 1) {
                throw new DomainException('Registro de uso persistido inválido.');
            }
        }

        return $rows;
    }

    private static function scope(
        TenantSubscriptionRecord $record,
        string $tenantId,
        string $actorId,
        bool $authorized,
    ): void {
        TenantSubscriptionRecord::assertFixtureScope($tenantId, $actorId);
        if (!$authorized || $record->tenantId() !== $tenantId || $record->actorId() !== $actorId) {
            throw new DomainException('Escritura de uso no autorizada.');
        }
    }
}
