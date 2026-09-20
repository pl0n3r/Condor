<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_tenant_user', columns: ['tenant_id', 'user_id'])]
class Membership
{
    public const ROLE_OWNER = 'OWNER';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'role_key', type: 'string', length: 64)]
    private string $roleKey;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, User $user, string $roleKey)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->user = $user;
        $this->roleKey = $roleKey;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function roleKey(): string
    {
        return $this->roleKey;
    }
}
