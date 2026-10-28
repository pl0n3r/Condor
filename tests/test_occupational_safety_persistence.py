"""M03 SG-SST — executable fixture contracts against real PHP entity and repository."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

BASE = Path(__file__).resolve().parents[1]
MODEL = BASE / "src/Domain/Compliance/Entity/OccupationalRecord.php"
STORE = BASE / "src/Infrastructure/Persistence/OccupationalRecordRepository.php"
SCHEMA = BASE / "migrations/Version20261101120000.php"

# The repository is executed in PHP; this in-memory DBAL port deliberately
# rejects unscoped statements and preserves two independent tenant buckets.
PHP_HARNESS = r"""
namespace Doctrine\DBAL {
    final class Connection {
        public array $data = [];
        public array $events = [];
        public bool $failAudit = false;

        public function transactional(callable $callback): mixed {
            $savedData = $this->data;
            $savedEvents = $this->events;
            try {
                return $callback();
            } catch (\Throwable $failure) {
                $this->data = $savedData;
                $this->events = $savedEvents;
                throw $failure;
            }
        }
        public function insert(string $table, array $values): void {
            if ($table === 'condor_occupational_audit') {
                if ($this->failAudit) {
                    throw new \DomainException('audit injection failure');
                }
                $this->events[] = $values;
            } elseif ($table === 'condor_occupational_record') {
                $scope = $values['tenant_ref'];
                $id = $values['record_ref'];
                if (isset($this->data[$scope][$id])) {
                    throw new \DomainException('duplicate fixture');
                }
                $this->data[$scope][$id] = $values;
            } else {
                throw new \DomainException('unexpected destination');
            }
        }
        public function fetchAllAssociative(string $query, array $bindings): array {
            if (!str_contains($query, 'WHERE tenant_ref = ?')) {
                throw new \DomainException('unscoped list');
            }
            return array_values($this->data[$bindings[0]] ?? []);
        }
        public function fetchAssociative(string $query, array $bindings): array|false {
            if (!str_contains($query, 'WHERE tenant_ref = ? AND record_ref = ?')) {
                throw new \DomainException('unscoped lookup');
            }
            return $this->data[$bindings[0]][$bindings[1]] ?? false;
        }
        public function update(string $table, array $patch, array $keys): int {
            if ($table !== 'condor_occupational_record'
                || !isset($keys['tenant_ref'], $keys['record_ref'])) {
                throw new \DomainException('unscoped mutation');
            }
            $tenant = $keys['tenant_ref'];
            $id = $keys['record_ref'];
            if (!isset($this->data[$tenant][$id])) {
                return 0;
            }
            $this->data[$tenant][$id] = array_replace($this->data[$tenant][$id], $patch);
            return 1;
        }
    }
}
namespace {
    require $argv[1];
    require $argv[2];

    $db = new \Doctrine\DBAL\Connection();
    $store = new \App\Infrastructure\Persistence\OccupationalRecordRepository($db);
    $input = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
    $observations = [];

    foreach ($input as $step) {
        $db->failAudit = (bool) ($step['fail_audit'] ?? false);
        $tenant = $step['tenant'] ?? 'tenant-a';
        $record = $step['record'] ?? 'fixture:occupational/inspection';
        $actor = $step['actor'] ?? 'fixture:actor/operator';
        $allowed = $step['allowed'] ?? true;
        try {
            $observations[] = match ($step['action']) {
                'create' => $store->create(
                    new \App\Domain\Compliance\Entity\OccupationalRecord(
                        $tenant, $record, $actor, $allowed,
                        $step['review_due'] ?? null,
                    ),
                    $tenant, $actor, $allowed,
                ),
                'list' => $store->forTenant($tenant, $actor, $allowed),
                'read' => $store->view($tenant, $record, $actor, $allowed),
                'change' => $store->transition(
                    $tenant, $record, $actor, $allowed,
                    $step['state'], $step['evidence'] ?? null,
                ),
            };
        } catch (\DomainException $exception) {
            $observations[] = ['denied' => true];
        }
    }
    echo json_encode(
        ['observations' => $observations, 'events' => $db->events],
        JSON_THROW_ON_ERROR,
    );
}
"""



# Execute the actual Doctrine migration method against a guarded fake connection.
# The fake enforces that no DROP or emptiness read can occur outside LOCK TABLES.
MIGRATION_HARNESS = r"""
namespace Doctrine\DBAL\Schema {
    class Schema {}
}
namespace Doctrine\Migrations\Exception {
    class IrreversibleMigration extends \RuntimeException {}
}
namespace Doctrine\Migrations {
    class AbstractMigration {
        protected object $connection;
        public function __construct(object $connection) {
            $this->connection = $connection;
        }
        protected function addSql(string $statement): void {
            throw new \RuntimeException('deferred rollback is unsafe');
        }
    }
}
namespace {
    class GuardedDb {
        public bool $locked = false;
        public array $events = [];
        public function __construct(private bool $hasRows) {}
        public function executeStatement(string $sql): int {
            if (str_starts_with($sql, 'LOCK TABLES')) {
                $this->locked = true;
                $this->events[] = 'lock';
                return 0;
            }
            if ($sql === 'UNLOCK TABLES') {
                $this->locked = false;
                $this->events[] = 'unlock';
                return 0;
            }
            if (str_starts_with($sql, 'DROP TABLE')) {
                if (!$this->locked) {
                    throw new \RuntimeException('unlocked destructive DDL');
                }
                $this->events[] = 'drop';
                return 0;
            }
            throw new \RuntimeException('unexpected SQL');
        }
        public function fetchOne(string $sql): int {
            if (!$this->locked) {
                throw new \RuntimeException('unlocked emptiness check');
            }
            $this->events[] = 'read';
            return $this->hasRows ? 1 : 0;
        }
    }

    require $argv[1];
    $db = new GuardedDb($argv[2] === '1');
    $migration = new \DoctrineMigrations\Version20261101120000($db);
    $denied = false;
    try {
        $migration->down(new \Doctrine\DBAL\Schema\Schema());
    } catch (\Doctrine\Migrations\Exception\IrreversibleMigration $failure) {
        $denied = true;
    }
    echo json_encode(['denied' => $denied, 'events' => $db->events], JSON_THROW_ON_ERROR);
}
"""


def execute(*actions: dict) -> dict:
    process = subprocess.run(
        [
            "php", "-n", "-d", "allow_url_fopen=0", "-r", PHP_HARNESS,
            str(MODEL), str(STORE), json.dumps(actions),
        ],
        cwd=BASE, capture_output=True, text=True, timeout=20, check=True,
    )
    return json.loads(process.stdout)


def step(action: str, tenant: str = "tenant-a", **values) -> dict:
    return {"action": action, "tenant": tenant, **values}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        actual = execute(
            step("create"), step("create", tenant="tenant-b"),
            step("list"), step("list", tenant="tenant-b"),
            step("read", tenant="tenant-c"), step("read"),
        )["observations"]
        self.assertEqual("tenant-a", actual[2][0]["tenant_ref"])
        self.assertEqual("tenant-b", actual[3][0]["tenant_ref"])
        self.assertEqual(1, len(actual[2]))
        self.assertEqual(1, len(actual[3]))
        self.assertIsNone(actual[4])
        self.assertEqual("tenant-a", actual[5]["tenant_ref"])
        source = STORE.read_text(encoding="utf-8")
        self.assertIn("WHERE tenant_ref = ? AND record_ref = ?", source)
        self.assertIn("FOR UPDATE", source)

    def test_permisos_y_auditoria(self) -> None:
        result = execute(
            step("create"), step("list"), step("read"),
            step("change", state="EN_REVISION"),
        )
        self.assertEqual(
            ["synthetic.created", "synthetic.listed",
             "synthetic.viewed", "synthetic.state_changed"],
            [event["event"] for event in result["events"]],
        )
        denied = execute(
            step("create", allowed=False),
            step("list", allowed=False),
            step("read", allowed=False),
        )
        self.assertEqual([{"denied": True}] * 3, denied["observations"])
        self.assertEqual([], denied["events"])
        wrong = execute(step("create"), step("change", tenant="tenant-b", state="EN_REVISION"))
        self.assertEqual({"denied": True}, wrong["observations"][1])
        self.assertEqual(1, len(wrong["events"]))

        # Audit failure must rollback the record write, not leave orphan state.
        rolled_back = execute(step("create", fail_audit=True), step("list"))
        self.assertEqual({"denied": True}, rolled_back["observations"][0])
        self.assertEqual([], rolled_back["observations"][1])
        self.assertEqual(["synthetic.listed"], [e["event"] for e in rolled_back["events"]])

    def test_sin_datos_reales(self) -> None:
        bad = [
            step("create", tenant="1099887766"),
            step("create", actor="other@company.example"),
            step("create", record="https://example.com/sensitive"),
            step("create", record="fixture:occupational/../secret"),
            step("create", review_due="2026-02-30"),
        ]
        outcome = execute(*bad)
        self.assertEqual([{"denied": True}] * len(bad), outcome["observations"])
        self.assertEqual([], outcome["events"])
        for file in (MODEL, STORE):
            source = file.read_text(encoding="utf-8").lower()
            self.assertNotIn("file_get_contents(", source)
            self.assertNotIn("httpclient", source)
            self.assertNotIn("document_number", source)

    def test_estados_explicitos(self) -> None:
        result = execute(
            step("create"),
            step("change", state="EN_REVISION"),
            step("change", state="CERRADO"),
            step("change", state="CIERRE_REQUIERE_REVISION"),
            step("change", state="CIERRE_REQUIERE_REVISION", evidence="fixture:evidence/review"),
            step("read"),
        )["observations"]
        self.assertEqual("PENDIENTE", result[0]["state"])
        self.assertEqual("EN_REVISION", result[1]["state"])
        self.assertEqual({"denied": True}, result[2])
        self.assertEqual({"denied": True}, result[3])
        self.assertEqual("CIERRE_REQUIERE_REVISION", result[4]["state"])
        self.assertEqual(result[4]["state"], result[5]["state"])
        migration = SCHEMA.read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE condor_occupational_record", migration)
        self.assertIn("CREATE TABLE condor_occupational_audit", migration)
        self.assertIn("PRIMARY KEY (tenant_ref, record_ref)", migration)
        self.assertIn("IrreversibleMigration", migration)
        self.assertIn("if (max($recordCount, $eventCount) > 0)", migration)
        for contains_rows, expected in (
            ("0", ["lock", "read", "read", "drop", "drop", "unlock"]),
            ("1", ["lock", "read", "read", "unlock"]),
        ):
            with self.subTest(contains_rows=contains_rows):
                process = subprocess.run(
                    ["php", "-n", "-r", MIGRATION_HARNESS, str(SCHEMA), contains_rows],
                    cwd=BASE, capture_output=True, text=True, timeout=20, check=True,
                )
                outcome = json.loads(process.stdout)
                self.assertEqual(contains_rows == "1", outcome["denied"])
                self.assertEqual(expected, outcome["events"])


if __name__ == "__main__":
    unittest.main()
