#!/usr/bin/env python3
"""#1670: real PHP/DBAL-simulated M10 persistence and disabled HTTP behavior.

Only fabricated tenant ULIDs and opaque asset references; no customer DB, DNS
or network. Executes the real PHP entity, service and controller code.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Assets/Entity/AssetRecord.php"
SERVICE = ROOT / "src/Application/Assets/AssetRecordService.php"
CONTROLLER = ROOT / "src/Http/Controller/AssetsController.php"
MIGRATION = ROOT / "migrations/Version20261106139000.php"

DBAL_FAKE = r"""<?php
declare(strict_types=1);
namespace Doctrine\DBAL\Exception {
    class UniqueConstraintViolationException extends \RuntimeException {}
}
namespace Doctrine\DBAL {
    class Connection {
        public array $rows = [];
        public function transactional(callable $run): mixed { return $run(); }
        public function insert(string $table, array $row): int {
            if ($table !== 'condor_asset_real_record') throw new \DomainException('table');
            foreach ($this->rows as $existing) {
                if ($existing['tenant_id'] === $row['tenant_id']
                    && $existing['company_id'] === $row['company_id']
                    && $existing['record_ref'] === $row['record_ref']
                    && $existing['version'] === $row['version']) {
                    throw new \Doctrine\DBAL\Exception\UniqueConstraintViolationException();
                }
            }
            $this->rows[] = $row;
            return 1;
        }
        private function latest(array $args): array {
            $found = [];
            foreach ($this->rows as $row) {
                if ($row['tenant_id'] !== $args['tenant']
                    || $row['company_id'] !== $args['company']
                    || (isset($args['reference']) && $row['record_ref'] !== $args['reference'])
                    || (isset($args['cursor']) && strcmp($row['record_ref'], $args['cursor']) <= 0)) {
                    continue;
                }
                $ref = $row['record_ref'];
                if (!isset($found[$ref]) || $row['version'] > $found[$ref]['version']) {
                    $found[$ref] = $row;
                }
            }
            ksort($found);
            return array_values($found);
        }
        public function fetchAssociative(string $sql, array $args = []): array|false {
            $rows = $this->latest($args);
            return $rows === [] ? false : $rows[0];
        }
        public function fetchAllAssociative(string $sql, array $args = []): array {
            return array_slice($this->latest($args), 0, 101);
        }
    }
}
namespace App\Shared\Id {
    class UlidFactory {
        public static function new(): string {
            static $i = 0;
            return str_pad(strtoupper(dechex(++$i)), 26, '0', STR_PAD_LEFT);
        }
    }
}
namespace {
require $argv[1];
require $argv[2];
use App\Application\Assets\AssetRecordService;
use Doctrine\DBAL\Connection;
function refused(callable $call): bool {
    try { $call(); return false; } catch (\DomainException $e) { return true; }
}
function ref(string $letter): string { return '0'.str_repeat($letter, 25); }
$db = new Connection();
$service = new AssetRecordService($db);
$t1 = ref('A'); $t2 = ref('D'); $company1 = ref('B'); $company2 = ref('E');
$actor = ref('C');
$a1 = $service->add($t1, $company1, $actor, ['record_ref'=>'laptop_a', 'category'=>'EQUIPO']);
$a2 = $service->add($t1, $company2, $actor, ['record_ref'=>'laptop_a', 'category'=>'MUEBLE']);
$a3 = $service->add($t2, $company1, $actor, ['record_ref'=>'laptop_a', 'category'=>'VEHICULO']);
$assigned = $service->transition($t1, $company1, $actor, 'laptop_a', 'ASIGNADO', 1, 'worker_a');
$oldVersionRejected = refused(fn () => $service->transition(
    $t1, $company1, $actor, 'laptop_a', 'EN_REVISION', 1
));
$badTransitionRejected = refused(fn () => $service->transition(
    $t1, $company1, $actor, 'laptop_a', 'PENDIENTE', 2
));
$evidenceRequired = refused(fn () => $service->transition(
    $t1, $company1, $actor, 'laptop_a', 'BAJA_REQUIERE_REVISION', 2
));
$decommission = $service->transition(
    $t1, $company1, $actor, 'laptop_a', 'BAJA_REQUIERE_REVISION', 2, null, 'evidence_a'
);
$otherCompany = $service->one($t1, $company2, 'laptop_a');
$otherTenant = $service->one($t2, $company1, 'laptop_a');
$wrongScope = $service->one($t2, $company2, 'laptop_a') === null;
$forgedRefRejected = refused(fn () => $service->add(
    $t1, $company1, $actor, ['record_ref'=>'person_12345678', 'category'=>'EQUIPO']
));
$clientScopeRejected = refused(fn () => $service->transition(
    $t2, $company2, $actor, 'laptop_a', 'EN_REVISION', 1
));
$duplicateRejected = refused(fn () => $service->add(
    $t1, $company1, $actor, ['record_ref'=>'laptop_a', 'category'=>'EQUIPO']
));
$badCategoryRejected = refused(fn () => $service->add(
    $t1, $company1, $actor, ['record_ref'=>'other_a', 'category'=>'UNKNOWN']
));
$page = $service->page($t1, $company1);
$cursorRejected = refused(fn () => $service->page($t1, $company1, 'person_12345678'));
echo json_encode([
    'created' => $a1['version']===1 && $a1['state']==='PENDIENTE',
    'same_ref_separated' => $a1['company_id']!==$a2['company_id']
        && $a1['tenant_id']!==$a3['tenant_id']
        && $otherCompany['category']==='MUEBLE' && $otherTenant['category']==='VEHICULO',
    'assigned' => $assigned['state']==='ASIGNADO'
        && $assigned['responsible_ref']==='worker_a' && $assigned['actor_id']===$actor,
    'revisions' => $decommission['version']===3 && $decommission['previous_id']===$assigned['id']
        && $decommission['evidence_ref']==='evidence_a'
        && $decommission['state']==='BAJA_REQUIERE_REVISION',
    'history' => count($db->rows)===5,
    'scope' => $wrongScope && $clientScopeRejected && count($page['records'])===1
        && $page['records'][0]['version']===3 && $page['next_cursor']===null,
    'reject' => $oldVersionRejected && $badTransitionRejected && $evidenceRequired
        && $forgedRefRejected && $duplicateRejected && $badCategoryRejected && $cursorRejected,
], JSON_THROW_ON_ERROR);
}
"""

OFF_FAKE = r"""<?php
declare(strict_types=1);
namespace Symfony\Component\HttpFoundation {
    class HeaderBag {
        public array $headers = [];
        public function set(string $key, string $value): void { $this->headers[$key] = $value; }
    }
    class JsonResponse {
        public HeaderBag $headers;
        public function __construct(public array $data, public int $status) {
            $this->headers = new HeaderBag();
        }
    }
    class Response {
        public const HTTP_OK = 200;
        public const HTTP_CREATED = 201;
        public const HTTP_NOT_FOUND = 404;
    }
    class Request {}
}
namespace Symfony\Bundle\FrameworkBundle\Controller {
    abstract class AbstractController {
        protected function json(array $data, int $status = 200): \Symfony\Component\HttpFoundation\JsonResponse {
            return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status);
        }
    }
}
namespace App\Http\Controller { trait BranchApiSupport {} }
namespace {
require $argv[1];
$class = new \ReflectionClass(\App\Http\Controller\AssetsController::class);
$controller = $class->newInstanceWithoutConstructor();
putenv('ASSETS_REAL_DATA_ENABLED');
$routes = [
    $controller->assetsRealList('branch', new \Symfony\Component\HttpFoundation\Request()),
    $controller->assetsRealView('branch', 'test_a'),
    $controller->assetsRealCreate('branch', new \Symfony\Component\HttpFoundation\Request()),
    $controller->assetsRealState('branch', 'test_a', new \Symfony\Component\HttpFoundation\Request()),
];
echo json_encode([
    'off' => count(array_filter($routes, static fn ($r): bool =>
        $r->status === 404 && $r->data === ['error'=>'feature_unavailable']
        && $r->headers->headers['Cache-Control'] === 'private, no-store'))===4,
    'routes' => count($routes),
], JSON_THROW_ON_ERROR);
}
"""


def real_php(script: str, *paths: Path) -> dict:
    executable = shutil.which("php")
    if not executable:
        raise AssertionError("PHP 8.5 es requisito de aceptación.")
    with tempfile.TemporaryDirectory(prefix="condor1670-") as folder:
        php_file = Path(folder) / "fixture.php"
        php_file.write_text(script, encoding="utf-8")
        completed = subprocess.run(
            [executable, "-n", str(php_file), *(str(p) for p in paths)],
            cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
        )
    if completed.returncode:
        raise AssertionError(completed.stdout + completed.stderr)
    return json.loads(completed.stdout)


class AssetRecordPersistenceTests(unittest.TestCase):
    def test_tenant_scope_transitions_and_audit(self) -> None:
        results = real_php(DBAL_FAKE, ENTITY, SERVICE)
        for key, value in results.items():
            self.assertIs(value, True, f"Persistencia y auditoría: {key}={value}")

    def test_flag_off_prevents_real_endpoints(self) -> None:
        results = real_php(OFF_FAKE, CONTROLLER)
        self.assertIs(results["off"], True)
        self.assertEqual(results["routes"], 4)
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("getenv('ASSETS_REAL_DATA_ENABLED') === 'true'", controller)
        self.assertIn("requireCsrf(request: $request)", controller)
        self.assertIn("Membership::ROLE_OWNER", controller)
        self.assertIn("authorizedBranchScope($branchId, $permission)", controller)
        self.assertIn("legalEntity?->tenant()->id() !== $tenant->id()", controller)
        self.assertIn("Cache-Control', 'private, no-store", controller)

    def test_schema_additive_and_immutable(self) -> None:
        text = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE condor_asset_real_record", text)
        self.assertIn("UNIQUE KEY uq_asset_real_scope_ref_version (tenant_id, company_id, record_ref, version)", text)
        self.assertIn("FOREIGN KEY (tenant_id, company_id)", text)
        self.assertIn("ON DELETE RESTRICT", text)
        self.assertIn("IrreversibleMigration(", text)
        self.assertNotIn("DROP TABLE", text)
        self.assertNotIn("DELETE FROM", text)

if __name__ == "__main__":
    unittest.main()
