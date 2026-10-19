#!/usr/bin/env python3
"""Acceptance #689: offline employee aggregate, tenant isolation and auditing."""

from pathlib import Path
import json
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    ROOT / "src/Domain/HumanResources/Employee.php",
    ROOT / "src/Application/HumanResources/EmployeeStore.php",
    ROOT / "src/Application/HumanResources/EmployeeService.php",
)

SCENARIO = r'''<?php
declare(strict_types=1);
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/src/Application/HumanResources/EmployeeStore.php';

use App\Application\HumanResources\EmployeeService;
use App\Application\HumanResources\EmployeeStore;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\HumanResources\Employee;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DomainException as EmployeeDomainException;

final class MemoryEmployeeStore implements EmployeeStore
{
    public array $employees = [];
    public array $events = [];

    public function findById(Tenant $tenant, string $id): ?Employee
    {
        return $this->employees[$tenant->id()][$id] ?? null;
    }

    public function findByCode(Tenant $tenant, string $code): ?Employee
    {
        foreach ($this->employees[$tenant->id()] ?? [] as $employee) {
            if ($employee->code() === $code) {
                return $employee;
            }
        }
        return null;
    }

    public function findByUser(Tenant $tenant, User $user): ?Employee
    {
        foreach ($this->employees[$tenant->id()] ?? [] as $employee) {
            if ($employee->user()?->id() === $user->id()) {
                return $employee;
            }
        }
        return null;
    }

    public function commit(Employee $employee, AuditEvent $event): void
    {
        $this->employees[$employee->tenant()->id()][$employee->id()] = $employee;
        $this->events[] = $event;
    }
}

function rejected(callable $operation): bool
{
    try { $operation(); return false; }
    catch (EmployeeDomainException) { return true; }
}

$tenantA = new Tenant('Empresa A', 'empresa-a');
$tenantB = new Tenant('Empresa B', 'empresa-b');
$actor = new User('owner@example.test', 'Owner A');
$ownerA = new Membership($tenantA, $actor, Membership::ROLE_OWNER);
$ownerB = new Membership($tenantB, $actor, Membership::ROLE_OWNER);
$target = new User('staff@example.test', 'Staff A');
$memberA = new Membership($tenantA, $target, 'STAFF');
$memberB = new Membership($tenantB, $target, 'STAFF');
$store = new MemoryEmployeeStore();
$service = new EmployeeService($store);

$employee = $service->create($tenantA, ' emp-001 ', $actor, $ownerA);
$withoutUser = $employee->user() === null && $employee->code() === 'EMP-001';
$foreignAbsent = $service->find($tenantB, $employee->id(), $actor, $ownerB) === null;
$invalidCrossMembership = rejected(fn() => $service->attachUser($tenantA, $employee->id(), $target, $memberB, $actor, $ownerA));
$duplicateCode = rejected(fn() => $service->create($tenantA, 'EMP-001', $actor, $ownerA));
$crossOwner = rejected(fn() => $service->create($tenantB, 'EMP-002', $actor, $ownerA));
$invalidCode = rejected(fn() => $service->create($tenantA, "EMP\n3", $actor, $ownerA));

$linked = $service->attachUser($tenantA, $employee->id(), $target, $memberA, $actor, $ownerA);
$linkedCorrectly = $linked->user()?->id() === $target->id() &&
    $service->find($tenantA, $employee->id(), $actor, $ownerA)?->user()?->id() === $target->id();
$replay = $service->attachUser($tenantA, $employee->id(), $target, $memberA, $actor, $ownerA);
$repeatSafe = $replay->id() === $linked->id() && count($store->events) === 2;

$second = $service->create($tenantA, 'EMP-002', $actor, $ownerA);
$duplicateUser = rejected(fn() => $service->attachUser($tenantA, $second->id(), $target, $memberA, $actor, $ownerA));
$otherTenantSameCode = $service->create($tenantB, 'EMP-001', $actor, $ownerB);
$otherTenantIndependent = $otherTenantSameCode->user() === null && $otherTenantSameCode->tenant()->id() === $tenantB->id();
$invalidTenantEmployee = rejected(fn() => $service->attachUser($tenantB, $employee->id(), $target, $memberB, $actor, $ownerB));

echo json_encode([
    'without_user' => $withoutUser,
    'foreign_absent' => $foreignAbsent,
    'cross_member_denied' => $invalidCrossMembership,
    'duplicate_code_denied' => $duplicateCode,
    'wrong_owner_denied' => $crossOwner,
    'invalid_code_denied' => $invalidCode,
    'linked' => $linkedCorrectly,
    'replay_idempotent' => $repeatSafe,
    'duplicate_user_denied' => $duplicateUser,
    'same_code_other_tenant' => $otherTenantIndependent,
    'cross_tenant_employee_denied' => $invalidTenantEmployee,
    'audited' => count($store->events) === 4 &&
        array_reduce($store->events, static fn(bool $valid, $e) => $valid && $e instanceof AuditEvent, true),
], JSON_THROW_ON_ERROR);
'''


class EmpleadosBaseTests(unittest.TestCase):
    def test_acceptance(self):
        for file in FILES:
            self.assertTrue(file.is_file(), str(file))
            lint = subprocess.run(
                ["php", "-l", str(file)], cwd=ROOT, capture_output=True,
                text=True, check=False,
            )
            self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-employees-") as temp:
            scenario_path = Path(temp) / "employees_scenario.php"
            scenario_path.write_text(SCENARIO, encoding="utf-8")
            result = subprocess.run(
                ["php", str(scenario_path), str(ROOT)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            data = json.loads(result.stdout)
            for key, value in data.items():
                with self.subTest(case=key):
                    self.assertTrue(value, key)


if __name__ == "__main__":
    unittest.main()
