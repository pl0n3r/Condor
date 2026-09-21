<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_branch_role_assignment')]
#[ORM\UniqueConstraint(
    name: 'uniq_branch_role_assignment',
    columns: ['membership_id', 'branch_id', 'role_id'],
)]
class BranchRoleAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(name: 'membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Membership $membership;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Branch $branch;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Membership $membership,
        Branch $branch,
        Role $role,
    ) {
        $tenant = $membership->tenant();
        if (
            $branch->tenant()->id() !== $tenant->id()
            || $role->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'La membresía, la sede y el rol deben pertenecer a la misma empresa.',
            );
        }

        if (!$membership->isActive() || !$role->isActive()) {
            throw new DomainException('La membresía y el rol deben estar activos.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->branch = $branch;
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    public function branch(): Branch
    {
        return $this->branch;
    }

    public function role(): Role
    {
        return $this->role;
    }
}
