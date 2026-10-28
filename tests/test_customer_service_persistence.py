#!/usr/bin/env python3
"""#1059 M16: execute real PHP code against an in-memory DBAL test double.

No network, database connection, customer data or production migration.
The DBAL double only simulates SQL operations; it does not attest a live DB.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/CustomerService/Entity/CustomerCaseRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/CustomerCaseRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261109120000.php"

PHP_FIXTURE = r"""
namespace Doctrine\DBAL {
    class Connection {
        public array $records = [];
        public array $audits = [];
        public function transactional(callable $callback): mixed { return $callback(); }
        public function fetchOne(string $sql, array $args = []): mixed {
            if (str_contains($sql, "FROM condor_tenant")) { return $args[0]; }
            if (str_contains($sql, "FROM condor_customer_case_record")) {
                return isset($this->records[$args[0].":".$args[1]]) ? $args[1] : false;
            }
            return false;
        }
        public function insert(string $table, array $row): int {
            if ($table === "condor_customer_case_record") {
                foreach ($this->records as $existing) {
                    if ($existing["tenant_id"] === $row["tenant_id"]
                        && $existing["case_reference"] === $row["case_reference"]) {
                        throw new \DomainException("duplicate case reference");
                    }
                }
                $this->records[$row["tenant_id"].":".$row["id"]] = $row;
                return 1;
            }
            if ($table === "condor_customer_case_audit") {
                if (!isset($this->records[$row["tenant_id"].":".$row["case_id"]])) {
                    throw new \DomainException("audit requires case");
                }
                $this->audits[] = $row;
                return 1;
            }
            throw new \DomainException("unknown table");
        }
        public function fetchAssociative(string $sql, array $args = []): array|false {
            return $this->records[$args[0].":".$args[1]] ?? false;
        }
        public function executeStatement(string $sql, array $args = []): int {
            [$state,$version,$time,$tenant,$case,$expected] = $args;
            $key = $tenant.":".$case;
            if (!isset($this->records[$key])
                || $this->records[$key]["version"] !== $expected) { return 0; }
            $this->records[$key]["status"] = $state;
            $this->records[$key]["version"] = $version;
            $this->records[$key]["updated_at"] = $time;
            return 1;
        }
        public function fetchAllAssociative(string $sql, array $args = []): array {
            return array_values(array_filter(
                $this->audits,
                static fn (array $event): bool =>
                    $event["tenant_id"] === $args[0] && $event["case_id"] === $args[1],
            ));
        }
    }
}
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $key) {}
        public function id(): string { return $this->key; }
    }
}
namespace App\Shared\Id {
    class UlidFactory {
        public static function new(): string {
            static $seq = 0;
            return str_pad(strtoupper(dechex(++$seq)), 26, "0", STR_PAD_LEFT);
        }
    }
}
namespace {
require $argv[1];
require $argv[2];
use App\Domain\Organization\Entity\Tenant;
use App\Domain\CustomerService\Entity\CustomerCaseRecord;
use App\Infrastructure\Persistence\CustomerCaseRecordRepository;
use Doctrine\DBAL\Connection;
function denied(callable $callback): bool {
    try { $callback(); return false; }
    catch (\DomainException $error) { return true; }
}
function setup(): array {
    $db = new Connection();
    $repo = new CustomerCaseRecordRepository($db);
    $alpha = new Tenant(str_repeat("A", 26));
    $beta = new Tenant(str_repeat("B", 26));
    return [$db, $repo, $alpha, $beta, "fixture:actor/qa"];
}
"""


def run_php(code: str) -> dict:
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("PHP real es obligatorio")
    script = PHP_FIXTURE + code + "\n}\n"
    completed = subprocess.run(
        [executable, "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(ENTITY), str(REPOSITORY)],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if completed.returncode != 0:
        raise AssertionError(completed.stdout + completed.stderr)
    import json
    return json.loads(completed.stdout)


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        observed = run_php(r"""
[$db,$repo,$alpha,$beta,$actor] = setup();
$one = new CustomerCaseRecord($alpha, "fixture:customer-case/same", "PQRS");
$two = new CustomerCaseRecord($beta, "fixture:customer-case/same", "PQRS");
$repo->create($alpha->id(), $one, $actor, true);
$repo->create($beta->id(), $two, $actor, true);
$a = $repo->find($alpha->id(), $one->id(), $actor, true);
$b = $repo->find($beta->id(), $two->id(), $actor, true);
$cross = $repo->find($alpha->id(), $two->id(), $actor, true);
$denied = denied(fn () => $repo->create($alpha->id(), new CustomerCaseRecord($beta, "fixture:customer-case/alien", "PQRS"), $actor, true));
echo json_encode(["distinct" => $one->id() !== $two->id(),
    "a" => $a["tenant_id"], "b" => $b["tenant_id"],
    "foreign_absent" => $cross === null, "cross_write_denied" => $denied,
    "count" => count($db->records)], JSON_THROW_ON_ERROR);
""")
        self.assertTrue(observed["distinct"])
        self.assertEqual(observed["a"], "A" * 26)
        self.assertEqual(observed["b"], "B" * 26)
        self.assertTrue(observed["foreign_absent"])
        self.assertTrue(observed["cross_write_denied"])
        self.assertEqual(observed["count"], 2)

    def test_permisos_y_auditoria(self) -> None:
        observed = run_php(r"""
[$db,$repo,$alpha,$beta,$actor] = setup();
$case = new CustomerCaseRecord($alpha, "fixture:customer-case/audit", "SOPORTE");
$noCreate = denied(fn () => $repo->create($alpha->id(), $case, $actor, false));
$repo->create($alpha->id(), $case, $actor, true);
$noRead = denied(fn () => $repo->find($alpha->id(), $case->id(), $actor, false));
$noActor = denied(fn () => $repo->find($alpha->id(), $case->id(), "user@example.invalid", true));
$read = $repo->find($alpha->id(), $case->id(), $actor, true);
$noTransition = denied(fn () => $repo->transition($alpha->id(), $case->id(), $actor, false, 1, "EN_REVISION"));
$repo->transition($alpha->id(), $case->id(), $actor, true, 1, "EN_REVISION");
$history = $repo->history($alpha->id(), $case->id(), $actor, true);
echo json_encode(["no_create" => $noCreate, "no_read" => $noRead,
    "no_actor" => $noActor, "no_transition" => $noTransition,
    "status" => $read["status"],
    "events" => array_column($history, "action"),
    "count" => count($db->audits)], JSON_THROW_ON_ERROR);
""")
        for field in ("no_create", "no_read", "no_actor", "no_transition"):
            self.assertTrue(observed[field], field)
        self.assertEqual(observed["status"], "PENDIENTE")
        self.assertEqual(observed["events"], ["create", "read", "transition"])
        self.assertEqual(observed["count"], 4)  # history access is audited too

    def test_sin_datos_reales(self) -> None:
        observed = run_php(r"""
[$db,$repo,$alpha,$beta,$actor] = setup();
$rejected = [];
foreach (["someone@example.invalid", "3012345678", "Juan Pérez",
    "https://example.invalid/real", "fixture:customer-case/real@example.invalid",
    "fixture:customer-case/1234567890"] as $reference) {
    $rejected[] = denied(fn () => new CustomerCaseRecord($alpha, $reference, "PQRS"));
}
$badKind = denied(fn () => new CustomerCaseRecord($alpha, "fixture:customer-case/test", "INFORME_PERSONAL"));
$case = new CustomerCaseRecord($alpha, "fixture:customer-case/fixture_a", "PQRS");
$repo->create($alpha->id(), $case, $actor, true);
echo json_encode(["denied" => $rejected, "kind" => $badKind,
    "columns" => array_keys(array_values($db->records)[0])], JSON_THROW_ON_ERROR);
""")
        self.assertTrue(all(observed["denied"]))
        self.assertTrue(observed["kind"])
        for forbidden in ("email", "phone", "name", "description", "document", "payload"):
            self.assertNotIn(forbidden, observed["columns"])
        entity = ENTITY.read_text(encoding="utf-8")
        self.assertNotIn("HttpClient", entity)
        self.assertNotIn("curl_exec", entity)

    def test_estados_explicitos(self) -> None:
        observed = run_php(r"""
[$db,$repo,$alpha,$beta,$actor] = setup();
$case = new CustomerCaseRecord($alpha, "fixture:customer-case/lifecycle", "GARANTIA");
$repo->create($alpha->id(), $case, $actor, true);
$before = $repo->find($alpha->id(), $case->id(), $actor, true);
$earlyClose = denied(fn () => $repo->transition($alpha->id(), $case->id(), $actor, true, 1, "CERRADO", true));
$repo->transition($alpha->id(), $case->id(), $actor, true, 1, "EN_REVISION");
$notExplicit = denied(fn () => $repo->transition($alpha->id(), $case->id(), $actor, true, 2, "CERRADO"));
$stale = denied(fn () => $repo->transition($alpha->id(), $case->id(), $actor, true, 1, "EN_REVISION"));
$review = $repo->find($alpha->id(), $case->id(), $actor, true);
$repo->transition($alpha->id(), $case->id(), $actor, true, 2, "CERRADO", true);
$after = $repo->find($alpha->id(), $case->id(), $actor, true);
$duplicate = denied(fn () => $repo->transition($alpha->id(), $case->id(), $actor, true, 3, "CERRADO", true));
echo json_encode(["initial" => $before["status"], "review" => $review["status"],
    "final" => $after["status"], "version" => $after["version"],
    "early" => $earlyClose, "not_explicit" => $notExplicit,
    "stale" => $stale, "duplicate" => $duplicate], JSON_THROW_ON_ERROR);
""")
        self.assertEqual(observed["initial"], "PENDIENTE")
        self.assertEqual(observed["review"], "EN_REVISION")
        self.assertEqual(observed["final"], "CERRADO")
        self.assertEqual(observed["version"], 3)
        for flag in ("early", "not_explicit", "stale", "duplicate"):
            self.assertTrue(observed[flag], flag)

        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up(", 1)[1].split("public function down(", 1)[0]
        self.assertEqual(len(re.findall(r"\bCREATE TABLE\b", up)), 2)
        for forbidden in (r"\bDROP TABLE\b", r"\bTRUNCATE\b",
                          r"\bDELETE FROM\b", r"\bALTER TABLE\b"):
            self.assertNotRegex(up, forbidden)
        self.assertIn("IrreversibleMigration", source)


if __name__ == "__main__":
    unittest.main()
