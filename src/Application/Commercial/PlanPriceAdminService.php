<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Tarifas administrativas: únicamente crea versiones comerciales auditadas.
 * No ejecuta cargos, facturas, despliegues ni modifica contratos.
 */
final readonly class PlanPriceAdminService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanVersionTimeline $timeline,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function schedule(string $key, array $payload, string $actor): array
    {
        $fields = array_keys($payload);
        sort($fields);
        if ($fields !== [
            'addons', 'annual_amount', 'currency', 'effective_from',
            'expected_version', 'monthly_amount', 'quote_required', 'verticals',
        ] || !is_int($payload['expected_version']) || $payload['expected_version'] < 1
            || !is_array($payload['addons']) || !is_array($payload['verticals'])
        ) {
            throw new DomainException('Configuración de tarifas inválida.');
        }

        $base = self::validateRate([
            'currency' => $payload['currency'],
            'monthly_amount' => $payload['monthly_amount'],
            'annual_amount' => $payload['annual_amount'],
            'quote_required' => $payload['quote_required'],
        ]);
        $addons = self::validateMap($payload['addons'], $base['currency']);
        $verticals = self::validateMap($payload['verticals'], $base['currency']);
        $now = self::now();
        $at = self::effectiveFrom($payload['effective_from'], $now);
        $expected = $payload['expected_version'];

        return $this->em->wrapInTransaction(function () use (
            $key, $expected, $base, $addons, $verticals, $at, $now, $actor,
        ): array {
            $plan = $this->lockedPlan($key);
            $versions = $this->em->getRepository(PlanVersion::class)
                ->findBy(['plan' => $plan], ['version' => 'ASC']);
            $current = $this->timeline->effectiveAt($versions, $plan, $now);
            $latest = $versions === [] ? null : $versions[count($versions) - 1];

            if (!$current instanceof PlanVersion || $current->version() !== $expected
                || !$latest instanceof PlanVersion || $latest !== $current
                || $at <= $current->effectiveFrom()
            ) {
                throw new DomainException('Versión cambiada o tarifa futura pendiente; recarga antes de guardar.');
            }

            $allowedAddons = array_map(
                static fn ($item): string => $item->key(), $current->addOns(),
            );
            $allowedVerticals = array_map(
                static fn ($item): string => $item->key(), $current->verticals(),
            );
            if (array_diff(array_keys($addons), $allowedAddons) !== []
                || array_diff(array_keys($verticals), $allowedVerticals) !== []
            ) {
                throw new DomainException('Complemento o vertical fuera de la versión del plan.');
            }

            $before = self::snapshot($plan, $current);
            $rates = $current->priceOptions();
            $rates['addons'] = array_replace($rates['addons'] ?? [], $addons);
            $rates['verticals'] = array_replace($rates['verticals'] ?? [], $verticals);

            $next = new PlanVersion(
                $plan, $current->version() + 1,
                $base['monthly_amount'], $base['annual_amount'], $base['quote_required'],
                $current->limits(), $at, null, $base['currency'], $rates,
            );
            foreach ($current->capabilities() as $item) {
                $next->addCapability($item);
            }
            foreach ($current->verticals() as $item) {
                $next->addVertical($item);
            }
            foreach ($current->addOns() as $item) {
                $next->addAddOn($item);
            }
            $current->closeAt($at);
            $this->timeline->assertCanAdd($versions, $next);
            $this->em->persist($next);
            $after = self::snapshot($plan, $next);
            $this->em->persist(new PlatformAuditEvent(
                $actor, null, 'commercial.plan.prices.scheduled',
                'commercial_plan', $plan->id(), ['old' => $before, 'new' => $after],
            ));
            return $after;
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancelScheduled(string $key, array $payload, string $actor): array
    {
        if (array_keys($payload) !== ['expected_version']
            || !is_int($payload['expected_version']) || $payload['expected_version'] < 2
        ) {
            throw new DomainException('Cancelación de tarifa inválida.');
        }
        $expected = $payload['expected_version'];
        $now = self::now();

        return $this->em->wrapInTransaction(function () use (
            $key, $expected, $now, $actor,
        ): array {
            $plan = $this->lockedPlan($key);
            $versions = $this->em->getRepository(PlanVersion::class)
                ->findBy(['plan' => $plan], ['version' => 'ASC']);
            $scheduled = $versions === [] ? null : $versions[count($versions) - 1];
            $previous = count($versions) >= 2 ? $versions[count($versions) - 2] : null;
            if (!$scheduled instanceof PlanVersion || !$previous instanceof PlanVersion
                || $scheduled->version() !== $expected
                || $scheduled->effectiveFrom() <= $now
                || $scheduled->effectiveUntil() !== null
                || $previous->effectiveUntil() != $scheduled->effectiveFrom()
            ) {
                throw new DomainException('No existe una versión futura cancelable.');
            }

            $before = self::snapshot($plan, $scheduled);
            $previous->reopenAfterCancellation($scheduled->effectiveFrom());
            $this->em->remove($scheduled);
            $after = self::snapshot($plan, $previous);
            $this->em->persist(new PlatformAuditEvent(
                $actor, null, 'commercial.plan.prices.cancelled',
                'commercial_plan', $plan->id(), ['old' => $before, 'new' => $after],
            ));
            return $after;
        });
    }

    /**
     * @param array<string,mixed> $value
     * @return array{currency:string,monthly_amount:?int,annual_amount:?int,quote_required:bool}
     */
    public static function validateRate(array $value): array
    {
        $fields = array_keys($value);
        sort($fields);
        if ($fields !== ['annual_amount', 'currency', 'monthly_amount', 'quote_required']
            || !is_string($value['currency'])
            || preg_match('/^[A-Z]{3}$/D', $value['currency']) !== 1
            || !is_bool($value['quote_required'])
        ) {
            throw new DomainException('Tarifa comercial inválida.');
        }
        foreach (['monthly_amount', 'annual_amount'] as $field) {
            $amount = $value[$field];
            if ($amount !== null && (!is_int($amount) || $amount < 0 || $amount > 2147483647)) {
                throw new DomainException('Importe comercial inválido.');
            }
        }
        if ($value['quote_required']
            && ($value['monthly_amount'] !== null || $value['annual_amount'] !== null)
        ) {
            throw new DomainException('Cotizar no admite importes.');
        }

        return [
            'currency' => $value['currency'],
            'monthly_amount' => $value['monthly_amount'],
            'annual_amount' => $value['annual_amount'],
            'quote_required' => $value['quote_required'],
        ];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,array{currency:string,monthly_amount:?int,annual_amount:?int,quote_required:bool}>
     */
    private static function validateMap(array $input, string $currency): array
    {
        if (count($input) > 40 || ($input !== [] && array_is_list($input))) {
            throw new DomainException('Mapa de tarifas inválido.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $key) !== 1
                || !is_array($value)
            ) {
                throw new DomainException('Complemento o vertical inválido.');
            }
            $rate = self::validateRate($value);
            if ($rate['currency'] !== $currency) {
                throw new DomainException('Monedas distintas en el mismo plan.');
            }
            $result[$key] = $rate;
        }
        ksort($result);
        return $result;
    }

    private static function effectiveFrom(mixed $input, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($input === null) {
            return $now;
        }
        if (!is_string($input)) {
            throw new DomainException('Fecha de vigencia inválida.');
        }
        $format = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input) === 1
            ? '!Y-m-d' : '!Y-m-d\TH:i:s\Z';
        $at = DateTimeImmutable::createFromFormat($format, $input, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$at instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $at->format($format === '!Y-m-d' ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z') !== $input
        ) {
            throw new DomainException('La vigencia debe ser actual o futura en UTC.');
        }
        // Una fecha de calendario de hoy significa vigencia inmediata,
        // no medianoche pasada. Instantes explícitos nunca se retrodatan.
        if ($format === '!Y-m-d' && $at->format('Y-m-d') === $now->format('Y-m-d')) {
            return $now;
        }
        if ($at < $now) {
            throw new DomainException('La vigencia debe ser actual o futura en UTC.');
        }
        return $at;
    }

    private function lockedPlan(string $key): Plan
    {
        $plan = $this->em->getRepository(Plan::class)
            ->findOneBy(['key' => $key, 'active' => true]);
        if (!$plan instanceof Plan) {
            throw new DomainException('Plan inexistente.');
        }
        $this->em->lock($plan, LockMode::PESSIMISTIC_WRITE);
        return $plan;
    }

    /** @return array<string,mixed> */
    private static function snapshot(Plan $plan, PlanVersion $version): array
    {
        return [
            'plan_key' => $plan->key(),
            'version' => $version->version(),
            'effective_from' => $version->effectiveFrom()->format(DATE_ATOM),
            'effective_until' => $version->effectiveUntil()?->format(DATE_ATOM),
            'currency' => $version->currency(),
            'monthly_amount' => $version->monthlyAmount(),
            'annual_amount' => $version->annualAmount(),
            'quote_required' => $version->quoteRequired(),
            'price_options' => $version->priceOptions(),
        ];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
