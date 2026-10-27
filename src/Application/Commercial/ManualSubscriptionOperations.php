<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\SubscriptionChangeRecord;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Synthetic-only manual adjustment facade over the canonical Commercial domain.
 * A tenant row lock and the existing audited change records supply durable
 * idempotency. No price ledger, shadow subscription or billing gateway is made.
 */
final readonly class ManualSubscriptionOperations
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformCommercialAdjustmentManager $adjustments,
    ) {
    }

    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function adjust(string $tenantId, string $ownerId, array $command): array
    {
        if (!self::fixture($tenantId, 72) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $ownerId) !== 1) {
            throw new DomainException('Operación limitada a una empresa sintética y dueño autorizado.');
        }
        $fields = array_keys($command);
        sort($fields);
        if ($fields !== ['add_on_ids', 'event_key', 'reason', 'renews_at', 'target_plan_version_id']) {
            throw new DomainException('Payload comercial incompleto, ambiguo o no autorizado.');
        }
        $eventKey = $command['event_key'];
        $reason = $command['reason'];
        $planVersion = $command['target_plan_version_id'];
        $renewal = $command['renews_at'];
        $ids = $command['add_on_ids'];
        if (!self::fixture($eventKey, 64)
            || !self::fixture($reason, 64)
            || !is_string($planVersion)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $planVersion) !== 1
            || !is_array($ids)
            || !array_is_list($ids)
            || count($ids) > 30
            || ($renewal !== null && (!is_string($renewal) || !self::utc($renewal)))) {
            throw new DomainException('Solo referencias sintéticas y plan/version canónicos.');
        }
        $selected = [];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1 || isset($selected[$id])) {
                throw new DomainException('Add-on canónico inválido o duplicado.');
            }
            $selected[$id] = true;
        }
        $ids = array_keys($selected);
        sort($ids);
        $fingerprint = hash('sha256', json_encode([
            'tenant' => $tenantId, 'actor' => $ownerId, 'event' => $eventKey,
            'reason' => $reason, 'plan_version' => $planVersion,
            'add_ons' => $ids, 'renewal' => $renewal,
        ], JSON_THROW_ON_ERROR));
        $auditReason = $reason.'|'.$eventKey.'|sha256:'.$fingerprint;
        $connection = $this->entityManager->getConnection();

        return $connection->transactional(function () use (
            $connection, $tenantId, $ownerId, $eventKey,
            $auditReason, $planVersion, $ids, $renewal,
        ): array {
            // Serialize all manual adjustments per tenant, including replays.
            $tenant = $connection->fetchOne('SELECT id FROM condor_tenant WHERE id = ? FOR UPDATE', [$tenantId]);
            if ($tenant !== $tenantId) {
                throw new DomainException('Empresa sintética inexistente.');
            }
            $changes = $this->entityManager->getRepository(SubscriptionChangeRecord::class)
                ->findBy(['tenantId' => $tenantId]);
            foreach ($changes as $change) {
                if ($change->tenantId() !== $tenantId) {
                    throw new DomainException('Auditoría comercial incompatible con la empresa.');
                }
                $audit = $change->audit();
                $stored = $audit['reason'] ?? '';
                if (!str_contains($stored, '|'.$eventKey.'|sha256:')) {
                    continue;
                }
                if ($stored !== $auditReason || ($audit['requested_by'] ?? null) !== $ownerId) {
                    throw new DomainException('Conflicto de idempotencia del evento.');
                }
                return [
                    'status' => 'replayed', 'tenant_ref' => $tenantId,
                    'audit_ref' => $change->id(), 'event_key' => $eventKey,
                ];
            }

            $changed = $this->adjustments->apply(
                $tenantId, $planVersion, $ids, $renewal, $auditReason, $ownerId,
            );
            if (($changed['audit']['reason'] ?? null) !== $auditReason
                || ($changed['audit']['requested_by'] ?? null) !== $ownerId
                || !isset($changed['id'])) {
                throw new DomainException('El gestor comercial no confirmó la auditoría.');
            }
            return [
                'status' => 'recorded', 'tenant_ref' => $tenantId,
                'audit_ref' => $changed['id'], 'event_key' => $eventKey,
                'commercial' => $changed,
            ];
        });
    }

    private static function fixture(mixed $id, int $limit): bool
    {
        return is_string($id)
            && strlen($id) <= $limit
            && preg_match('/^fixture-[a-z][a-z0-9-]{1,61}$/D', $id) === 1;
    }

    private static function utc(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            return false;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $time instanceof \DateTimeImmutable && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }
}
