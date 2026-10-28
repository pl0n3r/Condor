#!/usr/bin/env python3
"""Condor #1088: real PHP/PDO repository contract with synthetic memory driver."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Contracts/Entity/ContractRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/ContractRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261206120000.php"

PHP_DRIVER = r'''
require $argv[1];
require $argv[2];

final class SyntheticStore implements \App\Infrastructure\Persistence\ContractRecordStorage {
    public array $rows = [];

    public function executeStatement(string $sql, array $params): void {
        if (!str_contains($sql, "INSERT INTO condor_contract_synthetic_record")) {
            throw new \RuntimeException("invalid_statement");
        }
        $row = [];
        foreach ($params as $key => $value) {
            $row[ltrim($key, ":")] = $value;
        }
        foreach ($this->rows as $existing) {
            if ($existing["id"] === $row["id"]
                || ($existing["tenant_ref"] === $row["tenant_ref"]
                    && $existing["company_ref"] === $row["company_ref"]
                    && $existing["contract_ref"] === $row["contract_ref"]
                    && $existing["version"] === $row["version"])) {
                throw new \RuntimeException("duplicate_record");
            }
        }
        $row["created_at"] = "2026-01-01 00:00:00";
        $this->rows[] = $row;
    }

    public function selectRows(string $sql, array $params): array {
        if (!str_contains($sql, "tenant_ref = :tenant_ref")
            || !str_contains($sql, "company_ref = :company_ref")) {
            throw new \RuntimeException("missing_scope");
        }
        return array_values(array_filter($this->rows,
            static fn (array $row): bool =>
                $row["tenant_ref"] === $params[":tenant_ref"]
                && $row["company_ref"] === $params[":company_ref"]));
    }
}

$store = new SyntheticStore();
$repository = new \App\Infrastructure\Persistence\ContractRecordRepository($store);
$steps = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
$results = [];
foreach ($steps as $step) {
    try {
        if ($step["op"] === "append") {
            $record = \App\Domain\Contracts\Entity\ContractRecord::fromInput($step["record"]);
            $row = $repository->append(
                $record, $step["actor_tenant"], $step["actor_company"], $step["role"]
            );
            $results[] = [
                "ok" => true, "state" => $row["state"],
                "audit_action" => $row["audit_action"], "id" => $row["id"],
            ];
        } elseif ($step["op"] === "list") {
            $rows = $repository->listByScope(
                $step["tenant"], $step["company"], $step["actor_tenant"],
                $step["actor_company"], $step["role"]
            );
            $results[] = ["ok" => true, "rows" => $rows];
        } else {
            throw new \InvalidArgumentException("unsupported_operation");
        }
    } catch (\Throwable $exception) {
        $results[] = ["ok" => false, "error" => "rejected"];
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR);
'''


def fixture(tenant="tenant-a", company="fixture:company/example-a"):
    return {
        "tenant_ref": tenant,
        "company_ref": company,
        "contract_ref": "fixture:contract/sample-a",
        "worker_ref": "fixture:worker/sample-a",
        "template_ref": "fixture:template/base-a",
        "actor_ref": "fixture:actor/owner-a",
        "state": "REQUIERE_REVISION",
        "version": 1,
        "audit_action": "CREATED",
    }


def append(record, role="owner", tenant=None, company=None):
    return {
        "op": "append", "record": record,
        "actor_tenant": tenant or record["tenant_ref"],
        "actor_company": company or record["company_ref"],
        "role": role,
    }


def listing(tenant, company, role="reader", actor_tenant=None, actor_company=None):
    return {
        "op": "list", "tenant": tenant, "company": company,
        "actor_tenant": actor_tenant or tenant,
        "actor_company": actor_company or company, "role": role,
    }


def run(steps):
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", PHP_DRIVER,
         str(ENTITY), str(REPOSITORY), json.dumps(steps)],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(result.stderr)
    return json.loads(result.stdout)


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        a = fixture()
        b = fixture("tenant-b", "fixture:company/example-b")
        rows = run([
            append(a), append(b, role="editor"),
            listing(a["tenant_ref"], a["company_ref"]),
            listing(b["tenant_ref"], b["company_ref"]),
            listing(b["tenant_ref"], b["company_ref"],
                    actor_tenant=a["tenant_ref"], actor_company=a["company_ref"]),
        ])
        self.assertTrue(rows[0]["ok"])
        self.assertTrue(rows[1]["ok"])
        self.assertEqual(1, len(rows[2]["rows"]))
        self.assertEqual(1, len(rows[3]["rows"]))
        self.assertEqual(a["company_ref"], rows[2]["rows"][0]["company_ref"])
        self.assertEqual(b["company_ref"], rows[3]["rows"][0]["company_ref"])
        self.assertFalse(rows[4]["ok"])

    def test_permisos_y_auditoria(self):
        a = fixture()
        revised = {**a, "version": 2, "audit_action": "REVISED"}
        result = run([
            append(a, role="reader"),
            append(a, role="owner"),
            append(revised, role="editor"),
            listing(a["tenant_ref"], a["company_ref"], role="unknown"),
            listing(a["tenant_ref"], a["company_ref"]),
        ])
        self.assertFalse(result[0]["ok"])
        self.assertTrue(result[1]["ok"])
        self.assertTrue(result[2]["ok"])
        self.assertFalse(result[3]["ok"])
        self.assertEqual(
            ["CREATED", "REVISED"],
            [row["audit_action"] for row in result[4]["rows"]],
        )
        for row in result[4]["rows"]:
            self.assertEqual("REQUIERE_REVISION", row["state"])
            self.assertTrue(row["actor_ref"].startswith("fixture:actor/"))
            self.assertEqual(64, len(row["fingerprint"]))

    def test_sin_datos_reales(self):
        a = fixture()
        variants = []
        for key, value in (
            ("worker_ref", "person@example.com"),
            ("worker_ref", "3115551234"),
            ("worker_ref", "1020304050"),
            ("actor_ref", "https://example.com/person"),
            ("company_ref", "900123456-1"),
            ("tenant_ref", "tenant-a@example.org"),
        ):
            bad = copy.deepcopy(a)
            bad[key] = value
            variants.append(bad)
        variants.append({**a, "email": "someone@example.net"})
        variants.append({**a, "name": "Real Person"})
        variants.append({**a, "approved": True})
        results = run([append(value) for value in variants])
        self.assertTrue(all(not result["ok"] for result in results))
        self.assertNotIn("example.net", json.dumps(results))

    def test_estados_explicitos(self):
        a = fixture()
        invalid = [
            {**a, "state": "APROBADO"},
            {**a, "state": "CERRADO"},
            {**a, "state": "FIRMADO"},
            {**a, "audit_action": "REVISED", "version": 1},
            {**a, "audit_action": "CREATED", "version": 2},
            {**a, "version": 0},
        ]
        response = run([append(a)] + [append(x) for x in invalid] + [append(a)])
        self.assertTrue(response[0]["ok"])
        self.assertTrue(all(not item["ok"] for item in response[1:]))
        source = REPOSITORY.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("WHERE tenant_ref = :tenant_ref AND company_ref = :company_ref", source)
        self.assertIn("UNIQUE INDEX uniq_contract_synthetic_scope_version", migration)
        self.assertIn("IrreversibleMigration", migration)
        self.assertNotIn("UPDATE condor_contract_synthetic_record", source)
        self.assertNotIn("DELETE FROM condor_contract_synthetic_record", source)
        self.assertIn("REQUIERE_REVISION", ENTITY.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
