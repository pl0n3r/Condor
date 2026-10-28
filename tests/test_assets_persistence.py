"""M10 assets persistence: PHP runtime, tenant scopes, RBAC, audits and reversible schema."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Assets/Entity/AssetRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/AssetRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261105120000.php"

# A DBAL stand-in exposes SQL, transactions and row isolation in the PHP
# runtime. The normal CI Backend PHP/MariaDB job validates installed drivers.
FAKE_DBAL = r"""
namespace Doctrine\DBAL {
    class Connection {
        public array $records = [];
        public array $audits = [];
        public function transactional(callable $run): mixed { return $run(); }
        public function insert(string $table, array $row): void {
            if ($table === 'condor_asset_audit') { $this->audits[] = $row; return; }
            if ($table !== 'condor_asset_record') { throw new \DomainException('bad table'); }
            $key = $row['tenant_ref'].'|'.$row['asset_ref'];
            if (isset($this->records[$key])) { throw new \DomainException('duplicate'); }
            $this->records[$key] = $row;
        }
        public function fetchAllAssociative(string $sql, array $params): array {
            if (!str_contains($sql, 'WHERE tenant_ref = ?')) { throw new \DomainException('unscoped'); }
            $rows = array_values(array_filter($this->records,
                static fn(array $v): bool => $v['tenant_ref'] === $params[0]));
            usort($rows, static fn(array $a, array $b): int => strcmp($a['asset_ref'], $b['asset_ref']));
            return $rows;
        }
        public function fetchAssociative(string $sql, array $params): array|false {
            if (!str_contains($sql, 'WHERE tenant_ref = ? AND asset_ref = ?')) {
                throw new \DomainException('unscoped');
            }
            return $this->records[$params[0].'|'.$params[1]] ?? false;
        }
        public function update(string $table, array $fields, array $keys): int {
            if ($table !== 'condor_asset_record' || !isset($keys['tenant_ref'], $keys['asset_ref'])) {
                throw new \DomainException('unscoped write');
            }
            $id = $keys['tenant_ref'].'|'.$keys['asset_ref'];
            if (!isset($this->records[$id])) { return 0; }
            $this->records[$id] = array_replace($this->records[$id], $fields);
            return 1;
        }
    }
}
"""


def execute(ops):
    script = FAKE_DBAL + r"""
namespace {
require $argv[1];
require $argv[2];
use App\Domain\Assets\Entity\AssetRecord;
use App\Infrastructure\Persistence\AssetRecordRepository;
$db = new \Doctrine\DBAL\Connection();
$repository = new AssetRecordRepository($db);
$ops = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
$out = [];
foreach ($ops as $op) {
    try {
        $tenant = $op['tenant'] ?? 'tenant-a';
        $actor = $op['actor'] ?? 'fixture:actor/tester';
        $allowed = $op['authorized'] ?? true;
        $asset = $op['asset'] ?? 'fixture:asset/laptop';
        switch ($op['method']) {
            case 'POST':
                $record = new AssetRecord($tenant, $asset, $actor, $allowed);
                $out[] = $repository->create($record, $tenant, $actor, $allowed);
                break;
            case 'GET':
                $out[] = $repository->forTenant($tenant, $actor, $allowed);
                break;
            case 'VIEW':
                $out[] = $repository->view($tenant, $asset, $actor, $allowed);
                break;
            case 'PATCH':
                $out[] = $repository->transition(
                    $tenant, $asset, $actor, $allowed, $op['state'], $op['evidence'] ?? null,
                );
                break;
        }
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
$out[] = ['audit' => $db->audits];
echo json_encode($out, JSON_THROW_ON_ERROR);
}
"""
    output = subprocess.check_output(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script,
         str(ENTITY), str(REPOSITORY), json.dumps(ops)],
        cwd=ROOT, stderr=subprocess.PIPE, text=True, timeout=20,
    )
    return json.loads(output)


def op(method, tenant="tenant-a", asset="fixture:asset/laptop", **kwargs):
    return {"method": method, "tenant": tenant, "asset": asset, **kwargs}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        result = execute([
            op("POST"), op("POST", "tenant-b"), op("GET"),
            op("GET", "tenant-b"), op("GET", "tenant-c"),
            op("VIEW", "tenant-c"), op("VIEW"),
        ])
        self.assertEqual("tenant-a", result[2][0]["tenant_ref"])
        self.assertEqual("tenant-b", result[3][0]["tenant_ref"])
        self.assertEqual([], result[4])
        self.assertIsNone(result[5])
        self.assertEqual("tenant-a", result[6]["tenant_ref"])
        self.assertEqual(2, len(result[-1]["audit"][:2]))
        code = REPOSITORY.read_text(encoding="utf-8")
        self.assertIn("WHERE tenant_ref = ? AND asset_ref = ?", code)
        self.assertIn("FOR UPDATE", code)

    def test_permisos_y_auditoria(self):
        actions = [op("POST"), op("GET"), op("VIEW"),
                   op("PATCH", state="EN_REVISION")]
        result = execute(actions)
        self.assertEqual(["synthetic.created", "synthetic.listed",
                          "synthetic.viewed", "synthetic.state_changed"],
                         [entry["event"] for entry in result[-1]["audit"]])
        self.assertEqual("EN_REVISION", result[-2]["state"])
        denied = execute([op("POST", authorized=False), op("GET", authorized=False)])
        self.assertEqual([{"error": "denied"}, {"error": "denied"}], denied[:-1])
        self.assertEqual([], denied[-1]["audit"])
        denied_other = execute([op("POST"), op("PATCH", "tenant-b", state="EN_REVISION")])
        self.assertEqual({"error": "denied"}, denied_other[1])
        self.assertEqual(1, len(denied_other[-1]["audit"]))
        self.assertIn("transactional", REPOSITORY.read_text(encoding="utf-8"))

    def test_sin_datos_reales(self):
        for field, value in (
            ("tenant", "1099887766"), ("asset", "someone@example.com"),
            ("asset", "https://example.com/doc"), ("actor", "3005551212"),
        ):
            with self.subTest(field=field):
                self.assertEqual({"error": "denied"}, execute([op("POST", **{field: value})])[0])
        for path in (ENTITY, REPOSITORY):
            content = path.read_text(encoding="utf-8").lower()
            for forbidden in ("curl_", "file_get_contents(", "httpclient",
                              "email", "document_number"):
                self.assertNotIn(forbidden, content)

    def test_estados_explicitos(self):
        result = execute([
            op("POST"),
            op("PATCH", state="EN_REVISION"),
            op("PATCH", state="CERRADO"),
            op("PATCH", state="BAJA_REQUIERE_REVISION"),
            op("PATCH", state="BAJA_REQUIERE_REVISION", evidence="fixture:evidence/acta"),
            op("VIEW"),
        ])
        self.assertEqual("PENDIENTE", result[0]["state"])
        self.assertEqual("EN_REVISION", result[1]["state"])
        self.assertEqual({"error": "denied"}, result[2])
        self.assertEqual({"error": "denied"}, result[3])
        self.assertEqual("BAJA_REQUIERE_REVISION", result[4]["state"])
        self.assertEqual("BAJA_REQUIERE_REVISION", result[5]["state"])
        sql = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE condor_asset_record", sql)
        self.assertIn("CREATE TABLE condor_asset_audit", sql)
        self.assertIn("PRIMARY KEY (tenant_ref, asset_ref)", sql)
        self.assertIn("if ($auditCount !== 0 || $recordCount !== 0)", sql)
        self.assertIn("DROP TABLE", sql)
        self.assertIn("IrreversibleMigration", sql)


if __name__ == "__main__":
    unittest.main()
