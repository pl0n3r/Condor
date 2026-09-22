<?php

declare(strict_types=1);

namespace App\Domain\Audit\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_platform_audit_event')]
#[ORM\Index(name: 'idx_platform_audit_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_platform_audit_tenant_created', columns: ['tenant_id', 'created_at'])]
class PlatformAuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26)]
    private string $actorUserId;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 26, nullable: true)]
    private ?string $tenantId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $action;

    #[ORM\Column(name: 'entity_type', type: 'string', length: 120)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', type: 'string', length: 64)]
    private string $entityId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $context */
    public function __construct(
        string $actorUserId,
        ?string $tenantId,
        string $action,
        string $entityType,
        string $entityId,
        array $context = [],
    ) {
        $this->id = UlidFactory::new();
        $this->actorUserId = $actorUserId;
        $this->tenantId = $tenantId;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
