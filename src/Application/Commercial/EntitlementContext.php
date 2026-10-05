<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\EntitlementOverride;
use DateTimeImmutable;
use DomainException;

final readonly class EntitlementContext
{
    private string $tenantId;

    /** @var list<AddOn> */
    private array $selectedAddOns;

    /** @var list<EntitlementOverride> */
    private array $overrides;

    /** @var array<string,int> */
    private array $configuredLimits;

    /**
     * @param list<mixed> $selectedAddOns
     * @param list<mixed> $overrides
     * @param array<array-key,mixed> $configuredLimits
     */
    public function __construct(
        string $tenantId,
        private PlanVersion $planVersion,
        private Vertical $vertical,
        array $selectedAddOns,
        array $overrides,
        private DateTimeImmutable $evaluatedAt,
        array $configuredLimits = [],
    ) {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de entitlement inválido.');
        }

        $validatedAddOns = [];
        foreach ($selectedAddOns as $addOn) {
            if (!$addOn instanceof AddOn) {
                throw new DomainException('Selección de add-ons inválida.');
            }
            $validatedAddOns[] = $addOn;
        }

        $validatedOverrides = [];
        foreach ($overrides as $override) {
            if (!$override instanceof EntitlementOverride) {
                throw new DomainException('Overrides de entitlement inválidos.');
            }
            $validatedOverrides[] = $override;
        }

        $this->tenantId = $tenantId;
        $this->selectedAddOns = $validatedAddOns;
        $this->overrides = $validatedOverrides;
        $this->configuredLimits = self::normalizeConfiguredLimits(
            $planVersion,
            $configuredLimits,
        );
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function planVersion(): PlanVersion
    {
        return $this->planVersion;
    }

    public function vertical(): Vertical
    {
        return $this->vertical;
    }

    /** @return list<AddOn> */
    public function selectedAddOns(): array
    {
        return $this->selectedAddOns;
    }

    /** @return list<EntitlementOverride> */
    public function overrides(): array
    {
        return $this->overrides;
    }

    /** @return array<string,int> */
    public function configuredLimits(): array
    {
        return $this->configuredLimits;
    }

    public function evaluatedAt(): DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    /**
     * @param array<array-key,mixed> $configuredLimits
     * @return array<string,int>
     */
    private static function normalizeConfiguredLimits(
        PlanVersion $planVersion,
        array $configuredLimits,
    ): array {
        if ($configuredLimits === []) {
            return [];
        }
        if (array_is_list($configuredLimits)) {
            throw new DomainException('Límites configurados inválidos.');
        }

        $baseline = $planVersion->limits();
        $normalized = [];
        foreach ($configuredLimits as $key => $value) {
            $base = is_string($key) && array_key_exists($key, $baseline)
                ? $baseline[$key]
                : null;
            if (
                !is_string($key)
                || !is_int($base)
                || !is_int($value)
                || $value < 1
                || $value < $base
            ) {
                throw new DomainException(
                    'Límite configurado incompatible con la PlanVersion.',
                );
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        return $normalized;
    }
}
