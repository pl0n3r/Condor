<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use DateTimeImmutable;
use DomainException;

final readonly class UsageRecord
{
    private string $tenantId;

    public function __construct(
        string $tenantId,
        private UsageMetric $metric,
        private int $quantity,
        private DateTimeImmutable $windowStart,
        private DateTimeImmutable $windowEnd,
        private DateTimeImmutable $observedAt,
    ) {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de usage inválido.');
        }
        if ($quantity < 0) {
            throw new DomainException('Cantidad de usage inválida.');
        }
        if ($windowEnd <= $windowStart) {
            throw new DomainException('Ventana de usage inválida.');
        }
        if ($observedAt < $windowStart) {
            throw new DomainException('La observación no puede preceder el inicio de la ventana.');
        }

        $this->tenantId = $tenantId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function metric(): UsageMetric
    {
        return $this->metric;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function windowStart(): DateTimeImmutable
    {
        return $this->windowStart;
    }

    public function windowEnd(): DateTimeImmutable
    {
        return $this->windowEnd;
    }

    public function observedAt(): DateTimeImmutable
    {
        return $this->observedAt;
    }

    /** @return array{tenant_id:string,metric:string,quantity:int,window_start:string,window_end:string,observed_at:string} */
    public function snapshot(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'metric' => $this->metric->value,
            'quantity' => $this->quantity,
            'window_start' => self::formatTime($this->windowStart),
            'window_end' => self::formatTime($this->windowEnd),
            'observed_at' => self::formatTime($this->observedAt),
        ];
    }

    private static function formatTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.uP');
    }
}
