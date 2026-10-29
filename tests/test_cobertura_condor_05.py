#!/usr/bin/env python3
"""#1324: regresiones offline sobre código PHP real; no acredita producción."""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_FILES = (
    "src/Domain/Orders/OrderStateMachine.php",
    "src/Application/Contracts/ContractApiService.php",
    "src/Application/Inventory/StockCsvAdjuster.php",
    "src/Application/Storefront/PublicCatalogSearch.php",
    "src/Application/Seo/TagManagerSettings.php",
    "src/Shared/Version/AppVersion.php",
)
TESTABLE = frozenset((
    "D-030-S04", "D-031", "D-032", "D-033",
    "D-034", "D-035", "D-037", "D-039",
))
NON_OFFLINE = {
    "D-030-S02": ("C", "Mapa y orden editorial de dominios: sin operación aislada"),
    "D-030-S03": ("C", "Terminología documental: no define una operación aislada"),
    "D-036": ("B", "Calidad operativa integral exige ejecución y evidencia externa exact-SHA"),
    "D-040": ("B", "Baseline integral exige integración, infraestructura y evidencia externa"),
}
IDS = TESTABLE | NON_OFFLINE.keys()

RUNTIME = r'''
// Ejecuta las clases reales. En PHP sin mbstring, el shim sólo admite fixtures ASCII.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $input, string $encoding = 'UTF-8'): int {
        return strlen($input);
    }
}
foreach (json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR) as $path) {
    require $argv[1].'/'.$path;
}
$records = [];
$settings = [];
$gtm = new \App\Application\Seo\TagManagerSettings(
    static function (string $tenant, string $channel) use (&$settings) {
        return $settings[$tenant][$channel] ?? null;
    },
    static function (string $tenant, string $channel, array $record) use (&$settings) {
        $settings[$tenant][$channel] = $record;
    },
);
$machine = new \App\Domain\Orders\OrderStateMachine();
$version = new \App\Shared\Version\AppVersion($argv[1]);
$out = [];
foreach (json_decode($argv[3], true, 64, JSON_THROW_ON_ERROR) as $item) {
    try {
        $scope = hash('sha256', ($item['tenant'] ?? 'tenant-a').'|'.($item['company'] ?? 'company-a'));
        $out[] = match ($item['op']) {
            'order-label' => $machine->visibleName('order', 'open', ['order' => ['open' => $item['label']]]),
            'order-transition' => (static function () use ($machine, $item) {
                $state = $machine->initial('tenant-a', 100);
                $machine->assertTransition($state, 'order', $item['target']);
                return 'allowed';
            })(),
            'contract-create' => (static function () use (&$records, $scope, $item) {
                $result = \App\Application\Contracts\ContractApiService::createRecord(
                    $records, $scope, $item['payload']
                );
                $records = $result['store'];
                return $result['record'];
            })(),
            'contract-list' => \App\Application\Contracts\ContractApiService::listRecords($records, $scope),
            'contract-state' => (static function () use (&$records, $scope, $item) {
                $result = \App\Application\Contracts\ContractApiService::changeState(
                    $records, $scope, $item['ref'], $item['state']
                );
                $records = $result['store'];
                return $result['record'];
            })(),
            'stock-parse' => \App\Application\Inventory\StockCsvAdjuster::parseCsv($item['csv']),
            'stock-key' => \App\Application\Inventory\StockCsvAdjuster::rowKey(
                $item['tenant'], $item['sha'], $item['line']
            ),
            'catalog-search' => (static function () use ($item) {
                $query = \App\Application\Storefront\PublicCatalogSearch::fromQuery($item['query']);
                return ['page' => $query->page(), 'category' => $query->categorySlug(),
                    'href' => $query->pageHref(3)];
            })(),
            'gtm-configure' => $gtm->configure($item['tenant'], 'public', $item['container']),
            'gtm-enable' => (static function () use ($gtm, $item) {
                $gtm->setEnabled($item['tenant'], 'public', true);
                return $gtm->snapshot($item['tenant'], 'public');
            })(),
            'gtm-render' => $gtm->renderPublicTag(
                $item['tenant'], 'public',
                $item['surface'] ?? false, $item['consent'] ?? false,
                $item['csp'] ?? false,
            ),
            'version' => ['human' => $version->human(), 'sha' => $version->releaseSha()],
        };
    } catch (\DomainException|\InvalidArgumentException $exception) {
        $out[] = ['error' => 'denied'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
'''


def execute(operations: list[dict]) -> list:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI requerido: no se admite cobertura simulada")
    missing = [path for path in PHP_FILES if not (ROOT / path).is_file()]
    if missing:
        raise AssertionError(f"No existe código real para ejecutar: {missing}")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", RUNTIME,
         str(ROOT), json.dumps(PHP_FILES), json.dumps(operations)],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class CoberturaCondor05Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: ocho decisiones invocan código PHP real, no estados de la matriz."""
        self.assertEqual(12, len(IDS))
        self.assertEqual(8, len(TESTABLE))
        sku_csv = "sku,branch_id,quantity\nsku-a," + "A" * 26 + ",7\n"
        ops = [
            {"op": "order-label", "label": "Abierto en oficina"},
            {"op": "order-transition", "target": "cancelled"},
            {"op": "contract-create", "payload": {
                "kind": "contract", "ref": "fixture:contract/sample"}},
            {"op": "contract-list", "company": "other"},
            {"op": "contract-list"},
            {"op": "stock-parse", "csv": sku_csv},
            {"op": "catalog-search", "query": {
                "q": "pantalon", "categoria": "ropa", "pagina": "2"}},
            {"op": "gtm-configure", "tenant": "tenant-a", "container": "gtm-TEST1"},
            {"op": "gtm-render", "tenant": "tenant-a", "surface": True},
            {"op": "gtm-enable", "tenant": "tenant-a"},
            {"op": "gtm-render", "tenant": "tenant-a", "surface": True,
             "consent": True, "csp": True},
            {"op": "version"},
        ]
        result = execute(ops)
        self.assertEqual("Abierto en oficina", result[0])
        self.assertEqual("allowed", result[1])
        self.assertTrue(result[2]["synthetic_only"])
        self.assertEqual([], result[3])
        self.assertEqual([result[2]], result[4])
        self.assertEqual("SKU-A", result[5]["rows"][0]["sku"])
        self.assertEqual(7, result[5]["rows"][0]["quantity"])
        self.assertEqual([], result[5]["errors"])
        self.assertEqual(2, result[6]["page"])
        self.assertEqual("ropa", result[6]["category"])
        self.assertIn("pagina=3", result[6]["href"])
        self.assertFalse(result[7]["enabled"])
        self.assertEqual("", result[8])
        self.assertTrue(result[9]["enabled"])
        self.assertIn('data-gtm-container="GTM-TEST1"', result[10])
        self.assertRegex(result[11]["human"], r"^\d+\.\d+\.\d+$")
        self.assertRegex(result[11]["sha"], r"^(?:[0-9a-f]{40}|dev)$")

    def test_falla_cerrado(self) -> None:
        """AC-02: invalidación, tenancy, permisos e integraciones sin consentimiento."""
        csv = "sku,branch_id,quantity\nSKU-A," + "A" * 26 + ",-1\n"
        operations = [
            {"op": "order-label", "label": "<script>"},
            {"op": "order-transition", "target": "paid"},
            {"op": "contract-create", "payload": {
                "kind": "contract", "ref": "fixture:contract/first"}},
            {"op": "contract-create", "payload": {
                "kind": "contract", "ref": "fixture:contract/first"}},
            {"op": "contract-state", "ref": "fixture:contract/first", "state": "APROBADO"},
            {"op": "contract-list", "tenant": "other"},
            {"op": "stock-parse", "csv": csv},
            {"op": "stock-key", "tenant": "tenant-a", "sha": "invalid", "line": 2},
            {"op": "catalog-search", "query": {"pagina": "0"}},
            {"op": "catalog-search", "query": {"categoria": "../private"}},
            {"op": "gtm-configure", "tenant": "tenant-a", "container": "evil<script>"},
            {"op": "gtm-render", "tenant": "other", "surface": True,
             "consent": True, "csp": True},
        ]
        result = execute(operations)
        for idx in (0, 1, 3, 4, 7, 8, 9, 10):
            with self.subTest(index=idx):
                self.assertEqual({"error": "denied"}, result[idx])
        self.assertTrue(result[2]["synthetic_only"])
        self.assertEqual([], result[5])
        self.assertEqual([], result[6]["rows"])
        self.assertEqual("CSV_ROW_INVALID", result[6]["errors"][0]["error"])
        self.assertEqual("", result[11])

    def test_sin_datos_reales(self) -> None:
        """AC-03: sólo fixtures falsas y rechazo de referencias sensibles."""
        result = execute([
            {"op": "contract-create", "payload": {
                "kind": "contract", "ref": "persona@example.com"}},
            {"op": "contract-create", "payload": {
                "kind": "contract", "ref": "fixture:contract/3001234567"}},
            {"op": "gtm-configure", "tenant": "bad/tenant", "container": "GTM-FAKE"},
        ])
        self.assertEqual([{"error": "denied"}] * 3, result)
        self.assertNotRegex(Path(__file__).read_text(encoding="utf-8"),
                            r"(?<![a-z])(?:password|secret|api_key)\s*=\s*['\"][^'\"]+")

    def test_ci_gate_contract(self) -> None:
        """AC-04: smoke PHP real; CI exact-HEAD es gate externo."""
        self.assertEqual(12, len(IDS))
        self.assertEqual({"C", "B"}, {row[0] for row in NON_OFFLINE.values()})
        self.assertEqual(1, len(execute([{"op": "version"}])))


if __name__ == "__main__":
    unittest.main()
