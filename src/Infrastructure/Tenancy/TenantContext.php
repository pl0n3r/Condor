<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Domain\Organization\Entity\Tenant;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        if ($this->tenant !== null && $this->tenant->id() !== $tenant->id()) {
            throw new LogicException('El tenant activo no puede cambiar durante la misma petición.');
        }

        $this->tenant = $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new LogicException('No existe un tenant activo para esta operación.');
    }
}
