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
    /**
     * @param list<AddOn> $selectedAddOns
     * @param list<EntitlementOverride> $overrides
     */
    public function __construct(
        private string $tenantId,
        private PlanVersion $planVersion,
        private Vertical $vertical,
        private array $selectedAddOns,
        private array $overrides,
        private DateTimeImmutable $evaluatedAt,
    ) {
        if (trim($tenantId) === '') {
            throw new DomainException('Tenant de entitlement inválido.');
        }
        foreach ($selectedAddOns as $addOn) {
            if (!$addOn instanceof AddOn) {
                throw new DomainException('Selección de add-ons inválida.');
            }
        }
        foreach ($overrides as $override) {
            if (!$override instanceof EntitlementOverride) {
                throw new DomainException('Overrides de entitlement inválidos.');
            }
        }
    }

    public function tenantId(): string { return $this->tenantId; }
    public function planVersion(): PlanVersion { return $this->planVersion; }
    public function vertical(): Vertical { return $this->vertical; }
    /** @return list<AddOn> */
    public function selectedAddOns(): array { return $this->selectedAddOns; }
    /** @return list<EntitlementOverride> */
    public function overrides(): array { return $this->overrides; }
    public function evaluatedAt(): DateTimeImmutable { return $this->evaluatedAt; }
}
