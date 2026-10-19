#!/usr/bin/env python3
"""Executable #694 acceptance: offline attendance, tenant isolation, no biometrics."""
from __future__ import annotations

import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATHS = (
    "src/Domain/Attendance/AttendanceMark.php",
    "src/Domain/Attendance/AttendanceAdapter.php",
    "src/Domain/Attendance/AttendanceEvent.php",
    "src/Domain/Attendance/AttendanceEventStore.php",
    "src/Domain/Attendance/AttendanceService.php",
)

SCENARIO = r'''<?php
declare(strict_types=1);
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/src/Domain/Attendance/AttendanceAdapter.php';
require $argv[1] . '/src/Domain/Attendance/AttendanceEventStore.php';

use App\Domain\Attendance\AttendanceAdapter;
use App\Domain\Attendance\AttendanceEvent;
use App\Domain\Attendance\AttendanceEventStore;
use App\Domain\Attendance\AttendanceMark;
use App\Domain\Attendance\AttendanceService;
use App\Domain\HumanResources\Employee;
use App\Domain\Organization\Entity\Tenant;

final class MockReader implements AttendanceAdapter
{
    public function __construct(private string $key) {}
    public function sourceKey(): string { return $this->key; }
    public function normalizedMarks(): iterable { return []; }
}

final class MemoryAttendanceStore implements AttendanceEventStore
{
    public array $rows = [];
    public ?DateTimeImmutable $lastCutoff = null;

    public function recordOnce(AttendanceEvent $event, DateTimeImmutable $expireBefore): AttendanceEvent
    {
        $this->lastCutoff = $expireBefore;
        foreach ($this->rows as $key => $row) {
            if ($row->occurredAt() < $expireBefore->format('Y-m-d\TH:i:s\Z')) {
                unset($this->rows[$key]);
            }
        }
        $key = json_encode([
            $event->tenantId(), $event->sourceKey(), $event->externalEventId(),
        ], JSON_THROW_ON_ERROR);
        if (!array_key_exists($key, $this->rows)) {
            $this->rows[$key] = $event;
        }
        return $this->rows[$key];
    }
}
function rejected(callable $fn): bool
{
    try { $fn(); return false; } catch (DomainException|TypeError) { return true; }
}

$tenantA = new Tenant('A', 'attendance-a');
$tenantB = new Tenant('B', 'attendance-b');
$employeeA = new Employee($tenantA, 'EMP-001');
$employeeB = new Employee($tenantB, 'EMP-001');
$readerA = new MockReader('reader-a');
$readerB = new MockReader('reader-b');
$badReader = new MockReader('external@example.invalid');
$store = new MemoryAttendanceStore();
$service = new AttendanceService($store);
$now = new DateTimeImmutable('2026-10-19T18:00:00Z');
$input = [
    'external_event_id' => 'clock-0001',
    'occurred_at' => '2026-10-19T09:30:00Z',
    'type' => AttendanceMark::CHECK_IN,
];
$first = $service->recordPayload($tenantA, $employeeA, $readerA, $input, $now);
$repeat = $service->recordPayload($tenantA, $employeeA, $readerA, $input, $now);
$sameTenantOtherSource = $service->recordPayload($tenantA, $employeeA, $readerB, $input, $now);
$otherTenant = $service->recordPayload($tenantB, $employeeB, $readerA, $input, $now);
$crossTenantDenied = rejected(fn() => $service->recordPayload($tenantB, $employeeA, $readerA, $input, $now));
$payloadChanged = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA,
    [...$input, 'type' => AttendanceMark::CHECK_OUT], $now,
));
$foreignEmployeeSameKey = rejected(fn() => $service->recordPayload(
    $tenantA, new Employee($tenantA, 'EMP-002'), $readerA, $input, $now,
));
$biometricDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'fingerprint_template' => 'DO-NOT-STORE'], $now,
));
$faceDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'face_image' => ['pixels']], $now,
));
$unknownFieldDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'employee_id' => 'untrusted'], $now,
));
$badSourceDenied = rejected(fn() => $service->recordPayload($tenantA, $employeeA, $badReader, $input, $now));
$futureDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'occurred_at' => '2026-10-20T09:30:00Z'], $now,
));
$expiredDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'occurred_at' => '2026-09-01T09:30:00Z'], $now,
));
$badDateDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'occurred_at' => '2026-02-30T09:30:00Z'], $now,
));
$badTypeDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'type' => 'biometric_scan'], $now,
));
$badIdDenied = rejected(fn() => $service->recordPayload(
    $tenantA, $employeeA, $readerA, [...$input, 'external_event_id' => 'person@example.com'], $now,
));
$payload = json_encode($first->toSafeArray(), JSON_THROW_ON_ERROR);
echo json_encode([
    'replay_idempotent' => $repeat->id() === $first->id() && count($store->rows) === 3,
    'tenant_isolated' => $first->tenantId() !== $otherTenant->tenantId(),
    'source_isolated' => $first->id() !== $sameTenantOtherSource->id(),
    'employee_bound' => $first->employeeId() === $employeeA->id(),
    'tenant_guard' => $crossTenantDenied,
    'collision_guard' => $payloadChanged && $foreignEmployeeSameKey,
    'biometric_guard' => $biometricDenied && $faceDenied && $unknownFieldDenied,
    'source_guard' => $badSourceDenied,
    'retention_guard' => $futureDenied && $expiredDenied && $badDateDenied
        && $store->lastCutoff?->format('Y-m-d') === '2026-09-19',
    'schema_guard' => $badTypeDenied && $badIdDenied,
    'no_biometric_storage' => !str_contains($payload, 'fingerprint')
        && !str_contains($payload, 'biometric') && !str_contains($payload, 'face')
        && !str_contains($payload, 'DO-NOT-STORE')
        && count($first->toSafeArray()) === 7,
], JSON_THROW_ON_ERROR);
'''


class AsistenciaEventosTests(unittest.TestCase):
    def test_acceptance(self):
        for path in PATHS:
            file = ROOT / path
            self.assertTrue(file.is_file(), path)
            result = subprocess.run(
                ["php", "-l", str(file)], cwd=ROOT, text=True, capture_output=True,
                check=False, timeout=20,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-attendance-") as tmp:
            script = Path(tmp) / "scenario.php"
            script.write_text(SCENARIO, encoding="utf-8")
            result = subprocess.run(
                ["php", str(script), str(ROOT)], cwd=ROOT, text=True,
                capture_output=True, check=False, timeout=25,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        observed = json.loads(result.stdout)
        self.assertEqual(11, len(observed))
        for key, ok in observed.items():
            with self.subTest(case=key):
                self.assertTrue(ok, key)


if __name__ == "__main__":
    unittest.main()
