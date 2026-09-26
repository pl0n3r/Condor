<?php

declare(strict_types=1);

namespace App\Domain\Orders\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_order_event')]
#[ORM\Index(name: 'idx_order_event_order_time', columns: ['tenant_id', 'order_id', 'created_at'])]
class OrderEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(type: 'string', length: 60)]
    private string $type;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<string, scalar|null> $context */
    public function __construct(
        Order $order,
        string $type,
        ?string $actorUserId = null,
        array $context = [],
    ) {
        $type = strtolower(trim($type));
        if ($type === '' || mb_strlen($type, 'UTF-8') > 60) {
            throw new DomainException('El tipo de evento del pedido no es válido.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $order->tenant();
        $this->order = $order;
        $this->type = $type;
        $this->actorUserId = $actorUserId;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }
    public function order(): Order
    {
        return $this->order;
    }
    public function type(): string
    {
        return $this->type;
    }
    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
