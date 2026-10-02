<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionChange;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

/**
 * @phpstan-type StateEvent array{
 *   kind:'state',
 *   at:DateTimeImmutable,
 *   state:SubscriptionState,
 *   initial:bool
 * }
 * @phpstan-type PlanEvent array{
 *   kind:'plan',
 *   at:DateTimeImmutable,
 *   recognition:'effective'|'scheduled',
 *   current_plan_id:string,
 *   target_plan_id:string,
 *   current_amount:int,
 *   target_amount:int,
 *   currency:string
 * }
 * @phpstan-type RevenueEvent StateEvent|PlanEvent
 * @phpstan-type TenantState array{
 *   events:list<RevenueEvent>,
 *   current_plan_id:string,
 *   current_amount:int,
 *   currency:string,
 *   state:SubscriptionState|null,
 *   ever_active:bool,
 *   scheduled_seen:bool
 * }
 * @phpstan-type Movement array{
 *   tenant_id:string,
 *   type:'new'|'expansion'|'contraction'|'churn',
 *   amount:int,
 *   mrr_delta:int,
 *   occurred_at:string,
 *   recognition:'effective'|'scheduled'
 * }
 */
final class SaasRevenueMovements
{
    /**
     * @param list<Subscription> $subscriptions
     * @param list<SubscriptionChange> $changes
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   currency:string|null,
     *   movements:list<Movement>|null
     * }
     */
    public static function derive(array $subscriptions, array $changes): array
    {
        try {
            return self::deriveCanonical($subscriptions, $changes);
        } catch (Throwable) {
            return self::unavailable('invalid_or_ambiguous_commercial_evidence');
        }
    }

    /**
     * @param list<Subscription> $subscriptions
     * @param list<SubscriptionChange> $changes
     * @return array{
     *   status:'valid',
     *   reason:null,
     *   currency:string|null,
     *   movements:list<Movement>
     * }
     */
    private static function deriveCanonical(array $subscriptions, array $changes): array
    {
        /** @var array<string,TenantState> $tenants */
        $tenants = [];
        $currency = null;

        foreach ($subscriptions as $subscription) {
            $tenantId = trim($subscription->tenantId());
            if ($tenantId === '' || isset($tenants[$tenantId])) {
                throw new DomainException('Identidad de suscripción ambigua.');
            }

            $price = self::price($subscription->planVersion());
            $currency = self::sameCurrency($currency, $price['currency']);
            $history = $subscription->history();
            if ($history === []) {
                throw new DomainException('Historial de suscripción vacío.');
            }

            /** @var list<RevenueEvent> $events */
            $events = [];
            foreach ($history as $index => $entry) {
                $state = SubscriptionState::tryFrom($entry['state']);
                if ($state === null) {
                    throw new DomainException('Estado histórico inválido.');
                }
                $events[] = [
                    'kind' => 'state',
                    'at' => SubscriptionLifecycle::parseHistoricalTime($entry['at']),
                    'state' => $state,
                    'initial' => $index === 0,
                ];
            }

            $tenants[$tenantId] = [
                'events' => $events,
                'current_plan_id' => $subscription->planVersion()->id(),
                'current_amount' => $price['amount'],
                'currency' => $price['currency'],
                'state' => null,
                'ever_active' => false,
                'scheduled_seen' => false,
            ];
        }

        foreach ($changes as $change) {
            $tenantId = trim($change->tenantId());
            if (!isset($tenants[$tenantId])) {
                throw new DomainException('Cambio sin suscripción canónica.');
            }
            if ($change->addOns() !== [] || $change->overrides() !== []) {
                throw new DomainException('Cambio con precio total no demostrable.');
            }

            $current = self::price($change->currentPlan());
            $target = self::price($change->targetPlan());
            if ($current['currency'] !== $target['currency']) {
                throw new DomainException('Cambio multi-moneda ambiguo.');
            }
            $currency = self::sameCurrency($currency, $current['currency']);

            $status = $change->status();
            if ($status === 'pending_resolution') {
                throw new DomainException('Cambio pendiente sin resolución canónica.');
            }
            if (!in_array($status, ['effective', 'scheduled'], true)) {
                throw new DomainException('Estado de cambio no soportado.');
            }

            /** @var 'effective'|'scheduled' $recognition */
            $recognition = $status;
            $eventAt = $change->effectiveAt();
            if (!$eventAt instanceof DateTimeImmutable) {
                throw new DomainException('Cambio sin fecha efectiva.');
            }

            $tenants[$tenantId]['events'][] = [
                'kind' => 'plan',
                'at' => $eventAt,
                'recognition' => $recognition,
                'current_plan_id' => $change->currentPlan()->id(),
                'target_plan_id' => $change->targetPlan()->id(),
                'current_amount' => $current['amount'],
                'target_amount' => $target['amount'],
                'currency' => $current['currency'],
            ];
        }

        /** @var list<Movement> $movements */
        $movements = [];
        foreach ($tenants as $tenantId => &$tenant) {
            usort(
                $tenant['events'],
                static fn (array $left, array $right): int =>
                    strcmp(self::instant($left['at']), self::instant($right['at'])),
            );

            $previousInstant = null;
            foreach ($tenant['events'] as $event) {
                $instant = self::instant($event['at']);
                if ($previousInstant !== null && $previousInstant === $instant) {
                    throw new DomainException('Eventos comerciales simultáneos ambiguos.');
                }
                $previousInstant = $instant;

                if ($event['kind'] === 'state') {
                    self::applyStateEvent($tenantId, $tenant, $event, $movements);
                } else {
                    self::applyPlanEvent($tenantId, $tenant, $event, $movements);
                }
            }
        }
        unset($tenant);

        usort(
            $movements,
            static fn (array $left, array $right): int =>
                [$left['occurred_at'], $left['tenant_id'], $left['type']]
                <=> [$right['occurred_at'], $right['tenant_id'], $right['type']],
        );

        return [
            'status' => 'valid',
            'reason' => null,
            'currency' => $currency,
            'movements' => $movements,
        ];
    }

    /**
     * @param TenantState $tenant
     * @param StateEvent $event
     * @param list<Movement> $movements
     */
    private static function applyStateEvent(
        string $tenantId,
        array &$tenant,
        array $event,
        array &$movements,
    ): void {
        $next = $event['state'];
        $current = $tenant['state'];

        if ($event['initial']) {
            if ($next === SubscriptionState::Active) {
                self::appendMovement(
                    $movements,
                    $tenantId,
                    'new',
                    $tenant['current_amount'],
                    $event['at'],
                    'effective',
                );
                $tenant['ever_active'] = true;
            } elseif ($next !== SubscriptionState::Trialing) {
                throw new DomainException('Estado inicial sin baseline de MRR.');
            }
            $tenant['state'] = $next;

            return;
        }

        if (!$current instanceof SubscriptionState) {
            throw new DomainException('Secuencia de lifecycle incompleta.');
        }

        if ($current === SubscriptionState::Active && $next !== SubscriptionState::Active) {
            self::appendMovement(
                $movements,
                $tenantId,
                'churn',
                $tenant['current_amount'],
                $event['at'],
                'effective',
            );
        } elseif ($current !== SubscriptionState::Active && $next === SubscriptionState::Active) {
            if ($tenant['ever_active'] || $current !== SubscriptionState::Trialing) {
                throw new DomainException('Reactivación sin categoría canónica.');
            }
            self::appendMovement(
                $movements,
                $tenantId,
                'new',
                $tenant['current_amount'],
                $event['at'],
                'effective',
            );
            $tenant['ever_active'] = true;
        }

        $tenant['state'] = $next;
    }

    /**
     * @param TenantState $tenant
     * @param PlanEvent $event
     * @param list<Movement> $movements
     */
    private static function applyPlanEvent(
        string $tenantId,
        array &$tenant,
        array $event,
        array &$movements,
    ): void {
        if (
            $event['currency'] !== $tenant['currency']
            || $event['current_plan_id'] !== $tenant['current_plan_id']
            || $event['current_amount'] !== $tenant['current_amount']
        ) {
            throw new DomainException('Cadena de cambios comerciales incoherente.');
        }

        $delta = $event['target_amount'] - $event['current_amount'];
        if ($event['recognition'] === 'scheduled') {
            if ($tenant['scheduled_seen']) {
                throw new DomainException('Más de un cambio programado sin aplicación demostrada.');
            }
            $tenant['scheduled_seen'] = true;
            if ($delta !== 0 && $tenant['state'] === SubscriptionState::Active) {
                self::appendMovement(
                    $movements,
                    $tenantId,
                    $delta > 0 ? 'expansion' : 'contraction',
                    abs($delta),
                    $event['at'],
                    'scheduled',
                );
            }

            return;
        }

        if ($delta !== 0 && $tenant['state'] === SubscriptionState::Active) {
            self::appendMovement(
                $movements,
                $tenantId,
                $delta > 0 ? 'expansion' : 'contraction',
                abs($delta),
                $event['at'],
                'effective',
            );
        }

        $tenant['current_plan_id'] = $event['target_plan_id'];
        $tenant['current_amount'] = $event['target_amount'];
        $tenant['scheduled_seen'] = false;
    }

    /** @return array{amount:int,currency:string} */
    private static function price(PlanVersion $version): array
    {
        $amount = $version->monthlyAmount();
        $currency = strtoupper(trim($version->currency()));
        if (
            $version->quoteRequired()
            || $amount === null
            || $amount < 1
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
        ) {
            throw new DomainException('MRR no demostrable para PlanVersion.');
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    private static function sameCurrency(?string $current, string $next): string
    {
        if ($current !== null && $current !== $next) {
            throw new DomainException('MRR multi-moneda no agregable.');
        }

        return $next;
    }

    /**
     * @param list<Movement> $movements
     * @param 'new'|'expansion'|'contraction'|'churn' $type
     * @param 'effective'|'scheduled' $recognition
     */
    private static function appendMovement(
        array &$movements,
        string $tenantId,
        string $type,
        int $amount,
        DateTimeImmutable $at,
        string $recognition,
    ): void {
        if ($amount < 1) {
            throw new DomainException('Movimiento MRR no positivo.');
        }
        $sign = in_array($type, ['contraction', 'churn'], true) ? -1 : 1;
        $movements[] = [
            'tenant_id' => $tenantId,
            'type' => $type,
            'amount' => $amount,
            'mrr_delta' => $sign * $amount,
            'occurred_at' => self::time($at),
            'recognition' => $recognition,
        ];
    }

    private static function instant(DateTimeImmutable $value): string
    {
        return $value->format('U.u');
    }

    private static function time(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }

    /** @return array{status:'unavailable',reason:string,currency:null,movements:null} */
    private static function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'currency' => null,
            'movements' => null,
        ];
    }
}
