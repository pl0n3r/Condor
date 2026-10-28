#!/usr/bin/env python3
"""M05 HTTP contract: real PHP facade with an in-memory audited fixture port."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

BASE = Path(__file__).resolve().parents[1]
API = BASE / "src/Application/SocialSecurity/SocialSecurityApiService.php"
HTTP = BASE / "src/Http/Controller/SocialSecurityController.php"

RUNTIME = r"""
namespace App\Domain\Identity\Entity {
    final class User {
        public function __construct(private string $key, private bool $enabled = true) {}
        public function id(): string { return $this->key; }
        public function isActive(): bool { return $this->enabled; }
    }
}
namespace App\Domain\Organization\Entity {
    final class Tenant {
        public function __construct(private string $key) {}
        public function id(): string { return $this->key; }
    }
    final class LegalEntity {
        public function __construct(private string $key, private Tenant $owner) {}
        public function id(): string { return $this->key; }
        public function tenant(): Tenant { return $this->owner; }
    }
    final class Branch {
        public function __construct(private Tenant $owner, private ?LegalEntity $entity) {}
        public function legalEntity(): ?LegalEntity { return $this->entity; }
        public function tenant(): Tenant { return $this->owner; }
    }
}
namespace {
require $argv[1];
use App\Application\SocialSecurity\SocialSecurityApiService;
use App\Application\SocialSecurity\SocialSecurityApiPort;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Branch;

final class FakeLedger implements SocialSecurityApiPort {
    public array $entries = [];
    public array $audits = [];

    public function query(
        string $tenant, string $entity, ?string $ref, string $who, string $reason
    ): ?array {
        $this->audits[] = $reason;
        if ($ref !== null) return $this->entries[$tenant.'|'.$entity.'|'.$ref] ?? null;
        return array_values(array_filter($this->entries, static fn(array $row): bool =>
            $row['tenant_ref'] === $tenant && $row['entity_ref'] === $entity));
    }

    public function commit(
        array $data, string $command, string $who, ?int $expectedVersion
    ): array {
        $key = $data['tenant_ref'].'|'.$data['entity_ref'].'|'.$data['affiliation_ref'];
        if ($command === 'register') {
            if (isset($this->entries[$key])) throw new \DomainException('duplicate');
            $this->entries[$key] = $data;
        } else {
            if (!isset($this->entries[$key])
                || $this->entries[$key]['version'] !== $expectedVersion) {
                throw new \DomainException('CAS violation');
            }
            $this->entries[$key]['state'] = $data['state'];
            $this->entries[$key]['version']++;
        }
        // Fake atomic update and audit; production adapter intentionally absent.
        $this->audits[] = $command;
        return $this->entries[$key];
    }
}

$ledger = new FakeLedger();
$api = new SocialSecurityApiService($ledger);
$out = [];
foreach (json_decode($argv[2], true, 24, JSON_THROW_ON_ERROR) as $command) {
    $tenant = new Tenant($command['tenant'] ?? 'test-a');
    $entityOwner = new Tenant($command['owner'] ?? ($command['tenant'] ?? 'test-a'));
    $legalEntity = isset($command['no_entity']) ? null :
        new LegalEntity($command['entity'] ?? 'entity-a', $entityOwner);
    $branch = new Branch($tenant, $legalEntity);
    $actor = new User('actor-a', !isset($command['inactive']));
    $allowed = !isset($command['denied']);
    try {
        $out[] = match ($command['action']) {
            'list' => $api->list($tenant, $branch, $actor, $allowed),
            'view' => $api->view($tenant, $branch, $actor, $allowed, $command['ref']),
            'register' => $api->register($tenant, $branch, $actor, $allowed, $command['data']),
            'revise' => $api->revise($tenant, $branch, $actor, $allowed, $command['ref'], $command['data']),
        };
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
$out[] = ['audits' => $ledger->audits];
echo json_encode($out, JSON_THROW_ON_ERROR);
}
"""

def run(*ops: dict) -> list:
    p = subprocess.run(
        ["php", "-n", "-d", "display_errors=stderr", "-r", RUNTIME,
         str(API), json.dumps(ops)],
        cwd=BASE, capture_output=True, text=True, timeout=30, check=False,
    )
    if p.returncode:
        raise AssertionError(p.stdout + p.stderr)
    return json.loads(p.stdout)


def act(action: str, tenant: str = "test-a", entity: str = "entity-a", **extra) -> dict:
    return {"action": action, "tenant": tenant, "entity": entity, **extra}


def data(**extra) -> dict:
    return {
        "affiliation_ref": "fixture:affiliation/example",
        "employee_ref": "fixture:employee/example",
        "plan_ref": "fixture:plan/example",
        **extra,
    }


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        results = run(
            act("register", data=data()),
            act("register", entity="entity-b", data=data()),
            act("register", tenant="test-b", data=data()),
            act("list"),
            act("list", entity="entity-b"),
            act("list", tenant="test-b"),
            act("view", ref="fixture:affiliation/example"),
            act("view", entity="entity-b", ref="fixture:affiliation/example"),
            act("view", entity="entity-c", ref="fixture:affiliation/example"),
            act("list", owner="test-b"),
        )
        self.assertEqual(3, len({item["entity_ref"] for item in results[:3]}))
        for i in range(3):
            self.assertEqual([results[i]], results[i + 3])
        self.assertEqual(results[0], results[6])
        self.assertEqual(results[1], results[7])
        self.assertIsNone(results[8])
        self.assertEqual({"error": "denied"}, results[9])

    def test_permisos_y_auditoria(self) -> None:
        source = HTTP.read_text()
        support = (BASE / "src/Http/Controller/BranchApiSupport.php").read_text()
        for phrase in ("'users.view'", "'users.create'", "'users.update'",
                       "Membership::ROLE_OWNER", "authorizedBranchScope",
                       "requireCsrf($request)", "Cache-Control", "private, no-store"):
            self.assertIn(phrase, source)
        self.assertIn("$this->authorization->require(", support)
        rows = run(
            act("register", data=data()),
            act("list"),
            act("view", ref="fixture:affiliation/example"),
            act("revise", ref="fixture:affiliation/example",
                data={"state": "EN_REVISION", "expected_version": 1}),
            act("register", denied=True, data=data(affiliation_ref="fixture:affiliation/other")),
            act("list", inactive=True),
        )
        self.assertEqual("EN_REVISION", rows[3]["state"])
        self.assertEqual([{"error": "denied"}] * 2, rows[4:6])
        self.assertEqual(
            ["register", "social_security.list", "social_security.view", "revise"],
            rows[-1]["audits"],
        )
        self.assertIn("atomically commit", API.read_text())

    def test_sin_datos_reales(self) -> None:
        for fixture in (
            data(employee_ref="person@example.com"),
            data(employee_ref="+573001234567"),
            data(employee_ref="1234567890"),
            data(name="Persona real"),
            data(plan_ref="not-fixture"),
            data(plan_ref=42),
            data(affiliation_ref="fixture:affiliation/UPPER"),
        ):
            with self.subTest(fixture=fixture):
                self.assertEqual({"error": "denied"}, run(act("register", data=fixture))[0])
        src = HTTP.read_text()
        self.assertIn("CONDOR_SOCIAL_SECURITY_SYNTHETIC_API", src)
        self.assertIn("CONDOR_PRODUCTION_STAGE", src)
        self.assertIn("$environment !== 'test'", src)
        self.assertNotIn("['tenant_ref'", src)
        self.assertNotIn("['entity_ref'", src)

    def test_estados_explicitos(self) -> None:
        ref = "fixture:affiliation/example"
        rows = run(
            act("register", data=data()),
            act("revise", ref=ref, data={"state": "EN_REVISION", "expected_version": 1}),
            act("revise", ref=ref, data={"state": "APROBADO", "expected_version": 2}),
            act("revise", ref=ref, data={"state": "ENVIADO", "expected_version": 2}),
            act("revise", ref=ref, data={"state": "BORRADOR", "expected_version": 1}),
            act("revise", ref=ref, data={"state": "BORRADOR", "expected_version": 2}),
            act("view", ref=ref),
            act("register", data=data()),
        )
        self.assertEqual(("BORRADOR", 1), (rows[0]["state"], rows[0]["version"]))
        self.assertEqual(("EN_REVISION", 2), (rows[1]["state"], rows[1]["version"]))
        for index in (2, 3, 4, 7):
            self.assertEqual({"error": "denied"}, rows[index])
        self.assertEqual(("BORRADOR", 3), (rows[5]["state"], rows[5]["version"]))
        self.assertEqual(rows[5], rows[6])
        self.assertEqual(4, len(rows[-1]["audits"]))


if __name__ == "__main__":
    unittest.main()
