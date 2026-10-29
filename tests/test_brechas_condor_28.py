#!/usr/bin/env python3
"""#1223: brechas GEST-INTEG-04/05/06 y GEST-LIM-01/03 con datos sintéticos.

BRECHA_DOCUMENTADA acredita un caso negativo local, nunca cumplimiento legal,
integración real, retención, auditoría persistente ni validación en producción.
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
CSV = "src/Application/Reporting/TenantCsvReportService.php"
READER = "src/Application/Reporting/TenantReportRecordsReader.php"
DOC = "src/Application/Documents/DocumentApiService.php"
SOURCES = (MATRIX, SPEC, CSV, READER, DOC)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
BT = chr(96)
CLAUSES = {
    "GEST-INTEG-04": (
        "Nómina, PILA, DIAN y facturación: Adapters detrás de contratos versionados; "
        "credenciales, transmisión y declaración real quedan fuera del primer alcance.",
        "un caso negativo con datos laborales sintéticos, permisos por entidad legal y estado B de #742",
        "Adapters detrás de contratos versionados",
    ),
    "GEST-INTEG-05": (
        "Correo/notificaciones: Mínimo dato, plantillas auditables, sin incluir PII "
        "ni detalles de expedientes en el asunto o cola.",
        "una evidencia de ejecución sobre SHA exacto, resultado terminal y prueba de fail-closed",
        "Mínimo dato, plantillas auditables",
    ),
    "GEST-INTEG-06": (
        "BI/exportación: Filtro tenant + entidad legal + permiso en servidor; "
        "exportación auditable, con minimización y retención.",
        "una regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "Filtro tenant + entidad legal + permiso en servidor",
    ),
    "GEST-LIM-01": (
        "El alcance siempre es 'tenant → entidad legal → sede/municipio', "
        "con autorización server-side, mínimo privilegio y auditoría. "
        "'LegalEntity' no equivale a tenant ni concede visibilidad transversal; "
        "véase [arquitectura de entidades legales](arquitectura-entidades-legales.md).",
        "una regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
        "tenant → entidad legal → sede/municipio",
    ),
    "GEST-LIM-03": (
        "Un documento cargado no demuestra cumplimiento, una regla no sustituye "
        "una revisión profesional y un 'success' de CI no acredita producción. "
        "Todo estado desconocido falla cerrado.",
        "una prueba de integridad, exportación autorizada y retención/reversión del registro",
        "Un documento cargado no demuestra cumplimiento",
    ),
}


def sources() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matriz ilegible")
    rows = {}
    pattern = (re.escape(BT) + r"(GEST-(?:INTEG-0[456]|LIM-0[13]))"
               + re.escape(BT) + r" · " + re.escape(BT)
               + re.escape(SPEC) + re.escape(BT))
    for line in raw.splitlines():
        if not line.startswith("| " + BT + "GEST-"):
            continue
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        first = cells[0] if cells else ""
        match = re.fullmatch(pattern, first)
        if match is None:
            if any(first.startswith(BT + key + BT) for key in CLAUSES):
                raise ValueError("UNKNOWN: ID o fuente incorrectos")
            continue
        key = match.group(1)
        clause, missing, _anchor = CLAUSES[key]
        if (key in rows or len(cells) != 5 or cells[1] != clause
                or cells[2] != BT + "NO_VERIFICABLE" + BT
                or "Falta vincular para " + key not in cells[3]
                or missing not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or cells[4] != "#802"):
            raise ValueError("UNKNOWN: fila inválida " + key)
        rows[key] = cells
    if set(rows) != set(CLAUSES):
        raise ValueError("UNKNOWN: faltan filas")
    return rows


def synthetic_scope(request: object, grant: object, permission: object) -> str:
    """Oráculo negativo: no sustituye autorización del servidor."""
    fields = {"tenant", "entity", "site"}
    if (type(request) is not dict or type(grant) is not dict
            or set(request) != fields or set(grant) != fields
            or type(permission) is not bool
            or any(type(item[k]) is not str or not item[k]
                   for item in (request, grant) for k in fields)):
        return UNKNOWN
    return "SYNTHETIC_ONLY" if request == grant and permission else "DENY"


def synthetic_adapter(contract: object, gate: object, authorized: object) -> str:
    """#742: estado B deniega operación, A tampoco acredita transmisión real."""
    if (type(contract) is not dict or set(contract) != {"adapter", "version"}
            or contract.get("adapter") not in {"nomina", "pila", "dian", "facturacion"}
            or type(contract.get("version")) is not int
            or contract["version"] != 1 or type(authorized) is not bool
            or gate not in {"A", "B"}):
        return UNKNOWN
    return "DENY" if gate == "B" or not authorized else UNKNOWN


def synthetic_notice(template: object, metadata: object) -> dict | str:
    """Plantilla auditable simulada, no correo, cola real ni expedientes."""
    if template != "case_update" or type(metadata) is not dict:
        return UNKNOWN
    if set(metadata) != {"event_ref", "kind"}:
        return "DENY"
    if (type(metadata["event_ref"]) is not str
            or re.fullmatch(r"fixture:event/[a-z0-9_-]+", metadata["event_ref"]) is None
            or metadata["kind"] not in {"pending_review", "approved_for_review"}):
        return "DENY"
    return {"subject": "Notificación de gestión",
            "queue": {"template": template, "event_ref": metadata["event_ref"]}}


PHP_PROBE = r'''
require 'src/Application/Reporting/TenantCsvReportService.php';
require 'src/Application/Documents/DocumentApiService.php';
use App\Application\Reporting\TenantCsvReportService as C;
use App\Application\Documents\DocumentApiService as D;
function denied(callable $f): bool {
 try {$f(); return false;} catch (\DomainException $e) {return true;}
}
$t='tenant-fixture-a'; $c=new C();
$export=$c->build('sales',$t,'2026-10-01','2026-10-31',[
 ['tenant_id'=>$t,'occurred_at'=>'2026-10-02','order_id'=>'fixture:order/local',
  'currency'=>'COP','total_amount_minor'=>1200,'payment_status'=>'PAID'],
 ['tenant_id'=>'tenant-fixture-b','occurred_at'=>'2026-10-02',
  'order_id'=>'fixture:order/foreign','currency'=>'COP',
  'total_amount_minor'=>999999,'payment_status'=>'PAID']
]);
$invalid=denied(fn()=>$c->build('sales',$t,'2026-10-01','2026-10-31',[
 ['tenant_id'=>$t,'occurred_at'=>'2026-10-01','order_id'=>'fixture:order/bad',
  'currency'=>'COP','total_amount_minor'=>[],'payment_status'=>'PAID']
]));
$d=new D(); $s='tenant-a|entity-a|site-a';
$created=$d->create([],$s,'fixture:doc/proof','RESERVADA');
$review=$d->requestReview($created['state'],$s,'fixture:doc/proof');
echo json_encode([
 'csv'=>$export['body'],'invalid_export_denied'=>$invalid,
 'document_status'=>$d->detail($review['state'],$s,'fixture:doc/proof')['status'],
 'foreign_denied'=>denied(fn()=>$d->detail($review['state'],
    'tenant-b|entity-a|site-a','fixture:doc/proof')),
 'entity_denied'=>denied(fn()=>$d->detail($review['state'],
    'tenant-a|entity-b|site-a','fixture:doc/proof')),
 'site_denied'=>denied(fn()=>$d->detail($review['state'],
    'tenant-a|entity-a|site-b','fixture:doc/proof'))
],JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def exercise_php() -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI no disponible")
    run = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=25)
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: PHP local falló: " + run.stderr[-350:])
    try:
        parsed = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: salida PHP ilegible") from exc
    if type(parsed) is not dict:
        raise AssertionError("UNKNOWN: salida PHP sin objeto")
    return parsed


def local_check(key: object, data: object, proof: object) -> str:
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or type(proof) is not dict
            or set(data) != set(SOURCES)
            or any(type(data[p]) is not str or not data[p] for p in SOURCES)):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not all(anchor in data[SPEC] for _, _, anchor in CLAUSES.values()):
        return UNKNOWN
    if ("final class TenantCsvReportService" not in data[CSV]
            or "final readonly class TenantReportRecordsReader" not in data[READER]
            or "final class DocumentApiService" not in data[DOC]):
        return UNKNOWN
    scope = {"tenant": "a", "entity": "a", "site": "a"}
    try:
        if key == "GEST-INTEG-04":
            ok = (all(synthetic_adapter({"adapter": x, "version": 1}, "B", True)
                      == "DENY" for x in ("nomina", "pila", "dian", "facturacion"))
                  and synthetic_adapter({"adapter": "pila", "version": 1},
                                        "A", True) == UNKNOWN)
        elif key == "GEST-INTEG-05":
            notice = synthetic_notice("case_update", {
                "event_ref": "fixture:event/review", "kind": "pending_review"})
            ok = (type(notice) is dict and set(notice) == {"subject", "queue"}
                  and set(notice["queue"]) == {"template", "event_ref"}
                  and synthetic_notice("case_update", {
                      "event_ref": "fixture:event/review", "kind": "pending_review",
                      "case_details": "synthetic-only"}) == "DENY")
        elif key == "GEST-INTEG-06":
            ok = ("fixture:order/local" in proof["csv"]
                  and "fixture:order/foreign" not in proof["csv"]
                  and "999999" not in proof["csv"]
                  and proof["invalid_export_denied"] is True
                  and "REPORT_SCOPE_INVALID" in data[READER]
                  and "source->branch()" in data[READER]
                  and synthetic_scope(scope, {**scope, "entity": "b"}, True)
                      == "DENY")
        elif key == "GEST-LIM-01":
            ok = (synthetic_scope(scope, scope, True) == "SYNTHETIC_ONLY"
                  and synthetic_scope(scope, scope, False) == "DENY"
                  and all(synthetic_scope(scope, {**scope, f: "b"}, True)
                          == "DENY" for f in scope)
                  and "legalEntity()->id()" in data[READER]
                  and all(proof[k] is True for k in
                          ("foreign_denied", "entity_denied", "site_denied")))
        else:
            ok = (proof["document_status"] == "PENDIENTE_REVISION"
                  and proof["foreign_denied"] is True
                  and "no uploads, personal data, persistence" in data[DOC]
                  and synthetic_scope(scope, scope, False) == "DENY")
    except (KeyError, TypeError, AttributeError):
        return UNKNOWN
    return GAP if ok else UNKNOWN


def operational_attestation(_key: object, _evidence: object = None) -> str:
    """Sin auditoría/retención/revisión profesional, el estado es UNKNOWN."""
    return UNKNOWN


class BrechasCondor28Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data, proof = sources(), exercise_php()
        self.assertEqual(5, len(CLAUSES))
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        for key in CLAUSES:
            with self.subTest(row=key):
                self.assertEqual(GAP, local_check(key, data, proof))
                self.assertEqual(UNKNOWN, operational_attestation(key, proof))

    def test_falla_cerrado(self) -> None:
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            row = next(x for x in data[MATRIX].splitlines()
                       if x.startswith("| " + BT + key + BT + " · "))
            for changed in (
                data[MATRIX].replace(row, "", 1),
                data[MATRIX] + "\n" + row,
                data[MATRIX].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(changed)
                    self.assertEqual(UNKNOWN, local_check(
                        key, {**data, MATRIX: changed}, proof))
            self.assertEqual(UNKNOWN, local_check(key, {**data, SPEC: ""}, proof))
            self.assertEqual(UNKNOWN, local_check(key, data, {}))
        scope = {"tenant": "a", "entity": "a", "site": "a"}
        self.assertEqual(UNKNOWN, synthetic_scope(scope, {}, True))
        self.assertEqual(UNKNOWN, synthetic_scope(scope, scope, None))
        self.assertEqual(UNKNOWN, synthetic_adapter({}, "B", True))
        self.assertEqual(UNKNOWN, synthetic_adapter(
            {"adapter": "pila", "version": 1}, None, True))
        self.assertEqual("DENY", synthetic_notice("case_update", {
            "event_ref": "fixture:event/review", "kind": "pending_review",
            "subject": "synthetic-sensitive"}))

    def test_sin_datos_reales(self) -> None:
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "urllib", "socket", "httpx", "pymysql"})
        for token in ("http://", "https://", "DATABASE_URL", "APP_SECRET",
                      "file_get_contents", "shell_exec", "curl_exec"):
            self.assertNotIn(token, PHP_PROBE)
        self.assertNotIn("fixture:order/foreign", exercise_php()["csv"])

    def test_estados_unknown_explicitos(self) -> None:
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            self.assertEqual(GAP, local_check(key, data, proof))
            for alleged in (None, {}, True, {"sha": "a" * 40, "ci": "success"},
                            {"state": "VALIDATED_IN_PRODUCTION"},
                            {"legal_review": "APPROVED"},
                            {"record": "uploaded", "compliance": "CUMPLE"}):
                with self.subTest(row=key):
                    self.assertEqual(UNKNOWN, operational_attestation(key, alleged))
        self.assertEqual("DENY", synthetic_adapter(
            {"adapter": "nomina", "version": 1}, "B", True))
        self.assertEqual("DENY", synthetic_scope(
            {"tenant": "a", "entity": "a", "site": "a"},
            {"tenant": "b", "entity": "a", "site": "a"}, True))
        self.assertEqual("PENDIENTE_REVISION", proof["document_status"])


if __name__ == "__main__":
    unittest.main()
