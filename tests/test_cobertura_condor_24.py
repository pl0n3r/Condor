#!/usr/bin/env python3
"""Condor #1343: contratos PHP reales, offline, con fixtures descartables.

A verifica subcontratos locales, no aceptación integral, persistencia, pago,
stock físico, legislación real, despliegue ni producción. B/C/D se clasifican
explícitamente; este test no lee ni valida estados de matrices de cobertura.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = (
    "GEST-NORM-03", "GEST-NORM-04", "GEST-NORM-05", "GEST-NORM-06",
    "GEST-NORM-07", "OPS-M01-PENDIENTE", "OPS-M01-PRUEBA",
    "OPS-M02-PENDIENTE", "OPS-M02-PRUEBA", "OPS-M03-PENDIENTE",
    "OPS-M03-PRUEBA", "OPS-M04-PENDIENTE",
)
A = frozenset((
    "GEST-NORM-04", "GEST-NORM-05", "GEST-NORM-06",
    "OPS-M02-PRUEBA", "OPS-M03-PRUEBA",
))
B = {
    "OPS-M01-PRUEBA": "Crear variantes y precios requiere un catálogo y tenant de prueba transaccionales; sin DB no se atesta operación integral.",
    "OPS-M02-PENDIENTE": "Pasarela, dominio, shipping y aceptación real requieren integración externa/autoridad de despliegue.",
    "OPS-M03-PENDIENTE": "Recepción, facturación y conciliación exigen ERP/contabilidad y recibos de integración reales.",
}
C = {
    "GEST-NORM-03": "Fronteras arquitectónicas entre dominios y conectores; no son una operación única acreditable con fixture.",
    "GEST-NORM-07": "Trazabilidad a hojas, épicos y decisiones; referencias editoriales, no un runtime aislado.",
    "OPS-M01-PENDIENTE": "Aprobación de política de precios/mayoristas e interfaz comercial es decisión del negocio.",
}
D = {
    "OPS-M04-PENDIENTE": "Alerta automática de stock y escáner integrados en admin aún no acreditados como un solo flujo.",
}
PHP_FILES = (
    "src/Application/Documents/DocumentApiService.php",
    "src/Application/Compliance/ComplianceApiService.php",
    "src/Application/Compliance/NormativeApplicability.php",
    "src/Application/Compliance/NormativeMatrix.php",
    "src/Application/Storefront/PublicCatalogSearch.php",
    "src/Domain/Orders/OrderStateMachine.php",
    "src/Application/Orders/OrderStateTransitions.php",
    "src/Application/Inventory/RestockSuggester.php",
)

# All required classes are the actual repository PHP files. No source is
# reimplemented in this probe; mb_strlen fallback is for ASCII-only CLI fixtures.
PROBE = r'''
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, string $encoding = 'UTF-8'): int {
        return strlen($value); // ASCII-only synthetic fixture
    }
}
require_once 'src/Application/Documents/DocumentApiService.php';
require_once 'src/Application/Compliance/ComplianceApiService.php';
require_once 'src/Application/Compliance/NormativeApplicability.php';
require_once 'src/Application/Compliance/NormativeMatrix.php';
require_once 'src/Application/Storefront/PublicCatalogSearch.php';
require_once 'src/Domain/Orders/OrderStateMachine.php';
require_once 'src/Application/Orders/OrderStateTransitions.php';
require_once 'src/Application/Inventory/RestockSuggester.php';
use App\Application\Documents\DocumentApiService as Documents;
use App\Application\Compliance\ComplianceApiService as Compliance;
use App\Application\Compliance\NormativeMatrix as Matrix;
use App\Application\Storefront\PublicCatalogSearch as Catalog;
use App\Domain\Orders\OrderStateMachine as Orders;
use App\Application\Orders\OrderStateTransitions as Transitions;
use App\Application\Inventory\RestockSuggester as Restock;
function denied(callable $action): bool {
    try { $action(); } catch (\DomainException | \InvalidArgumentException $error) {
        return true;
    }
    return false;
}
$scopeA = hash('sha256', 'tenant-fixture-a|entity-fixture-a|site-fixture-a');
$scopeB = hash('sha256', 'tenant-fixture-b|entity-fixture-a|site-fixture-a');
$documents = new Documents();
$doc = $documents->create([], $scopeA, 'fixture:doc/contract_a', 'RESERVADA');
$documentOK = $documents->detail($doc['state'], $scopeA, 'fixture:doc/contract_a')
    === $doc['document']
    && $documents->listing($doc['state'], $scopeB) === []
    && $doc['document']['status'] === 'BORRADOR';
$docDenied = denied(fn() => $documents->detail(
        $doc['state'], $scopeB, 'fixture:doc/contract_a'))
    && denied(fn() => $documents->create(
        $doc['state'], $scopeA, 'fixture:doc/contract_a', 'RESERVADA'))
    && denied(fn() => $documents->create(
        [], $scopeA, 'fixture:doc/invalid', 'CLINICA'));
$evidence = Compliance::createRecord([], $scopeA, [
    'evidence_ref' => 'fixture:evidence/record_a',
    'obligation_ref' => 'fixture:obligation/record_a',
    'type' => 'EVIDENCIA',
]);
$complianceOK = count(Compliance::listRecords($evidence['store'], $scopeA)) === 1
    && Compliance::listRecords($evidence['store'], $scopeB) === []
    && Compliance::viewRecord(
        $evidence['store'], $scopeB, 'fixture:evidence/record_a') === null;
$complianceDenied = denied(fn() => Compliance::changeState(
        $evidence['store'], $scopeA, 'fixture:evidence/record_a', 'CUMPLE'))
    && denied(fn() => Compliance::createRecord(
        $evidence['store'], $scopeA, [
            'evidence_ref'=>'fixture:evidence/record_a',
            'obligation_ref'=>'fixture:obligation/record_a',
            'type'=>'EVIDENCIA',
        ]))
    && denied(fn() => Compliance::listRecords($evidence['store'], 'wrong-scope'));
$context = [
    'tenant_ref'=>'tenant-fixture-a', 'sector'=>'comercio', 'ciiu'=>'4711',
    'municipio'=>'bogota', 'riesgo'=>'ii', 'tamano_empresa'=>'micro',
    'regimen'=>'ordinario',
];
$rule = [
    'tenant_ref'=>'tenant-fixture-a', 'kind'=>'OBLIGATORIO', 'approved'=>true,
    'reviewer_ref'=>'reviewer-fixture',
    'source'=>[
        'kind'=>'official', 'issuer'=>'fixture-authority',
        'article'=>'article-fixture', 'version'=>'v1',
        'official_url'=>'https://normas.example.invalid/fixture',
    ],
    'effective_from'=>'2026-01-01', 'effective_until'=>'2026-12-31',
    'verified_on'=>'2026-03-01',
    'conditions'=>['sector'=>'comercio','municipio'=>'bogota'],
];
$accepted = Matrix::evaluate([$rule], $context, '2026-10-29')['rows'][0];
$sourceOK = $accepted['kind'] === 'OBLIGATORIO'
    && $accepted['applicability'] === 'APLICA'
    && $accepted['source']['issuer'] === 'fixture-authority'
    && $accepted['verified_on'] === '2026-03-01';
$unapproved = Matrix::evaluate([[...$rule,'approved'=>false]],
    $context, '2026-10-29')['rows'][0];
$notCurrent = Matrix::evaluate([$rule], $context, '2027-01-01')['rows'][0];
$badSource = Matrix::evaluate([[...$rule,'source'=>['kind'=>'unknown']]],
    $context, '2026-10-29')['rows'][0];
$sourceDenied = $unapproved['kind'] === 'REQUIERE_REVISION'
    && $unapproved['source'] === null
    && $notCurrent['applicability'] === 'DESCONOCIDO'
    && $notCurrent['source'] === null
    && $badSource['kind'] === 'REQUIERE_REVISION';
$otherContext = [...$context,'municipio'=>'medellin'];
$nonApplicable = Matrix::evaluate([$rule], $otherContext,
    '2026-10-29')['rows'][0];
$missing = Matrix::evaluate([$rule], ['tenant_ref'=>'tenant-fixture-a'],
    '2026-10-29')['rows'][0];
$foreign = Matrix::evaluate([[...$rule,'tenant_ref'=>'tenant-fixture-b']],
    $context, '2026-10-29')['rows'];
$crossOK = $nonApplicable['kind'] === 'NO_APLICA'
    && $nonApplicable['applicability'] === 'NO_APLICA'
    && $missing['kind'] === 'REQUIERE_REVISION'
    && $foreign === [];
$crossDenied = $missing['source'] === null
    && Matrix::evaluate([$rule], [], '2026-10-29')['rows'][0]['kind']
       === 'REQUIERE_REVISION'
    && denied(fn() => Compliance::changeState(
        $evidence['store'], $scopeA, 'fixture:evidence/record_a', 'APROBADO'));
$catalog = Catalog::fromQuery(['q'=>'Camisa', 'categoria'=>'ropa', 'pagina'=>'2']);
$orders = new Orders();
$changes = new Transitions($orders);
$when = new \DateTimeImmutable('2026-10-29T10:00:00+00:00');
$draft = $orders->initial('tenant-fixture-a', 250000);
$publicOK = $catalog->filters() === ['q'=>'Camisa','categoria'=>'ropa']
    && $catalog->page() === 2
    && $catalog->pageHref(3) === '?q=Camisa&categoria=ropa&pagina=3'
    && $draft['payment'] === 'pending' && $draft['fulfillment'] === 'reserved';
$publicDenied = denied(fn() => Catalog::fromQuery(['pagina'=>'0']))
    && denied(fn() => Catalog::fromQuery(['categoria'=>'../../../']))
    && denied(fn() => Catalog::fromQuery(['q'=>"Camisa\n"]));
$payment = $changes->recordPayment($draft, 250000, false,
    'tenant-fixture-a', 'tenant-fixture-a', 'actor-fixture-a', $when);
$fulfilled = $changes->change($payment, 'fulfillment', 'consumed',
    'tenant-fixture-a', 'tenant-fixture-a', 'actor-fixture-a', $when);
$cancelled = $changes->change($draft, 'order', 'cancelled',
    'tenant-fixture-a', 'tenant-fixture-a', 'actor-fixture-a', $when);
$restock = (new Restock())->suggest(45, 12, 2);
$ordersOK = $payment['payment'] === 'paid'
    && $fulfilled['fulfillment'] === 'consumed'
    && $fulfilled['history'][1]['actor'] === 'actor-fixture-a'
    && $cancelled['fulfillment'] === 'released'
    && count($cancelled['history']) === 2
    && $restock['available_quantity'] === 10
    && $restock['suggested_quantity'] === 35;
$ordersDenied = denied(fn() => $changes->recordPayment($draft, 250, false,
        'tenant-fixture-a', 'tenant-fixture-a', 'actor-fixture-a', $when))
    && denied(fn() => $changes->change($draft, 'fulfillment', 'consumed',
        'tenant-fixture-a', 'tenant-fixture-b', 'actor-fixture-a', $when))
    && denied(fn() => $changes->change($payment, 'order', 'cancelled',
        'tenant-fixture-a', 'tenant-fixture-a', 'actor-fixture-a', $when)) === false
    && denied(fn() => $orders->initial('', -1))
    && denied(fn() => (new Restock())->suggest(-1, 12, 2))
    && (new Restock())->suggest(0, 12, 2) === null;
echo json_encode([
    'GEST-NORM-04'=>$documentOK && $complianceOK,
    'GEST-NORM-04-denied'=>$docDenied && $complianceDenied,
    'GEST-NORM-05'=>$sourceOK,
    'GEST-NORM-05-denied'=>$sourceDenied,
    'GEST-NORM-06'=>$crossOK,
    'GEST-NORM-06-denied'=>$crossDenied,
    'OPS-M02-PRUEBA'=>$publicOK,
    'OPS-M02-PRUEBA-denied'=>$publicDenied,
    'OPS-M03-PRUEBA'=>$ordersOK,
    'OPS-M03-PRUEBA-denied'=>$ordersDenied,
], JSON_THROW_ON_ERROR);
'''


def php_evidence() -> dict[str, bool]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI required")
    for source in PHP_FILES:
        if not (ROOT / source).is_file():
            raise AssertionError("UNKNOWN: missing real PHP source " + source)
    result = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=30,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError("UNKNOWN: real PHP scenario failed: " + result.stderr[-800:])
    try:
        evidence = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP evidence malformed") from exc
    expected = {name for key in A for name in (key, key + "-denied")}
    if (not isinstance(evidence, dict) or set(evidence) != expected
            or any(type(value) is not bool for value in evidence.values())):
        raise AssertionError("UNKNOWN: incomplete PHP evidence")
    return evidence


class CoberturaCondor24Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(set(IDS), A | B.keys() | C.keys() | D.keys())
        self.assertEqual(len(IDS), 12)
        evidence = php_evidence()
        for key in A:
            with self.subTest(requirement=key):
                self.assertIs(evidence[key], True, key)

    def test_falla_cerrado(self) -> None:
        evidence = php_evidence()
        for key in A:
            with self.subTest(requirement=key):
                self.assertIs(evidence[key + "-denied"], True, key)
        for collection in (A, B.keys(), C.keys(), D.keys()):
            self.assertEqual(len(set(collection)), len(collection))
        self.assertEqual(sum(map(len, (A, B, C, D))), 12)

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        imports = ast.walk(ast.parse(own))
        for node in imports:
            if isinstance(node, ast.Import):
                self.assertFalse({part.name.split(".")[0] for part in node.names}
                                 & {"requests", "socket", "urllib", "httpx"})
            if isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0],
                                 {"requests", "socket", "urllib", "httpx"})
        for forbidden in ("curl_", "file_get_contents(", "getenv(",
                          "PDO(", "mysqli_", "Bearer ", "example.com"):
            self.assertNotIn(forbidden, PROBE)
        self.assertIn("normas.example.invalid", PROBE)
        self.assertIn("tenant-fixture-a", PROBE)
        self.assertEqual(sum(map(len, (A, B, C, D))), len(IDS))
        for category, mapping in (("B", B), ("C", C), ("D", D)):
            for key, reason in mapping.items():
                self.assertIn(key, IDS)
                self.assertTrue(reason, category)

    def test_ci_gate_contract(self) -> None:
        self.assertEqual(len(A), 5)
        self.assertEqual(len(B), 3)
        self.assertEqual(len(C), 3)
        self.assertEqual(len(D), 1)
        self.assertTrue(all(php_evidence().values()))


if __name__ == "__main__":
    unittest.main()
