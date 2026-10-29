#!/usr/bin/env python3
"""Condor #1222: pruebas locales de cinco brechas, sin simular producción.

La prueba usa sólo fixtures y contratos PHP puros. El código, permisos reales,
CI exact-SHA, firma y alcance operativo total siguen UNKNOWN.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/gestion-empresarial-integral.md"
ASSET = "src/Application/Assets/AssetRegisterLifecycle.php"
CSV = "src/Application/Reporting/TenantCsvReportService.php"
READER = "src/Application/Reporting/TenantReportRecordsReader.php"
PURCHASE = "src/Application/Purchasing/PurchaseOrderService.php"
PRICING = "src/Application/Commerce/PricingService.php"
DOC = "src/Application/Documents/DocumentApiService.php"
SOURCES = (MATRIX, SPEC, ASSET, CSV, READER, PURCHASE, PRICING, DOC)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "GEST-FLUJO-3-P04": (
        "Los tableros agregan sin exponer PII ni información médica/denuncias a roles no autorizados.",
        "una regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "4. Los tableros agregan sin exponer PII ni información médica/denuncias a roles no autorizados.",
    ),
    "GEST-FLUJO-5": (
        "Abastecimiento, activos y operación",
        "una evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "### 5. Abastecimiento, activos y operación",
    ),
    "GEST-INTEG-01": (
        "Comercio, pedidos, inventario, compras y producción: Consumir las entidades y APIs ya existentes; no duplicar catálogo, stock, cliente, proveedor ni costo.",
        "una prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
        "Consumir las entidades y APIs ya existentes; no duplicar catálogo, stock, cliente, proveedor ni costo.",
    ),
    "GEST-INTEG-02": (
        "Entitlements vs permisos: La contratación habilita una capacidad; RBAC decide quién puede usarla. Ambas condiciones son necesarias.",
        "una regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "La contratación habilita una capacidad; RBAC decide quién puede usarla. Ambas condiciones son necesarias.",
    ),
    "GEST-INTEG-03": (
        "Documento/firma: Adaptador intercambiable, evidencia de consentimiento/firma y estado verificable; no prometer firma o validez antes de integrar proveedor y revisión aplicable.",
        "una regresión de consistencia entre controlador, persistencia y permisos del tenant",
        "Adaptador intercambiable, evidencia de consentimiento/firma y estado verificable;",
    ),
}


def sources() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matriz ilegible")
    found = {}
    for line in raw.splitlines():
        if not line.startswith("| " + chr(96) + "GEST-"):
            continue
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        first = cells[0] if cells else ""
        pattern = (r"^" + re.escape(chr(96))
                   + r"(GEST-(?:FLUJO-3-P04|FLUJO-5|INTEG-0[123]))"
                   + re.escape(chr(96)) + r" · "
                   + re.escape(chr(96)) + re.escape(SPEC)
                   + re.escape(chr(96)) + r"$")
        match = re.fullmatch(pattern, first)
        if match is None:
            if any(first.startswith(chr(96) + k + chr(96)) for k in CLAUSES):
                raise ValueError("UNKNOWN: ID incorrecto")
            continue
        key = match.group(1)
        clause, required, _ = CLAUSES[key]
        if (key in found or len(cells) != 5
                or cells[1] != clause or cells[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                or f"Falta vincular para {key} " not in cells[3]
                or required not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError("UNKNOWN: fila inválida " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: faltan filas")
    return found


def normative_spec(raw: object) -> bool:
    return type(raw) is str and all(
        anchor in raw for _, _, anchor in CLAUSES.values()
    ) and all(x in raw for x in (
        "tenant → entidad legal → sede/municipio",
        "Un documento cargado no demuestra cumplimiento",
        "No se guardan historias clínicas ocupacionales",
    ))


def synthetic_permission(entitled: object, rbac: object,
                         requested: object, granted: object) -> bool | str:
    """Caso negativo ilustrativo; no se atribuye a la capa HTTP real."""
    if type(entitled) is not bool or type(rbac) is not bool:
        return UNKNOWN
    if type(requested) is not dict or type(granted) is not dict:
        return UNKNOWN
    keys = {"tenant", "company", "branch"}
    if set(requested) != keys or set(granted) != keys:
        return UNKNOWN
    if any(type(requested[k]) is not str or not requested[k]
           or type(granted[k]) is not str or not granted[k] for k in keys):
        return UNKNOWN
    return requested == granted and entitled and rbac


PHP_PROBE = r'''
require 'src/Application/Assets/AssetRegisterLifecycle.php';
require 'src/Application/Reporting/TenantCsvReportService.php';
require 'src/Application/Documents/DocumentApiService.php';
use App\Application\Assets\AssetRegisterLifecycle as A;
use App\Application\Reporting\TenantCsvReportService as C;
use App\Application\Documents\DocumentApiService as D;
function denied(callable $f): bool {
  try {$f(); return false;} catch (\DomainException $e) {return true;}
}
$t='tenant-test-alpha'; $c='fixture:company/alpha';
$asset=['tenant_ref'=>$t,'company_ref'=>$c,'as_of'=>'2026-10-28',
 'assets'=>[
  ['tenant_ref'=>$t,'company_ref'=>$c,'asset_ref'=>'fixture:asset/truck',
   'responsible_ref'=>'fixture:responsible/team','warranty_until'=>'2026-12-31',
   'maintenance_on'=>'2026-10-25','action'=>'ASIGNAR',
   'evidence_ref'=>'fixture:evidence/check'],
  ['tenant_ref'=>'tenant-test-beta','company_ref'=>'fixture:company/beta',
   'asset_ref'=>'fixture:asset/foreign','responsible_ref'=>null,
   'warranty_until'=>null,'maintenance_on'=>null,'action'=>'ALTA','evidence_ref'=>null]
]];
$badAsset=$asset;
$badAsset['assets'][0]['action']='SOLICITAR_BAJA';
$badAsset['assets'][0]['evidence_ref']=null;
$otherCompany=$asset;
$otherCompany['company_ref']='fixture:company/other';
$csv=new C();
$sales=$csv->build('sales',$t,'2026-10-01','2026-10-31',[
 ['tenant_id'=>$t,'occurred_at'=>'2026-10-01','order_id'=>'fixture:order/local',
  'currency'=>'COP','total_amount_minor'=>12500,'payment_status'=>'PAID'],
 ['tenant_id'=>'tenant-test-beta','occurred_at'=>'2026-10-01',
  'order_id'=>'fixture:order/foreign','currency'=>'COP',
  'total_amount_minor'=>999999,'payment_status'=>'PAID']
]);
$purchases=$csv->build('purchases',$t,'2026-10-01','2026-10-31',[
 ['tenant_id'=>$t,'occurred_at'=>'2026-10-02',
  'purchase_order_id'=>'fixture:purchase/local','supplier_id'=>'fixture:supplier/local',
  'status'=>'RECEIVED','total_cost_minor'=>25000]
]);
$invalid=denied(fn()=>$csv->build('sales',$t,'2026-10-01','2026-10-31',[
 ['tenant_id'=>$t,'occurred_at'=>'2026-10-02','order_id'=>'fixture:order/invalid',
  'currency'=>'COP','total_amount_minor'=>['invalid'],'payment_status'=>'PAID']
]));
$doc=new D();
$scope='tenant-a|entity-a|branch-a';
$created=$doc->create([],$scope,'fixture:doc/agreement','RESERVADA');
$review=$doc->requestReview($created['state'],$scope,'fixture:doc/agreement');
$again=$doc->requestReview($review['state'],$scope,'fixture:doc/agreement');
echo json_encode([
 'asset'=>A::project($asset),
 'asset_invalid'=>A::project($badAsset),
 'asset_other_company'=>A::project($otherCompany),
 'sales'=>$sales['body'],'purchases'=>$purchases['body'],
 'transaction_invalid_denied'=>$invalid,
 'document'=>$doc->detail($review['state'],$scope,'fixture:doc/agreement'),
 'document_changed'=>$review['changed'],'document_again_changed'=>$again['changed'],
 'foreign_denied'=>denied(fn()=>$doc->detail($review['state'],
    'tenant-b|entity-b|branch-b','fixture:doc/agreement')),
 'entity_denied'=>denied(fn()=>$doc->detail($review['state'],
    'tenant-a|entity-b|branch-a','fixture:doc/agreement')),
 'branch_denied'=>denied(fn()=>$doc->detail($review['state'],
    'tenant-a|entity-a|branch-b','fixture:doc/agreement'))
],JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def exercise_php() -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: falta PHP CLI")
    result = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: PHP offline: " + result.stderr[-350:])
    try:
        parsed = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: salida PHP inválida") from exc
    if type(parsed) is not dict:
        raise AssertionError("UNKNOWN: salida PHP no es objeto")
    return parsed


def local_check(key: object, data: object, probe: object) -> str:
    """Contraste parcial; nunca certificación productiva ni legal."""
    if key not in CLAUSES or type(data) is not dict or type(probe) is not dict:
        return UNKNOWN
    if set(data) != set(SOURCES) or any(
        type(data[p]) is not str or not data[p] for p in SOURCES
    ):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not normative_spec(data[SPEC]):
        return UNKNOWN
    for path, symbol in (
        (ASSET, "final class AssetRegisterLifecycle"),
        (CSV, "final class TenantCsvReportService"),
        (READER, "final readonly class TenantReportRecordsReader"),
        (PURCHASE, "final readonly class PurchaseOrderService"),
        (PRICING, "final readonly class PricingService"),
        (DOC, "final class DocumentApiService"),
    ):
        if symbol not in data[path]:
            return UNKNOWN
    try:
        if key == "GEST-FLUJO-3-P04":
            ok = ("fixture:order/local" in probe["sales"]
                  and "fixture:order/foreign" not in probe["sales"]
                  and "999999" not in probe["sales"]
                  and "source->branch()" in data[READER]
                  and "legalEntity" in data[READER]
                  and "self::COLUMNS[$kind]" in data[CSV])
        elif key == "GEST-FLUJO-5":
            asset = probe["asset"]
            ok = (asset["valid"] is True and len(asset["assets"]) == 1
                  and asset["assets"][0]["state"] == "ASIGNADO"
                  and asset["assets"][0]["maintenance_due"] is True
                  and asset["can_close"] is False
                  and asset["can_approve"] is False
                  and probe["asset_invalid"]["valid"] is False
                  and probe["asset_other_company"]["assets"] == [])
        elif key == "GEST-INTEG-01":
            ok = ("fixture:order/local,2026-10-01,COP,12500" in probe["sales"]
                  and "fixture:purchase/local,2026-10-02" in probe["purchases"]
                  and probe["transaction_invalid_denied"] is True
                  and "MaterialInventoryService" in data[PURCHASE]
                  and "PriceList" in data[PRICING])
        elif key == "GEST-INTEG-02":
            scope = {"tenant": "t-a", "company": "c-a", "branch": "b-a"}
            ok = (synthetic_permission(True, True, scope, scope) is True
                  and synthetic_permission(False, True, scope, scope) is False
                  and synthetic_permission(True, False, scope, scope) is False
                  and synthetic_permission(True, True, scope,
                        {**scope, "branch": "b-b"}) is False
                  and "REPORT_SCOPE_INVALID" in data[READER])
        else:
            doc = probe["document"]
            ok = (doc["reference"] == "fixture:doc/agreement"
                  and doc["status"] == "PENDIENTE_REVISION"
                  and probe["document_changed"] is True
                  and probe["document_again_changed"] is False
                  and all(probe[k] is True for k in (
                      "foreign_denied", "entity_denied", "branch_denied"
                  ))
                  and "no uploads" in data[DOC])
    except (KeyError, IndexError, TypeError, AttributeError):
        return UNKNOWN
    return GAP if ok else UNKNOWN


def operational_attestation(_key: object, _evidence: object = None) -> str:
    return UNKNOWN


class BrechasCondor27Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data, proof = sources(), exercise_php()
        self.assertEqual(len(CLAUSES), 5)
        self.assertEqual(set(matrix_rows(data[MATRIX])), set(CLAUSES))
        self.assertTrue(normative_spec(data[SPEC]))
        for key in CLAUSES:
            with self.subTest(row=key):
                self.assertEqual(GAP, local_check(key, data, proof))
                self.assertEqual(UNKNOWN, operational_attestation(key, proof))

    def test_falla_cerrado(self) -> None:
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            row = next(x for x in data[MATRIX].splitlines()
                       if x.startswith("| " + chr(96) + key + chr(96) + " · "))
            for altered in (
                data[MATRIX].replace(row, "", 1),
                data[MATRIX] + "\n" + row,
                data[MATRIX].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(UNKNOWN, local_check(
                        key, {**data, MATRIX: altered}, proof))
            self.assertEqual(UNKNOWN, local_check(key, {**data, SPEC: ""}, proof))
            self.assertEqual(UNKNOWN, operational_attestation(key, {"ci": "success"}))
        for key, field in (
            ("GEST-FLUJO-3-P04", "sales"), ("GEST-FLUJO-5", "asset"),
            ("GEST-INTEG-01", "transaction_invalid_denied"),
            ("GEST-INTEG-03", "document"),
        ):
            bad = dict(proof)
            bad[field] = None
            self.assertEqual(UNKNOWN, local_check(key, data, bad))
        scope = {"tenant": "t-a", "company": "c-a", "branch": "b-a"}
        self.assertEqual(UNKNOWN, synthetic_permission(None, True, scope, scope))
        self.assertEqual(UNKNOWN, synthetic_permission(True, None, scope, scope))
        self.assertEqual(UNKNOWN, synthetic_permission(True, True, scope, {}))

    def test_sin_datos_reales(self) -> None:
        raw = (ROOT / "tests/test_brechas_condor_27.py").read_text(encoding="utf-8")
        tree = ast.parse(raw)
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "urllib", "socket", "httpx", "pymysql"})
        for word in ("curl_", "PDO", "file_get_contents", "APP_SECRET",
                     "DATABASE_URL", "http://", "https://", "shell_exec"):
            self.assertNotIn(word, PHP_PROBE)
        self.assertNotIn("fixture:order/foreign", exercise_php()["sales"])

    def test_estados_unknown_explicitos(self) -> None:
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            self.assertEqual(GAP, local_check(key, data, proof))
            for evidence in (
                None, {}, True, {"sha": "f" * 40, "ci": "success"},
                {"approval": "APPROVED"}, {"state": "VALIDATED_IN_PRODUCTION"},
            ):
                with self.subTest(row=key):
                    self.assertEqual(UNKNOWN, operational_attestation(key, evidence))
        scope = {"tenant": "t-a", "company": "c-a", "branch": "b-a"}
        self.assertFalse(synthetic_permission(True, True, scope,
                                               {**scope, "tenant": "t-b"}))
        self.assertFalse(synthetic_permission(True, False, scope, scope))
        self.assertFalse(proof["asset_invalid"]["can_close"])
        self.assertEqual("PENDIENTE_REVISION", proof["document"]["status"])


if __name__ == "__main__":
    unittest.main()
