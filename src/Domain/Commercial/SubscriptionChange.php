<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\PlanVersion;
use DateTimeImmutable;
use DomainException;

final readonly class SubscriptionChange
{
    private const BLOCKERS = [
        'active_addons',
        'capabilities',
        'companies',
        'storage_mb',
        'users',
    ];

    /** @param list<AddOn> $addOns
     *  @param list<EntitlementOverride> $overrides
     *  @param list<string> $blockers
     */
    private function __construct(
        private string $tenantId,
        private PlanVersion $currentPlan,
        private PlanVersion $targetPlan,
        private string $direction,
        private string $status,
        private DateTimeImmutable $requestedAt,
        private ?DateTimeImmutable $effectiveAt,
        private array $addOns,
        private array $overrides,
        private array $blockers,
    ) {
    }

    /** @param array<array-key,mixed> $addOns
     *  @param array<array-key,mixed> $overrides
     */
    public static function upgrade(
        string $tenantId,
        PlanVersion $currentPlan,
        PlanVersion $targetPlan,
        DateTimeImmutable $requestedAt,
        array $addOns = [],
        array $overrides = [],
    ): self {
        $normalizedAddOns = self::normalizeAddOns($addOns);
        $normalizedOverrides = self::normalizeOverrides($tenantId, $overrides);
        self::assertNotNoOp($currentPlan, $targetPlan, $normalizedAddOns, $normalizedOverrides);

        return new self(
            trim($tenantId),
            $currentPlan,
            $targetPlan,
            'upgrade',
            'effective',
            $requestedAt,
            $requestedAt,
            $normalizedAddOns,
            $normalizedOverrides,
            [],
        );
    }

    /** @param array<array-key,mixed> $addOns
     *  @param array<array-key,mixed> $overrides
     *  @param array<array-key,mixed> $blockers
     */
    public static function downgrade(
        string $tenantId,
        PlanVersion $currentPlan,
        PlanVersion $targetPlan,
        DateTimeImmutable $requestedAt,
        DateTimeImmutable $renewsAt,
        bool $compatible,
        array $addOns = [],
        array $overrides = [],
        array $blockers = [],
    ): self {
        if ($renewsAt <= $requestedAt) {
            throw new DomainException('La renovación debe ser posterior a la solicitud.');
        }

        $normalizedAddOns = self::normalizeAddOns($addOns);
        $normalizedOverrides = self::normalizeOverrides($tenantId, $overrides);
        $normalizedBlockers = self::normalizeBlockers($blockers);

        self::assertNotNoOp($currentPlan, $targetPlan, $normalizedAddOns, $normalizedOverrides);

        if ($compatible && $normalizedBlockers !== []) {
            throw new DomainException('Un downgrade compatible no puede declarar blockers.');
        }
        if (!$compatible && $normalizedBlockers === []) {
            throw new DomainException('Un downgrade incompatible requiere blockers canónicos.');
        }

        return new self(
            trim($tenantId),
            $currentPlan,
            $targetPlan,
            'downgrade',
            $compatible ? 'scheduled' : 'pending_resolution',
            $requestedAt,
            $compatible ? $renewsAt : null,
            $normalizedAddOns,
            $normalizedOverrides,
            $normalizedBlockers,
        );
    }

    public function tenantId(): string { return $this->tenantId; }
    public function currentPlan(): PlanVersion { return $this->currentPlan; }
    public function targetPlan(): PlanVersion { return $this->targetPlan; }
    public function direction(): string { return $this->direction; }
    public function status(): string { return $this->status; }
    public function requestedAt(): DateTimeImmutable { return $this->requestedAt; }
    public function effectiveAt(): ?DateTimeImmutable { return $this->effectiveAt; }

    /** @return list<AddOn> */
    public function addOns(): array { return $this->addOns; }

    /** @return list<EntitlementOverride> */
    public function overrides(): array { return $this->overrides; }

    /** @return list<string> */
    public function blockers(): array { return $this->blockers; }

    /** @param list<AddOn> $addOns
     *  @param list<EntitlementOverride> $overrides
     */
    private static function assertNotNoOp(
        PlanVersion $currentPlan,
        PlanVersion $targetPlan,
        array $addOns,
        array $overrides,
    ): void {
        if ($currentPlan === $targetPlan && $addOns === [] && $overrides === []) {
            throw new DomainException('Cambio de suscripción sin efecto.');
        }
    }

    /** @param array<array-key,mixed> $addOns
     *  @return list<AddOn>
     */
    private static function normalizeAddOns(array $addOns): array
    {
        $normalized = [];
        foreach ($addOns as $addOn) {
            if (!$addOn instanceof AddOn) {
                throw new DomainException('Add-on inválido.');
            }
            $normalized[$addOn->key()] = $addOn;
        }
        ksort($normalized);

        return array_values($normalized);
    }

    /** @param array<array-key,mixed> $overrides
     *  @return list<EntitlementOverride>
     */
    private static function normalizeOverrides(string $tenantId, array $overrides): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de suscripción inválido.');
        }

        $normalized = [];
        foreach ($overrides as $override) {
            if (!$override instanceof EntitlementOverride || $override->tenantId() !== $tenantId) {
                throw new DomainException('Override incompatible con el tenant.');
            }
            $normalized[$override->entitlementNamespace() . ':' . $override->key()] = $override;
        }
        ksort($normalized);

        return array_values($normalized);
    }

    /** @param array<array-key,mixed> $blockers
     *  @return list<string>
     */
    private static function normalizeBlockers(array $blockers): array
    {
        $normalized = [];
        foreach ($blockers as $blocker) {
            if (!is_string($blocker) || !in_array($blocker, self::BLOCKERS, true)) {
                throw new DomainException('Blocker de downgrade inválido.');
            }
            $normalized[$blocker] = $blocker;
        }
        ksort($normalized);

        return array_values($normalized);
    }
}
