<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\HumanResources\Employee;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;

/**
 * Narrow persistence port for the offline HR slice.
 *
 * A production adapter MUST enforce tenant-scoped uniqueness of code and
 * linked user, and persist employee + audit event atomically in one transaction.
 * No runtime adapter or database migration is authorized by issue #689.
 */
interface EmployeeStore
{
    public function findById(Tenant $tenant, string $id): ?Employee;

    public function findByCode(Tenant $tenant, string $code): ?Employee;

    public function findByUser(Tenant $tenant, User $user): ?Employee;

    public function commit(Employee $employee, AuditEvent $event): void;
}
