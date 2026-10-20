#!/usr/bin/env python3
"""Executable #718 acceptance: HTTP boundary with synthetic tenant stores.

Uses real PHP HttpFoundation Request/JsonResponse and domain services. There is
no database, network, biometric reader, or real employee data in this test.
"""
from pathlib import Path
import json
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/EmployeeController.php"
SCENARIO = r'''<?php
declare(strict_types=1);
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/src/Application/HumanResources/EmployeeStore.php';
require $argv[1].'/src/Domain/Attendance/AttendanceEventStore.php';
require $argv[1].'/src/Domain/Attendance/AttendanceAdapter.php';
require $argv[1].'/src/Http/Controller/EmployeeController.php';

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Attendance\AttendanceEvent;
use App\Domain\Attendance\AttendanceEventStore;
use App\Domain\HumanResources\Employee;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Http\Controller\EmployeeController;
use App\Http\Controller\EmployeeHttpStore;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class MemoryEmployeeHttpStore implements EmployeeHttpStore
{
    public array $employees = [];
    public array $memberships = [];
    public array $audit = [];
    public bool $leakOnList = false;

    public function membership(Tenant $tenant, User $user): ?Membership
    {
        return $this->memberships[$tenant->id()][$user->id()] ?? null;
    }
    public function listForTenant(Tenant $tenant): array
    {
        $rows = array_values($this->employees[$tenant->id()] ?? []);
        if ($this->leakOnList) {
            foreach ($this->employees as $key => $foreign) {
                if ($key !== $tenant->id()) {
                    array_push($rows, ...array_values($foreign));
                }
            }
        }
        return $rows;
    }
    public function findById(Tenant $tenant, string $id): ?Employee
    {
        return $this->employees[$tenant->id()][$id] ?? null;
    }
    public function findByCode(Tenant $tenant, string $code): ?Employee
    {
        foreach ($this->listForTenant($tenant) as $employee) {
            if ($employee->tenant()->id() === $tenant->id() && $employee->code() === $code) {
                return $employee;
            }
        }
        return null;
    }
    public function findByUser(Tenant $tenant, User $user): ?Employee
    {
        foreach ($this->listForTenant($tenant) as $employee) {
            if ($employee->tenant()->id() === $tenant->id()
                && $employee->user()?->id() === $user->id()) {
                return $employee;
            }
        }
        return null;
    }
    public function commit(Employee $employee, AuditEvent $event): void
    {
        $this->employees[$employee->tenant()->id()][$employee->id()] = $employee;
        $this->audit[] = $event;
    }
}

final class MemoryAttendanceStore implements AttendanceEventStore
{
    public array $events = [];
    public function recordOnce(AttendanceEvent $event, DateTimeImmutable $expireBefore): AttendanceEvent
    {
        foreach ($this->events as $key => $stored) {
            if ($stored->occurredAt() < $expireBefore->format('Y-m-d\TH:i:s\Z')) {
                unset($this->events[$key]);
            }
        }
        $key = json_encode([
            $event->tenantId(), $event->sourceKey(), $event->externalEventId(),
        ], JSON_THROW_ON_ERROR);
        if (!isset($this->events[$key])) {
            $this->events[$key] = $event;
        }
        return $this->events[$key];
    }
}
function invokeAction(EmployeeController $api, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($api, $method))->invoke($api, ...$args);
}
function req(array $data): Request
{
    return Request::create('/api/v1/hr/employees', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
}
function body($response): array
{
    return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}
function rejected(callable $fn, string $expected): bool
{
    try { $fn(); return false; }
    catch (Throwable $error) { return is_a($error, $expected); }
}

$tenantA = new Tenant('A', 'http-tenant-a');
$tenantB = new Tenant('B', 'http-tenant-b');
$owner = new User('owner@example.test', 'Owner');
$viewer = new User('viewer@example.test', 'Viewer');
$foreign = new User('foreign@example.test', 'Foreign');
$store = new MemoryEmployeeHttpStore();
$store->memberships[$tenantA->id()][$owner->id()] = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
$store->memberships[$tenantB->id()][$owner->id()] = new Membership($tenantB, $owner, Membership::ROLE_OWNER);
$store->memberships[$tenantA->id()][$viewer->id()] = new Membership($tenantA, $viewer, 'STAFF');
$store->memberships[$tenantB->id()][$foreign->id()] = new Membership($tenantB, $foreign, 'STAFF');
$events = new MemoryAttendanceStore();
$api = new EmployeeController($store, $events);
$api->setContainer(new ServiceLocator([]));

$created = invokeAction($api, 'createWithinTenant', $tenantA, $owner, req(['code' => ' emp-1 ']));
$createdData = body($created);
$employeeId = $createdData['employee']['id'];
$otherId = body(invokeAction($api, 'createWithinTenant', $tenantB, $owner, req(['code' => 'EMP-1'])))['employee']['id'];
$listA = body(invokeAction($api, 'indexWithinTenant', $tenantA, $owner))['employees'];
$listB = body(invokeAction($api, 'indexWithinTenant', $tenantB, $owner))['employees'];
$foreignHidden = rejected(
    fn() => invokeAction($api, 'showWithinTenant', $tenantB, $owner, $employeeId),
    NotFoundHttpException::class,
);
$notOwner = rejected(
    fn() => invokeAction($api, 'indexWithinTenant', $tenantA, $viewer),
    AccessDeniedHttpException::class,
) && rejected(
    fn() => invokeAction($api, 'createWithinTenant', $tenantA, $viewer, req(['code' => 'NO'])),
    AccessDeniedHttpException::class,
);
$store->leakOnList = true;
$leakGuard = rejected(
    fn() => invokeAction($api, 'indexWithinTenant', $tenantA, $owner),
    AccessDeniedHttpException::class,
);
$store->leakOnList = false;
$linked = body(invokeAction($api, 'linkWithinTenant', $tenantA, $owner, $employeeId, $viewer));
$relinked = body(invokeAction($api, 'linkWithinTenant', $tenantA, $owner, $employeeId, $viewer));
$foreignLink = rejected(
    fn() => invokeAction($api, 'linkWithinTenant', $tenantA, $owner, $employeeId, $foreign),
    UnprocessableEntityHttpException::class,
);
$payload = [
    'external_event_id' => 'clock-001',
    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 120),
    'type' => 'CHECK_IN',
];
$first = body(invokeAction($api, 'attendanceWithinTenant', $tenantA, $owner, $employeeId, req($payload)));
$replay = body(invokeAction($api, 'attendanceWithinTenant', $tenantA, $owner, $employeeId, req($payload)));
$collision = rejected(
    fn() => invokeAction($api, 'attendanceWithinTenant', $tenantA, $owner,
        $employeeId, req([...$payload, 'type' => 'CHECK_OUT'])),
    UnprocessableEntityHttpException::class,
);
$biometric = rejected(
    fn() => invokeAction($api, 'attendanceWithinTenant', $tenantA, $owner,
        $employeeId, req([...$payload, 'fingerprint_template' => 'SECRET'])),
    UnprocessableEntityHttpException::class,
);
$foreignAttendance = rejected(
    fn() => invokeAction($api, 'attendanceWithinTenant', $tenantB, $owner, $employeeId, req($payload)),
    NotFoundHttpException::class,
);
$viewerAttendanceDenied = rejected(
    fn() => invokeAction($api, 'attendanceWithinTenant', $tenantA, $viewer, $employeeId, req($payload)),
    AccessDeniedHttpException::class,
);
$extraFieldDenied = rejected(
    fn() => invokeAction($api, 'createWithinTenant', $tenantA, $owner,
        req(['code' => 'NO', 'salary' => '1000000'])),
    UnprocessableEntityHttpException::class,
);
$oversizedJson = rejected(
    fn() => invokeAction($api, 'createWithinTenant', $tenantA, $owner,
        req(['code' => str_repeat('X', 4097)])),
    BadRequestHttpException::class,
);
$malformedJson = rejected(
    fn() => invokeAction($api, 'createWithinTenant', $tenantA, $owner,
        Request::create('/api/v1/hr/employees', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], '{not-json')),
    BadRequestHttpException::class,
);
$disabled = new EmployeeController();
$disabled->setContainer(new ServiceLocator([]));
$failClosed = rejected(
    fn() => invokeAction($disabled, 'indexWithinTenant', $tenantA, $owner),
    ServiceUnavailableHttpException::class,
);
$responseJson = json_encode([$createdData, $linked, $first], JSON_THROW_ON_ERROR);
echo json_encode([
    'create_http_201' => $created->getStatusCode() === 201 && $createdData['employee']['code'] === 'EMP-1',
    'tenant_list_isolation' => count($listA) === 1 && count($listB) === 1
        && $listA[0]['id'] === $employeeId && $listB[0]['id'] === $otherId,
    'foreign_employee_hidden' => $foreignHidden,
    'permissions_fail_closed' => $notOwner,
    'untrusted_store_guard' => $leakGuard,
    'link_audited_once' => $linked['employee']['linked']
        && $relinked['employee']['linked'] && count($store->audit) === 3,
    'foreign_link_denied' => $foreignLink,
    'replay_stable_id' => $first['event']['id'] === $replay['event']['id'] && count($events->events) === 1,
    'collision_denied' => $collision,
    'biometric_denied' => $biometric,
    'foreign_attendance_denied' => $foreignAttendance,
    'viewer_attendance_denied' => $viewerAttendanceDenied,
    'sensitive_extra_field_denied' => $extraFieldDenied,
    'oversized_json_denied' => $oversizedJson,
    'malformed_json_denied' => $malformedJson,
    'unwired_api_disabled' => $failClosed,
    'minimal_safe_json' => !str_contains($responseJson, 'owner@example.test')
        && !str_contains($responseJson, 'fingerprint')
        && array_keys($createdData['employee']) === ['id', 'code', 'linked'],
], JSON_THROW_ON_ERROR);
'''


class EmpleadosApiTests(unittest.TestCase):
    def test_acceptance(self):
        source = CONTROLLER.read_text(encoding="utf-8")
        for phrase in (
            "EmployeeHttpStore extends EmployeeStore",
            "->resolve($actor)",
            "requireCsrf($request)",
            "AttendanceService($this->attendanceEvents)",
            "/api/v1/hr/employees",
            "/link",
            "/attendance",
        ):
            self.assertIn(phrase, source)
        lint = subprocess.run(
            ["php", "-l", str(CONTROLLER)], cwd=ROOT, capture_output=True, text=True,
            check=False, timeout=20,
        )
        self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)
        with tempfile.TemporaryDirectory(prefix="condor718-") as temp:
            scenario = Path(temp) / "hr_http.php"
            scenario.write_text(SCENARIO, encoding="utf-8")
            result = subprocess.run(
                ["php", str(scenario), str(ROOT)], cwd=ROOT, capture_output=True,
                text=True, check=False, timeout=35,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        evidence = json.loads(result.stdout)
        self.assertEqual(len(evidence), 17)
        for name, passes in evidence.items():
            with self.subTest(case=name):
                self.assertTrue(passes, name)


if __name__ == "__main__":
    unittest.main()
