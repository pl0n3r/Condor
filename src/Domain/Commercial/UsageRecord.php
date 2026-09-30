<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class UsageRecord
{
    private string $tenantId;
    private string $key;

    public function __construct(
        string $tenantId,
        string $key,
        private int $quantity,
        private UsageAggregation $aggregation,
        private DateTimeImmutable $observedAt,
    ) {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de usage inválido.');
        }
        if ($quantity < 0) {
            throw new DomainException('Cantidad de usage inválida.');
        }

        $this->tenantId = $tenantId;
        $this->key = self::canonicalKey($key);
    }

    public static function canonicalKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $key) !== 1) {
            throw new DomainException('Key de usage inválida.');
        }

        return $key;
    }

    public function tenantId(): string { return $this->tenantId; }
    public function key(): string { return $this->key; }
    public function quantity(): int { return $this->quantity; }
    public function aggregation(): UsageAggregation { return $this->aggregation; }
    public function observedAt(): DateTimeImmutable { return $this->observedAt; }

    /** @return array{tenant_id:string,key:string,quantity:int,aggregation:string,observed_at:string} */
    public function snapshot(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'key' => $this->key,
            'quantity' => $this->quantity,
            'aggregation' => $this->aggregation->value,
            'observed_at' => $this->observedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
