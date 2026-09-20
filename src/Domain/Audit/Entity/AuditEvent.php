<?php

declare(strict_types=1);

namespace App\Domain\Audit\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_audit_event')]
#[ORM\Index(name: 'idx_audit_tenant_created', columns: ['tenant_id', 'created_at'])]
class AuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $action;

    #[ORM\Column(name: 'entity_type', type: 'string', length: 120)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', type: 'string', length: 64)]
    private string $entityId;

    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Tenant $tenant,
        ?string $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        array $context = [],
    ) {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->actorUserId = $actorUserId;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
