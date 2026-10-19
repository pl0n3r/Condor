<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\HumanResources\Employee;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Owner-authorized HR operations. Both writes carry an audit record.
 * No HTTP wiring, migration, real personal data or production activation.
 */
final readonly class EmployeeService
{
    public function __construct(private EmployeeStore $employees)
    {
    }

    public function create(
        Tenant $tenant,
        string $code,
        User $actor,
        Membership $actorMembership,
    ): Employee {
        self::requireOwner($tenant, $actor, $actorMembership);
        $employee = new Employee($tenant, $code);
        if ($this->employees->findByCode($tenant, $employee->code()) instanceof Employee) {
            throw new DomainException('El código ya existe en este tenant.');
        }

        $this->employees->commit($employee, new AuditEvent(
            $tenant,
            $actor->id(),
            'hr.employee.created',
            Employee::class,
            $employee->id(),
            ['employee_code' => $employee->code()],
        ));

        return $employee;
    }

    public function attachUser(
        Tenant $tenant,
        string $employeeId,
        User $target,
        Membership $targetMembership,
        User $actor,
        Membership $actorMembership,
    ): Employee {
        self::requireOwner($tenant, $actor, $actorMembership);
        $employee = $this->employees->findById($tenant, $employeeId);
        if (!$employee instanceof Employee || $employee->tenant()->id() !== $tenant->id()) {
            throw new DomainException('Empleado no encontrado en el tenant activo.');
        }
        // Validate the target before looking for competing links.
        $updated = $employee->linkedTo($target, $targetMembership);
        if ($employee->user()?->id() === $target->id()) {
            return $employee;
        }
        $existing = $this->employees->findByUser($tenant, $target);
        if ($existing instanceof Employee && $existing->id() !== $employee->id()) {
            throw new DomainException('El usuario ya tiene vínculo de empleado.');
        }

        $this->employees->commit($updated, new AuditEvent(
            $tenant,
            $actor->id(),
            'hr.employee.user_linked',
            Employee::class,
            $employee->id(),
            ['linked' => true],
        ));

        return $updated;
    }

    public function find(
        Tenant $tenant,
        string $employeeId,
        User $actor,
        Membership $actorMembership,
    ): ?Employee {
        self::requireOwner($tenant, $actor, $actorMembership);
        $employee = $this->employees->findById($tenant, $employeeId);
        if ($employee instanceof Employee && $employee->tenant()->id() === $tenant->id()) {
            return $employee;
        }

        return null;
    }

    private static function requireOwner(Tenant $tenant, User $actor, Membership $membership): void
    {
        if (
            !$actor->isActive()
            || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER
            || $membership->tenant()->id() !== $tenant->id()
            || $membership->user()->id() !== $actor->id()
        ) {
            throw new DomainException('Acceso denegado a empleados del tenant.');
        }
    }
}
