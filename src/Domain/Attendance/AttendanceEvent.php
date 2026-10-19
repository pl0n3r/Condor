<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;

/**
 * Immutable, pseudonymous attendance event. Retention is enforced at ingress
 * and must be implemented atomically by every eventual persistence adapter.
 */
final readonly class AttendanceEvent
{
    private string $id;
    private string $tenantId;
    private string $employeeId;
    private string $sourceKey;
    private string $externalEventId;
    private string $occurredAt;
    private string $type;

    public function __construct(Tenant $tenant, AttendanceMark $mark, string $sourceKey)
    {
        $this->id = UlidFactory::new();
        $this->tenantId = $tenant->id();
        $this->employeeId = $mark->employee->id();
        $this->sourceKey = $sourceKey;
        $this->externalEventId = $mark->externalEventId;
        $this->occurredAt = $mark->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $this->type = $mark->type;
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function employeeId(): string { return $this->employeeId; }
    public function sourceKey(): string { return $this->sourceKey; }
    public function externalEventId(): string { return $this->externalEventId; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function type(): string { return $this->type; }

    public function hasSamePayload(self $other): bool
    {
        return $this->tenantId === $other->tenantId
            && $this->employeeId === $other->employeeId
            && $this->sourceKey === $other->sourceKey
            && $this->externalEventId === $other->externalEventId
            && $this->occurredAt === $other->occurredAt
            && $this->type === $other->type;
    }

    /**
     * Explicit safe projection: no raw device payload or unrestricted personal field.
     * @return array<string, string>
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'employee_id' => $this->employeeId,
            'source_key' => $this->sourceKey,
            'external_event_id' => $this->externalEventId,
            'occurred_at' => $this->occurredAt,
            'type' => $this->type,
        ];
    }
}
