#!/usr/bin/env python3
"""Isolated PHP execution of the M04 synthetic payroll HTTP service contract."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Payroll/PayrollApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/PayrollController.php"

HARNESS = r"""
namespace App\Domain\Identity\Entity {
    final class User {
        public function __construct(private string $id, private bool $active=true) {}
        public function id(): string { return $this->id; }
        public function isActive(): bool { return $this->active; }
    }
}
namespace App\Domain\Organization\Entity {
    final class Tenant {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
    }
    final class LegalEntity {
        public function __construct(private string $id, private Tenant $owner) {}
        public function id(): string { return $this->id; }
        public function tenant(): Tenant { return $this->owner; }
    }
    final class Branch {
        public function __construct(private Tenant $tenant, private ?LegalEntity $company) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function legalEntity(): ?LegalEntity { return $this->company; }
    }
}
namespace {
require $argv[1];
use App\Application\Payroll\PayrollApiService;
use App\Application\Payroll\PayrollApiPort;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Identity\Entity\User;

final class AuditedMemory implements PayrollApiPort {
    private array $storage = [];
    public array $events = [];

    public function read(string $tenant, string $company, ?string $ref): ?array {
        $key = $tenant.'|'.$company.'|';
        if ($ref !== null) return $this->storage[$key.$ref] ?? null;
        return array_values(array_filter($this->storage,
            static fn(array $r): bool => $r['tenant_ref'] === $tenant
                && $r['company_ref'] === $company));
    }

    public function write(string $action, array $data, ?int $expected, string $actor): array {
        $key = $data['tenant_ref'].'|'.$data['company_ref'].'|'.$data['payroll_ref'];
        if ($action === 'create') {
            if (isset($this->storage[$key])) throw new \DomainException('duplicate');
            $this->storage[$key] = $data;
        } else {
            if (!isset($this->storage[$key]) || $this->storage[$key]['version'] !== $expected)
                throw new \DomainException('version conflict or missing record');
            $this->storage[$key]['state'] = $data['state'];
            $this->storage[$key]['version']++;
        }
        // The fake port models an atomic write+audit. No real DB is configured.
        $this->events[] = $action;
        return $this->storage[$key];
    }

    public function logRead(
        string $tenant, string $company, string $ref, string $actor, string $action,
    ): void {
        $this->events[] = $action;
    }
}
$port = new AuditedMemory();
$service = new PayrollApiService($port);
$results = [];
foreach (json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR) as $op) {
    $tenant = new Tenant($op['tenant'] ?? 'tenant-a');
    $companyOwner = new Tenant($op['foreign'] ?? ($op['tenant'] ?? 'tenant-a'));
    $company = isset($op['no_company']) ? null
        : new LegalEntity($op['company'] ?? 'company-a', $companyOwner);
    $branch = new Branch($tenant, $company);
    $actor = new User('actor-a', !isset($op['inactive']));
    try {
        $results[] = $service->execute(
            $tenant, $branch, $actor, !isset($op['forbidden']),
            $op['command'], $op['input'] ?? [],
        );
    } catch (\DomainException $e) {
        $results[] = ['error' => 'denied'];
    }
}
$results[] = ['events' => $port->events];
echo json_encode($results, JSON_THROW_ON_ERROR);
}
"""


def call(commands: list[dict]) -> list:
    outcome = subprocess.run(
        ["php", "-n", "-d", "display_errors=stderr", "-r", HARNESS,
         str(SERVICE), json.dumps(commands)],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if outcome.returncode:
        raise AssertionError(outcome.stdout + outcome.stderr)
    return json.loads(outcome.stdout)


def op(command: str, tenant="tenant-a", company="company-a", **kwargs) -> dict:
    return {"command": command, "tenant": tenant, "company": company, **kwargs}


def fixture(**overrides) -> dict:
    return {
        "payroll_ref": "fixture:payroll/sample",
        "employee_ref": "fixture:employee/sample",
        "period_ref": "fixture:period/sample",
        **overrides,
    }


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        result = call([
            op("create", input=fixture()),
            op("create", company="company-b", input=fixture()),
            op("create", tenant="tenant-b", input=fixture()),
            op("list"), op("list", company="company-b"), op("list", tenant="tenant-b"),
            op("view", input={"ref": "fixture:payroll/sample"}),
            op("view", company="company-b", input={"ref": "fixture:payroll/sample"}),
            op("view", company="company-c", input={"ref": "fixture:payroll/sample"}),
            op("list", foreign="tenant-b"),
        ])
        self.assertEqual(3, len({r["record"]["company_ref"] for r in result[:3]}))
        for offset in range(3):
            self.assertEqual([result[offset]["record"]], result[offset + 3]["records"])
        self.assertEqual(result[0]["record"], result[6]["record"])
        self.assertEqual(result[1]["record"], result[7]["record"])
        self.assertIsNone(result[8]["record"])
        self.assertEqual({"error": "denied"}, result[9])
        self.assertNotIn("company-b", json.dumps(result))

    def test_permisos_y_auditoria(self) -> None:
        controller = CONTROLLER.read_text()
        support = (ROOT / "src/Http/Controller/BranchApiSupport.php").read_text()
        for value in ("'users.view'", "'users.create'", "'users.update'",
                      "Membership::ROLE_OWNER", "authorizedBranchScope",
                      "requireCsrf", "private, no-store", "CONDOR_PAYROLL_SYNTHETIC_API"):
            self.assertIn(value, controller)
        self.assertIn("$this->authorization->require(", support)
        result = call([
            op("create", input=fixture()),
            op("list"), op("view", input={"ref": "fixture:payroll/sample"}),
            op("state", input={"ref": "fixture:payroll/sample", "state": "EN_REVISION",
                               "expected_version": 1}),
            op("list", forbidden=True),
            op("create", inactive=True, input=fixture(payroll_ref="fixture:payroll/other")),
        ])
        self.assertEqual("EN_REVISION", result[3]["record"]["state"])
        self.assertEqual({"error": "denied"}, result[4])
        self.assertEqual({"error": "denied"}, result[5])
        self.assertEqual(["create", "payroll.listed", "payroll.viewed", "state"],
                         result[-1]["events"])
        self.assertIn("one transaction", SERVICE.read_text())

    def test_sin_datos_reales(self) -> None:
        bad = [
            fixture(payroll_ref="persona@example.com"),
            fixture(employee_ref="+573001234567"),
            fixture(employee_ref="1234567890"),
            fixture(period_ref="2026-10"),
            fixture(full_name="Ninguna persona real"),
            fixture(employee_ref=None),
            fixture(payroll_ref="fixture:payroll/UPPER"),
            fixture(period_ref=5),
        ]
        for item in bad:
            with self.subTest(item=item):
                self.assertEqual({"error": "denied"}, call([op("create", input=item)])[0])
        source = CONTROLLER.read_text()
        self.assertNotIn("$payload['tenant", source)
        self.assertNotIn("$payload['company", source)
        self.assertIn("CONDOR_PRODUCTION_STAGE", source)
        self.assertIn("$environment !== 'test'", source)

    def test_estados_explicitos(self) -> None:
        ref = {"ref": "fixture:payroll/sample"}
        result = call([
            op("create", input=fixture()),
            op("state", input={**ref, "state": "EN_REVISION", "expected_version": 1}),
            op("state", input={**ref, "state": "CERRADO", "expected_version": 2}),
            op("state", input={**ref, "state": "PAGADO", "expected_version": 2}),
            op("state", input={**ref, "state": "BORRADOR", "expected_version": 1}),
            op("state", input={**ref, "state": "BORRADOR", "expected_version": 2}),
            op("view", input=ref),
            op("create", input=fixture()),
        ])
        self.assertEqual("BORRADOR", result[0]["record"]["state"])
        self.assertEqual(1, result[0]["record"]["version"])
        self.assertEqual("EN_REVISION", result[1]["record"]["state"])
        self.assertEqual(2, result[1]["record"]["version"])
        for index in (2, 3, 4, 7):
            self.assertEqual({"error": "denied"}, result[index])
        self.assertEqual("BORRADOR", result[5]["record"]["state"])
        self.assertEqual(3, result[5]["record"]["version"])
        self.assertEqual(result[5], result[6])
        self.assertEqual(4, len(result[-1]["events"]))


if __name__ == "__main__":
    unittest.main()
