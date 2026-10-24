"""Executable, dependency-free acceptance of the Condor #854 suggestion engine.

Actual PHP arithmetic is exercised, while the HTTP boundary is checked for
branch authorization, tenant filters and read-only routing. Symfony integration
and PHPunit suites separately validate the full application at the CI gate.
"""
from pathlib import Path
import json
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENGINE = ROOT / 'src/Application/Inventory/RestockSuggester.php'
CONTROLLER = ROOT / 'src/Http/Controller/RestockSuggestionsController.php'


def run_engine(*args):
    php = shutil.which('php')
    if php is None:
        raise AssertionError('PHP CLI is required to execute AC-01, not just lint it')
    code = ('require '+json.dumps(str(ENGINE))+'; '
            '$s = new \\App\\Application\\Inventory\\RestockSuggester(); '
            'echo json_encode($s->suggest('+','.join(str(x) for x in args)+'));')
    return subprocess.run([php, '-r', code], text=True, capture_output=True,
                          timeout=15, check=False)


class ResurtidoSugerenciasTests(unittest.TestCase):
    def test_acceptance(self):
        result = run_engine(90, 15, 3)
        self.assertEqual(0, result.returncode, result.stderr)
        value = json.loads(result.stdout)
        self.assertEqual(90, value['consumed_30d'])
        self.assertEqual(3, value['average_daily'])
        self.assertEqual(4, value['coverage_days'])
        self.assertEqual(78, value['suggested_quantity'])
        self.assertEqual('null', run_engine(0, 8, 0).stdout)
        self.assertEqual('null', run_engine(30, 35, 0).stdout)

        controller = CONTROLLER.read_text(encoding='utf-8')
        self.assertIn("'/api/inventory/restock-suggestions'", controller)
        self.assertIn("methods: ['GET']", controller)
        self.assertIn("authorizedBranchScope($query['branch_id'], 'inventory.view')", controller)
        self.assertIn("'tenant' => $tenant", controller)
        self.assertIn("'source' => $source", controller)
        self.assertIn('m.tenant = :tenant', controller)
        self.assertIn('m.source = :source', controller)
        self.assertIn('InventoryMovement::TYPE_ORDER_CONSUMPTION', controller)
        self.assertIn('m.createdAt >= :since', controller)
        self.assertIn('no-store', controller)
        self.assertNotIn('methods: [\'POST\']', controller)

    def test_negative_cases(self):
        result = run_engine(-1, 10, 0)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('fuera de rango', result.stderr)
        result = run_engine(30, -10, 5)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(30, json.loads(result.stdout)['suggested_quantity'])


if __name__ == '__main__':
    unittest.main()
