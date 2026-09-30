<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use DateTimeImmutable;
use DomainException;

final class UsageLedger
{
    private readonly string $tenantId;

    /** @var list<UsageRecord> */
    private array $records = [];

    /** @var array<string,UsageAggregation> */
    private array $aggregations = [];

    /** @var array<string,true> */
    private array $gaugeObservations = [];

    public function __construct(string $tenantId)
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de ledger inválido.');
        }

        $this->tenantId = $tenantId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function add(UsageRecord $record): void
    {
        if ($record->tenantId() !== $this->tenantId) {
            throw new DomainException('Usage pertenece a otro tenant.');
        }

        $key = $record->key();
        $known = $this->aggregations[$key] ?? null;
        if ($known !== null && $known !== $record->aggregation()) {
            throw new DomainException('Una métrica no puede mezclar modos de agregación.');
        }

        if ($record->aggregation() === UsageAggregation::Gauge) {
            $identity = $key.'|'.$record->observedAt()->format('U.u');
            if (isset($this->gaugeObservations[$identity])) {
                throw new DomainException('Gauge ambiguo para el mismo timestamp.');
            }
            $this->gaugeObservations[$identity] = true;
        }

        $this->aggregations[$key] = $record->aggregation();
        $this->records[] = $record;
    }

    public function value(
        string $key,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): ?int {
        $key = UsageRecord::canonicalKey($key);
        self::assertWindow($from, $until);

        $matches = array_values(array_filter(
            $this->records,
            static fn (UsageRecord $record): bool =>
                $record->key() === $key
                && $record->observedAt() >= $from
                && $record->observedAt() < $until,
        ));

        if ($matches === []) {
            return null;
        }

        $aggregation = $this->aggregations[$key] ?? null;
        if ($aggregation === null) {
            throw new DomainException('Agregación de usage desconocida.');
        }

        if ($aggregation === UsageAggregation::Counter) {
            $total = 0;
            foreach ($matches as $record) {
                if ($record->quantity() > PHP_INT_MAX - $total) {
                    throw new DomainException('Overflow de usage acumulado.');
                }
                $total += $record->quantity();
            }

            return $total;
        }

        $latest = null;
        foreach ($matches as $record) {
            if ($latest === null || $record->observedAt() > $latest->observedAt()) {
                $latest = $record;
            }
        }

        return $latest?->quantity();
    }

    /**
     * @return array{used:int,limit:int,remaining:int,exceeded:bool}
     */
    public function againstPlanLimit(
        PlanVersion $planVersion,
        string $key,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        $key = UsageRecord::canonicalKey($key);
        $limits = $planVersion->limits();
        if (!array_key_exists($key, $limits)) {
            throw new DomainException('Límite comercial desconocido.');
        }

        $limit = $limits[$key];
        if (!is_int($limit) || $limit < 0) {
            throw new DomainException('El límite comercial no es medible como entero.');
        }

        $used = $this->value($key, $from, $until);
        if ($used === null) {
            throw new DomainException('No existe observación de usage para la ventana.');
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'exceeded' => $used > $limit,
        ];
    }

    /**
     * @return list<array{tenant_id:string,key:string,quantity:int,aggregation:string,observed_at:string}>
     */
    public function snapshot(): array
    {
        $records = $this->records;
        usort(
            $records,
            static fn (UsageRecord $left, UsageRecord $right): int => [
                $left->key(),
                $left->aggregation()->value,
                $left->observedAt()->format('U.u'),
                $left->quantity(),
            ] <=> [
                $right->key(),
                $right->aggregation()->value,
                $right->observedAt()->format('U.u'),
                $right->quantity(),
            ],
        );

        return array_map(
            static fn (UsageRecord $record): array => $record->snapshot(),
            $records,
        );
    }

    private static function assertWindow(
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): void {
        if ($until <= $from) {
            throw new DomainException('Ventana de usage inválida.');
        }
    }
}
