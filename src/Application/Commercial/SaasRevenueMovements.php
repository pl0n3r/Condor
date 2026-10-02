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

final class SaasRevenueMovements
{
    /**
     * @param list<Subscription> $subscriptions
     * @param list<SubscriptionChange> $changes
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   currency:string|null,
     *   movements:list<array{
     *     tenant_id:string,
     *     type:'new'|'expansion'|'contraction'|'churn',
     *     amount:int,
     *     mrr_delta:int,
     *     occurred_at:string,
     *     recognition:'effective'|'scheduled'
     *   }>|null
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
     *   movements:list<array{
     *     tenant_id:string,
     *     type:'new'|'expansion'|'contraction'|'churn',
     *     amount:int,
     *     mrr_delta:int,
     *     occurred_at:string,
     *     recognition:'effective'|'scheduled'
     *   }>
     * }
     */
    private static function deriveCanonical(array $subscriptions, array $changes): array
    {
        if (!array_is_list($subscriptions) || !array_is_list($changes)) {
            throw new DomainException('Colecciones comerciales no canónicas.');
        }

        /** @var array<string,array<string,mixed>> $tenants */
        $tenants = [];
        $currency = null;

        foreach ($subscriptions as $subscription) {
            if (!$subscription instanceof Subscription) {
                throw new DomainException('Suscripción comercial inválida.');
            }

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

            $events = [];
            foreach ($history as $index => $entry) {
                $state = SubscriptionState::tryFrom($entry['state'] ?? '');
                if ($state === null) {
                    throw new DomainException('Estado histórico inválido.');
                }
                $at = SubscriptionLifecycle::parseHistoricalTime($entry['at'] ?? null);
                $events[] = [
                    'kind' => 'state',
                    'at' => $at,
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
            if (!$change instanceof SubscriptionChange) {
                throw new DomainException('Cambio comercial inválido.');
            }

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

            $recognition = null;
            $eventAt = null;
            if ($change->status() === 'effective') {
                $recognition = 'effective';
                $eventAt = $change->effectiveAt();
            } elseif ($change->status() === 'scheduled') {
                $recognition = 'scheduled';
                $eventAt = $change->effectiveAt();
            } elseif ($change->status() === 'pending_resolution') {
                if ($change->effectiveAt() !== null) {
                    throw new DomainException('Cambio bloqueado con fecha efectiva.');
                }
                continue;
            } else {
                throw new DomainException('Estado de cambio no soportado.');
            }

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

        $movements = [];
        foreach ($tenants as $tenantId => &$tenant) {
            usort(
                $tenant['events'],
                static fn (array $left, array $right): int =>
                    self::instant($left['at']) <=> self::instant($right['at']),
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
                    continue;
                }

                self::applyPlanEvent($tenantId, $tenant, $event, $movements);
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

    /** @param array<string,mixed> $tenant
     *  @param array<string,mixed> $event
     *  @param list<array<string,mixed>> $movements
     */
    private static function applyStateEvent(
        string $tenantId,
        array &$tenant,
        array $event,
        array &$movements,
    ): void {
        /** @var SubscriptionState $next */
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

    /** @param array<string,mixed> $tenant
     *  @param array<string,mixed> $event
     *  @param list<array<string,mixed>> $movements
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

        if ($event['recognition'] !== 'effective') {
            throw new DomainException('Reconocimiento comercial inválido.');
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

    /** @param list<array<string,mixed>> $movements */
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
