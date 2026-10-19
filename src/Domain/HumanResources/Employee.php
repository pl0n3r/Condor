<?php

declare(strict_types=1);

namespace App\Domain\HumanResources;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DomainException;

/**
 * Minimal offline HR aggregate. Employee identity is independent of login.
 * The code is an opaque tenant-local alias containing no personal attributes.
 * Database mapping is intentionally deferred to a separately scoped migration.
 */
final class Employee
{
    private readonly string $id;
    private readonly Tenant $tenant;
    private readonly string $code;
    private ?User $user = null;

    public function __construct(Tenant $tenant, string $code)
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,31}$/D', $code) !== 1) {
            throw new DomainException('Código de empleado inválido.');
        }
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->code = $code;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function linkedTo(User $user, Membership $membership): self
    {
        if (
            !$user->isActive()
            || !$membership->isActive()
            || $membership->tenant()->id() !== $this->tenant->id()
            || $membership->user()->id() !== $user->id()
        ) {
            throw new DomainException('El usuario no tiene membresía activa del tenant.');
        }

        // Never mutate an aggregate already held by the persistence adapter
        // before the paired employee + audit commit succeeds.
        $updated = clone $this;
        $updated->user = $user;

        return $updated;
    }
}
