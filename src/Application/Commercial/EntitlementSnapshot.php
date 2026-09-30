<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class EntitlementSnapshot
{
    /** @var array<string,bool> */
    private array $capabilities;
    /** @var array<string,bool> */
    private array $addOns;
    /** @var array<string,bool|int|string|null> */
    private array $limits;
    /** @var list<array{namespace:string,key:string,value:bool|int|string|null,reason:string,actor:string,created_at:string}> */
    private array $overrideProvenance;

    /**
     * @param array<string,bool> $capabilities
     * @param array<string,bool> $addOns
     * @param array<string,bool|int|string|null> $limits
     * @param list<array{namespace:string,key:string,value:bool|int|string|null,reason:string,actor:string,created_at:string}> $overrideProvenance
     */
    public function __construct(
        private string $tenantId,
        private string $planKey,
        private int $planVersion,
        private string $verticalKey,
        array $capabilities,
        array $addOns,
        array $limits,
        array $overrideProvenance,
        private DateTimeImmutable $evaluatedAt,
    ) {
        ksort($capabilities);
        ksort($addOns);
        ksort($limits);
        usort($overrideProvenance, static fn (array $a, array $b): int =>
            [$a['created_at'], $a['namespace'], $a['key']]
            <=> [$b['created_at'], $b['namespace'], $b['key']]
        );
        $this->capabilities = $capabilities;
        $this->addOns = $addOns;
        $this->limits = $limits;
        $this->overrideProvenance = $overrideProvenance;
    }

    public function tenantId(): string { return $this->tenantId; }
    public function planKey(): string { return $this->planKey; }
    public function planVersion(): int { return $this->planVersion; }
    public function verticalKey(): string { return $this->verticalKey; }
    public function evaluatedAt(): DateTimeImmutable { return $this->evaluatedAt; }

    public function capability(string $key): bool
    {
        $key = strtolower(trim($key));
        if (!array_key_exists($key, $this->capabilities)) {
            throw new DomainException('Capability de entitlement desconocida.');
        }
        return $this->capabilities[$key];
    }

    public function addOn(string $key): bool
    {
        $key = strtolower(trim($key));
        if (!array_key_exists($key, $this->addOns)) {
            throw new DomainException('Add-on de entitlement desconocido.');
        }
        return $this->addOns[$key];
    }

    public function limit(string $key): bool|int|string|null
    {
        $key = strtolower(trim($key));
        if (!array_key_exists($key, $this->limits)) {
            throw new DomainException('Límite de entitlement desconocido.');
        }
        return $this->limits[$key];
    }

    /** @return array<string,bool> */
    public function capabilities(): array { return $this->capabilities; }
    /** @return array<string,bool> */
    public function addOns(): array { return $this->addOns; }
    /** @return array<string,bool|int|string|null> */
    public function limits(): array { return $this->limits; }
    /** @return list<array{namespace:string,key:string,value:bool|int|string|null,reason:string,actor:string,created_at:string}> */
    public function overrideProvenance(): array { return $this->overrideProvenance; }
}
