#!/usr/bin/env python3
"""Acceptance #851: actual PHP tenant-bound order filtering and pagination."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Orders/OrderListQuery.php"
CONTROLLER = ROOT / "src/Http/Controller/OrderController.php"
PHP_TEST = ROOT / "tests/php/Application/Orders/OrderListQueryTest.php"

PHP_FILTER_SCENARIO = r'''
require 'src/Domain/Orders/Entity/Order.php';
require 'src/Application/Orders/OrderListQuery.php';
$query = new App\Application\Orders\OrderListQuery();
$method = (new ReflectionClass($query))->getMethod('filters');
$good = $method->invoke($query, [
    'page'=>'2', 'per_page'=>'10', 'status'=>'open',
    'q'=>'camila', 'from'=>'2026-01-01', 'to'=>'2026-01-02'
]);
$rejected = [];
foreach ([
    ['page'=>'0'], ['per_page'=>'101'], ['from'=>'2026-02-30'],
    ['tenant_id'=>'other'], ['q'=>'%']
] as $bad) {
    try { $method->invoke($query, $bad); $rejected[] = 'NOT_REJECTED'; }
    catch (DomainException $error) { $rejected[] = $error->getMessage(); }
}
echo json_encode([
    'ok'=>[$good['page'], $good['per_page'], $good['status'],
        $good['from']->format(DATE_ATOM), $good['to']->format(DATE_ATOM)],
    'rejected'=>$rejected
], JSON_THROW_ON_ERROR);
'''


class PedidosFiltrosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio")
        for filename in (SERVICE, CONTROLLER, PHP_TEST):
            self.assertTrue(filename.is_file(), str(filename))
            lint = subprocess.run([php, "-l", str(filename)], cwd=ROOT,
                                  text=True, capture_output=True, check=False)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        controller = CONTROLLER.read_text(encoding="utf-8")
        for required in (
            "OrderListQuery $query", "Request $request", "'orders.view'",
            "authorizedBranchScope(", "accessibleSources(", "$query->fetch(",
            "'pagination' => $listed['pagination']",
        ):
            with self.subTest(required=required):
                self.assertIn(required, controller)

        service = SERVICE.read_text(encoding="utf-8")
        for required in (
            "'o.tenant = :tenant'", "'o.legalEntity = :legalEntity'",
            "'o.inventorySource IN (:sources)'", "COUNT(o.id)",
            "addOrderBy('o.id', \\SortDirection::Descending)", "setMaxResults($perPage)",
        ):
            with self.subTest(required=required):
                self.assertIn(required, service)
        # Backend PHP/MariaDB runs the real Doctrine/PHPUnit integration suite.
        # The contract job intentionally installs PHP only, not vendor/ or MariaDB.
        # Exercise the real PHP filter parser there instead of pretending PHPUnit ran.
        runner = ROOT / "vendor/bin/simple-phpunit"
        if runner.is_file():
            result = subprocess.run(
                [str(runner), "--filter", "testTenantFiltersAndStablePagination", str(PHP_TEST)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        else:
            result = subprocess.run(
                [php, "-r", PHP_FILTER_SCENARIO], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=30,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            observed = json.loads(result.stdout)
            self.assertEqual([2, 10, "open", "2026-01-01T05:00:00+00:00",
                              "2026-01-03T05:00:00+00:00"], observed["ok"])
            self.assertEqual(5, len(observed["rejected"]))
            self.assertTrue(all(reason.startswith("ORDER_LIST_") for reason in observed["rejected"]))


if __name__ == "__main__":
    unittest.main()
