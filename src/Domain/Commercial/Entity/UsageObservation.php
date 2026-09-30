<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_usage_observation')]
#[ORM\Index(
    name: 'idx_commercial_usage_tenant_metric_window',
    columns: ['tenant_id', 'metric', 'window_start', 'window_end'],
)]
#[ORM\Index(
    name: 'idx_commercial_usage_tenant_observed',
    columns: ['tenant_id', 'observed_at'],
)]
final class UsageObservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 120)]
    private string $tenantId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $metric;

    #[ORM\Column(type: 'bigint')]
    private int $quantity;

    #[ORM\Column(name: 'window_start', type: 'string', length: 32)]
    private string $windowStart;

    #[ORM\Column(name: 'window_end', type: 'string', length: 32)]
    private string $windowEnd;

    #[ORM\Column(name: 'observed_at', type: 'string', length: 32)]
    private string $observedAt;

    private function __construct()
    {
    }

    public static function fromRecord(UsageRecord $record): self
    {
        $observation = new self();
        $observation->id = UlidFactory::new();
        $observation->tenantId = $record->tenantId();
        $observation->metric = $record->metric()->value;
        $observation->quantity = $record->quantity();
        $observation->windowStart = self::formatExact($record->windowStart());
        $observation->windowEnd = self::formatExact($record->windowEnd());
        $observation->observedAt = self::formatExact($record->observedAt());

        return $observation;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function toRecord(): UsageRecord
    {
        $metric = UsageMetric::tryFrom($this->metric);
        if ($metric === null) {
            throw new DomainException('Métrica de usage persistida inválida.');
        }

        return new UsageRecord(
            $this->tenantId,
            $metric,
            $this->quantity,
            self::parseExact($this->windowStart),
            self::parseExact($this->windowEnd),
            self::parseExact($this->observedAt),
        );
    }

    private static function formatExact(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private static function parseExact(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s.u\\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || self::formatExact($parsed) !== $value
        ) {
            throw new DomainException('Timestamp de usage persistido inválido.');
        }

        return $parsed;
    }
}
