#!/usr/bin/env python3
"""#1561: contratos de SG-SST ficticio; no acredita operación laboral real.

Los contratos HTTP del controlador se inspeccionan por fuente. El servidor Symfony
autenticado requiere fixtures del kernel para pruebas E2E completas posteriores.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Compliance/OccupationalApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/ComplianceController.php"
UI = ROOT / "frontend/admin/ComplianceManagement.tsx"
KINDS = ("PERFIL_ESTANDARES", "PELIGRO", "CONTROL", "INSPECCION", "INCIDENTE",
         "INVESTIGACION", "EPP", "CAPACITACION", "EMERGENCIA", "COMITE_VIGIA")


def execute(steps: list[dict]) -> list:
    script = r"""
require $argv[1];
use App\Application\Compliance\OccupationalApiService as C;
$steps = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$store = []; $out = [];
foreach ($steps as $step) {
    try {
        $scope = hash('sha256', $step['tenant'].'|'.$step['branch']);
        switch ($step['method']) {
            case 'POST':
                $result = C::createRecord($store, $scope, $step['payload']);
                $store = $result['store']; $out[] = $result['record']; break;
            case 'GET':
                $out[] = C::listRecords($store, $scope); break;
            case 'VIEW':
                $out[] = C::viewRecord($store, $scope, $step['ref']); break;
            case 'PATCH':
                $result = C::changeState($store, $scope, $step['ref'], $step['state']);
                $store = $result['store']; $out[] = $result['record']; break;
        }
    } catch (\DomainException $exception) { $out[] = ['error' => 'denied']; }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""
    result = subprocess.check_output(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script, str(SERVICE),
         json.dumps(steps)], cwd=ROOT, text=True, timeout=20,
    )
    return json.loads(result)


def op(method: str, tenant: str = "tenant-a", branch: str = "branch-a",
       **kwargs: object) -> dict:
    return {"method": method, "tenant": tenant, "branch": branch, **kwargs}


def payload(kind: str, ref: str) -> dict[str, str]:
    return {"record_ref": ref, "source_ref": "fixture:sgsst-source/demo",
            "kind": kind}


class M03HttpUiIntegracionTests(unittest.TestCase):
    def test_ac01_ten_kinds_through_real_php_service(self) -> None:
        ops = [op("POST", payload=payload(kind, f"fixture:sgsst-record/demo-{i}"))
               for i, kind in enumerate(KINDS)]
        got = execute(ops + [op("GET")])
        self.assertEqual(list(KINDS), [row["kind"] for row in got[:-1]])
        self.assertEqual(10, len(got[-1]))
        for row in got[:-1]:
            self.assertIs(row["synthetic_only"], True)
            self.assertIs(row["requires_professional_review"], True)
            self.assertIs(row["automatically_approved"], False)
            self.assertIs(row["automatically_closed"], False)

    def test_ac02_tenant_and_branch_reject_cross_scope(self) -> None:
        ref = "fixture:sgsst-record/demo"
        got = execute([op("POST", payload=payload("CONTROL", ref)),
                       op("GET", tenant="tenant-b"),
                       op("GET", branch="branch-b"),
                       op("VIEW", tenant="tenant-b", ref=ref),
                       op("PATCH", tenant="tenant-b", ref=ref, state="EN_REVISION"),
                       op("GET")])
        self.assertEqual([], got[1])
        self.assertEqual([], got[2])
        self.assertIsNone(got[3])
        self.assertEqual({"error": "denied"}, got[4])
        self.assertEqual([got[0]], got[5])

    def test_ac02_invalid_events_and_pii_fail_closed(self) -> None:
        ref = "fixture:sgsst-record/demo"
        bad = [
            payload("APPROVED", ref),
            {**payload("INVESTIGACION", ref), "email": "person@example.com"},
            {**payload("CONTROL", ref), "record_ref": "fixture:sgsst-record/12345678"},
            {**payload("COMITE_VIGIA", ref), "source_ref": "somebody@example.com"},
        ]
        got = execute([op("POST", payload=row) for row in bad]
                      + [op("POST", payload=payload("COMITE_VIGIA", ref)),
                         op("PATCH", ref=ref, state="APROBADO"),
                         op("PATCH", ref=ref, state="CERRADO"),
                         op("GET")])
        self.assertEqual([{"error": "denied"}] * 4, got[:4])
        self.assertEqual("PENDIENTE", got[4]["state"])
        self.assertEqual([{"error": "denied"}] * 2, got[5:7])
        self.assertEqual("PENDIENTE", got[7][0]["state"])

    def test_ac02_http_contract_auth_csrf_flag_and_no_store(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        section = source.split("// SG-SST intentionally uses a distinct synthetic session store")[1]
        self.assertEqual(4, section.count("#[Route("))
        for permission in ("'settings.view'", "'settings.create'", "'settings.update'"):
            self.assertIn(permission, section)
        for guard in ("authorizedBranchScope($branchId, $permission)",
                      "requireCsrf($request)",
                      "getenv('CONDOR_OCCUPATIONAL_SYNTHETIC_API')",
                      "getenv('CONDOR_PRODUCTION_STAGE')", "'construccion'",
                      "'condor_sgsst_synthetic'", "'private, no-store'"):
            self.assertIn(guard, section)
        self.assertIn("!in_array($environment, ['dev', 'test'], true)", section)
        self.assertLess(section.index("getenv('CONDOR_OCCUPATIONAL_SYNTHETIC_API')"),
                        section.index("authorizedBranchScope($branchId, $permission)"))
        self.assertEqual(4, section.count("occupationalAudit($tenant, $actor"))

    def test_ac03_dev_ui_http_and_four_states(self) -> None:
        source = UI.read_text(encoding="utf-8")
        segment = source.split("/** M03 HTTP: server-authorized synthetic records")[1]
        segment = segment.split("/** M06: independent synthetic compliance-evidence facade")[0]
        for expected in ("import.meta.env.DEV", "permissions.includes('settings.view')",
                         "permissions.includes('settings.create')",
                         "permissions.includes('settings.update')",
                         "credentials: 'same-origin'", "'X-CSRF-Token'",
                         "'loading'", "'ready'", "'denied'", "'error'",
                         "Acceso denegado", "Sin registros", "m03ValidRecord",
                         "synthetic_only", "Revisión profesional requerida",
                         "fetch (", "'POST'", "'PATCH'"):
            self.assertIn(expected, segment)
        self.assertNotIn("localStorage", segment)
        self.assertNotIn("sessionStorage", segment)
        self.assertNotIn("automatically_approved: true", segment)


if __name__ == "__main__":
    unittest.main()
