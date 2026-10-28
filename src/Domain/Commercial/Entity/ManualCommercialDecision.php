<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use DateTimeImmutable;
use DomainException;

/** One immutable synthetic, explicitly approved manual commercial decision. */
final readonly class ManualCommercialDecision
{
    public function __construct(
        private string $tenantId,
        private string $subscriptionId,
        private string $planVersionId,
        private string $verticalId,
        private string $eventKey,
        private string $actorId,
        private string $approverId,
        private string $reason,
        private int $discountBasisPoints,
        private string $startsAt,
        private string $renewsAt,
        private string $approvalExpiresAt,
        private string $paymentState,
    ) {
        if (!self::fixture($tenantId, 26) || !self::fixture($eventKey, 72) || !self::fixture($reason, 72)) {
            throw new DomainException('Solo identificadores, eventos y motivos sintéticos.');
        }
        foreach ([$subscriptionId, $planVersionId, $verticalId, $actorId, $approverId] as $id) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
                throw new DomainException('Identificador canónico opaco inválido.');
            }
        }
        if ($actorId !== $approverId || $discountBasisPoints < 0 || $discountBasisPoints > 5000) {
            throw new DomainException('Aprobación del dueño o descuento inválidos.');
        }
        if (!in_array($paymentState, ['MANUAL_PENDING', 'MANUAL_UNVERIFIED'], true)) {
            throw new DomainException('Nunca se acredita un cobro real sin pasarela.');
        }
        if (!self::date($startsAt) || !self::date($renewsAt) || !self::date($approvalExpiresAt)
            || $startsAt > $renewsAt || $startsAt > $approvalExpiresAt) {
            throw new DomainException('Vigencia comercial incompleta o contradictoria.');
        }
    }

    /** @return array{tenant_id:string,subscription_id:string,plan_version_id:string,vertical_id:string,event_key:string,actor_ref:string,approver_ref:string,reason:string,discount_bps:int,starts_at:string,renews_at:string,approval_expires_at:string,payment_state:string} */
    public function snapshot(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'subscription_id' => $this->subscriptionId,
            'plan_version_id' => $this->planVersionId,
            'vertical_id' => $this->verticalId,
            'event_key' => $this->eventKey,
            'actor_ref' => $this->actorId,
            'approver_ref' => $this->approverId,
            'reason' => $this->reason,
            'discount_bps' => $this->discountBasisPoints,
            'starts_at' => $this->startsAt,
            'renews_at' => $this->renewsAt,
            'approval_expires_at' => $this->approvalExpiresAt,
            'payment_state' => $this->paymentState,
        ];
    }

    private static function fixture(string $s, int $maxLength): bool
    {
        return strlen($s) <= $maxLength && preg_match('/^fixture-[a-z][a-z0-9-]{1,61}$/D', $s) === 1;
    }

    private static function date(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $s) !== 1) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $s);
        return $d instanceof DateTimeImmutable && $d->format('Y-m-d\TH:i:s\Z') === $s;
    }
}
