<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Capability;
use App\Domain\Commercial\Entity\VerticalCapability;
use App\Domain\Commercial\EntitlementOverride;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class EntitlementResolver
{
    public function __construct(
        private PlanConfiguratorCatalogReader $catalog,
        private EntityManagerInterface $entityManager,
    ) {}

    public function resolve(EntitlementContext $context): EntitlementSnapshot
    {
        $version = $context->planVersion();
        $vertical = $context->vertical();
        if (!$version->plan()->isActive() || !$version->isEffectiveAt($context->evaluatedAt()) || !$vertical->isActive()) {
            throw new DomainException('Contexto comercial inactivo o fuera de vigencia.');
        }

        $options = $this->catalog->options(
            $version->plan()->key(),
            $vertical->key(),
            $context->evaluatedAt(),
        );
        if (
            ($options['plan']['version'] ?? null) !== $version->version()
            || ($options['vertical']['key'] ?? null) !== $vertical->key()
        ) {
            throw new DomainException('Contexto comercial stale.');
        }

        $capabilities = [];
        foreach ($version->capabilities() as $capability) {
            $capabilities[$capability->key()] = false;
        }
        foreach ($options['capabilities'] as $capability) {
            $key = $capability['key'] ?? null;
            if (!is_string($key) || !array_key_exists($key, $capabilities)) {
                throw new DomainException('Catálogo de capabilities inconsistente.');
            }
            $capabilities[$key] = true;
        }

        $planAddOns = [];
        $addOns = [];
        foreach ($version->addOns() as $addOn) {
            $planAddOns[$addOn->key()] = $addOn;
            $addOns[$addOn->key()] = false;
        }
        foreach ($context->selectedAddOns() as $selected) {
            $canonical = $planAddOns[$selected->key()] ?? null;
            if (!$canonical instanceof AddOn || !$canonical->isActive() || !$selected->isActive()) {
                throw new DomainException('Add-on incompatible con la PlanVersion.');
            }
            $addOns[$selected->key()] = true;
        }

        $limits = $version->limits();
        $provenance = $this->applyOverrides(
            $context,
            $capabilities,
            $addOns,
            $limits,
            $planAddOns,
        );

        return new EntitlementSnapshot(
            $context->tenantId(),
            $version->plan()->key(),
            $version->version(),
            $vertical->key(),
            $capabilities,
            $addOns,
            $limits,
            $provenance,
            $context->evaluatedAt(),
        );
    }

    /**
     * @param array<string,bool> $capabilities
     * @param array<string,bool> $addOns
     * @param array<string,bool|int|string|null> $limits
     * @param array<string,AddOn> $planAddOns
     * @return list<array<string,mixed>>
     */
    private function applyOverrides(
        EntitlementContext $context,
        array &$capabilities,
        array &$addOns,
        array &$limits,
        array $planAddOns,
    ): array {
        $overrides = $context->overrides();
        usort($overrides, static fn (EntitlementOverride $a, EntitlementOverride $b): int =>
            [$a->createdAt()->format('U.u'), $a->entitlementNamespace(), $a->key()]
            <=> [$b->createdAt()->format('U.u'), $b->entitlementNamespace(), $b->key()]
        );

        $seen = [];
        $provenance = [];
        foreach ($overrides as $override) {
            if ($override->tenantId() !== $context->tenantId()) {
                throw new DomainException('Override pertenece a otro tenant.');
            }
            $identity = $override->entitlementNamespace().':'.$override->key();
            $stamp = $override->createdAt()->format('U.u');
            if (($seen[$identity] ?? null) === $stamp) {
                throw new DomainException('Overrides ambiguos para la misma key.');
            }
            $seen[$identity] = $stamp;

            match ($override->entitlementNamespace()) {
                'capability' => $this->applyCapability($context, $override, $capabilities),
                'addon' => $this->applyAddOn($override, $addOns, $planAddOns),
                'limit' => $this->applyLimit($override, $limits),
                default => throw new DomainException('Namespace de entitlement inválido.'),
            };
            $provenance[] = $override->snapshot();
        }
        return $provenance;
    }

    /** @param array<string,bool> $capabilities */
    private function applyCapability(
        EntitlementContext $context,
        EntitlementOverride $override,
        array &$capabilities,
    ): void {
        if (!is_bool($override->value())) {
            throw new DomainException('Override de capability debe ser booleano.');
        }
        if ($override->value() === false) {
            if (!array_key_exists($override->key(), $capabilities)) {
                throw new DomainException('Capability comercial desconocida.');
            }
            $capabilities[$override->key()] = false;
            return;
        }

        $capability = $this->entityManager->getRepository(Capability::class)
            ->findOneBy(['key' => $override->key(), 'active' => true]);
        if (!$capability instanceof Capability) {
            throw new DomainException('Capability comercial desconocida o inactiva.');
        }
        $relation = $this->entityManager->getRepository(VerticalCapability::class)
            ->findOneBy(['key' => VerticalCapability::keyFor($context->vertical(), $capability)]);
        if (!$relation instanceof VerticalCapability) {
            throw new DomainException('Capability incompatible con el vertical.');
        }
        $capabilities[$capability->key()] = true;
    }

    /**
     * @param array<string,bool> $addOns
     * @param array<string,AddOn> $planAddOns
     */
    private function applyAddOn(EntitlementOverride $override, array &$addOns, array $planAddOns): void
    {
        if (!is_bool($override->value())) {
            throw new DomainException('Override de add-on debe ser booleano.');
        }
        $addOn = $planAddOns[$override->key()] ?? null;
        if (!$addOn instanceof AddOn || !$addOn->isActive()) {
            throw new DomainException('Add-on incompatible o inactivo.');
        }
        $addOns[$override->key()] = $override->value();
    }

    /** @param array<string,bool|int|string|null> $limits */
    private function applyLimit(EntitlementOverride $override, array &$limits): void
    {
        if (!array_key_exists($override->key(), $limits)) {
            throw new DomainException('Límite comercial desconocido.');
        }
        $base = $limits[$override->key()];
        $value = $override->value();
        if (
            ($base === null && $value !== null)
            || ($base !== null && get_debug_type($base) !== get_debug_type($value))
        ) {
            throw new DomainException('Tipo de override de límite incompatible.');
        }
        $limits[$override->key()] = $value;
    }
}
