#!/usr/bin/env python3
"""M03 SG-SST: exercise the real PHP service and validate the guarded HTTP contract."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Compliance/OccupationalApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/ComplianceController.php"

PHP_SCRIPT = r"""
require $argv[1];
use App\Application\Compliance\OccupationalApiService as API;
$ops = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$store = [];
$out = [];
foreach ($ops as $op) {
    try {
        $scope = hash('sha256', $op['tenant'].'|'.$op['branch']);
        switch ($op['method']) {
            case 'POST':
                $r = API::createRecord($store, $scope, $op['payload']);
                $store = $r['store'];
                $out[] = $r['record'];
                break;
            case 'GET':
                $out[] = API::listRecords($store, $scope);
                break;
            case 'VIEW':
                $out[] = API::viewRecord($store, $scope, $op['ref']);
                break;
            case 'PATCH':
                $r = API::changeState($store, $scope, $op['ref'], $op['state']);
                $store = $r['store'];
                $out[] = $r['record'];
                break;
            case 'INJECT':
                $store[$scope][$op['ref']] = $op['payload'];
                $out[] = 'injected';
                break;
            default:
                $out[] = ['error' => 'denied'];
        }
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def execute(ops: list[dict]) -> list[object]:
    process = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", PHP_SCRIPT, str(SERVICE), json.dumps(ops)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(process.stdout)


def op(method: str, tenant: str = "tenant-a", branch: str = "branch-a", **fields: object) -> dict:
    return {"method": method, "tenant": tenant, "branch": branch, **fields}


REF = "fixture:sgsst-record/hazard-a"
PAYLOAD = {
    "record_ref": REF,
    "source_ref": "fixture:sgsst-source/projection-a",
    "kind": "PELIGRO",
}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        got = execute([
            op("POST", payload=PAYLOAD),
            op("GET", "tenant-b"),
            op("GET", "tenant-a", "branch-b"),
            op("VIEW", "tenant-b", ref=REF),
            op("POST", "tenant-b", payload=PAYLOAD),
            op("PATCH", "tenant-a", ref=REF, state="EN_REVISION"),
            op("GET", "tenant-b"),
            op("GET"),
        ])
        self.assertEqual([], got[1])
        self.assertEqual([], got[2])
        self.assertIsNone(got[3])
        self.assertEqual("PENDIENTE", got[4]["state"])
        self.assertEqual("EN_REVISION", got[5]["state"])
        self.assertEqual([got[4]], got[6])
        self.assertEqual([got[5]], got[7])
        self.assertEqual(True, got[5]["synthetic_only"])

    def test_permisos_y_auditoria(self) -> None:
        src = CONTROLLER.read_text(encoding="utf-8")
        occupational = src.split("// SG-SST intentionally uses a distinct synthetic session store")[1]
        self.assertEqual(4, occupational.count("#[Route("))
        self.assertEqual(4, occupational.count("$this->occupationalAccess($request, $branchId"))
        for permission in ("'settings.view'", "'settings.create'", "'settings.update'"):
            self.assertIn(permission, occupational)
        for method in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(method, occupational)
        self.assertIn("$this->authorizedBranchScope($branchId, $permission)", occupational)
        self.assertIn("$this->requireCsrf($request);", occupational)
        self.assertIn("getenv('CONDOR_OCCUPATIONAL_SYNTHETIC_API')", occupational)
        self.assertIn("getenv('CONDOR_PRODUCTION_STAGE')", occupational)
        self.assertIn("kernel.environment", occupational)
        self.assertIn("$this->entityManager->flush()", occupational)
        self.assertEqual(4, occupational.count("$this->occupationalAudit($tenant, $actor,"))
        self.assertIn("'sgsst.synthetic.'.$event", occupational)
        self.assertIn("hash('sha256', $tenant->id().'|'.$branch->id())", occupational)
        self.assertIn("['record_ref', 'source_ref', 'kind']", occupational)
        self.assertIn("$this->payload($request, ['state'])", occupational)
        self.assertIn("'condor_sgsst_synthetic'", occupational)
        self.assertLess(
            occupational.index("'created', $outcome['record']['record_ref']"),
            occupational.index("$request->getSession()->set('condor_sgsst_synthetic'"),
        )
        self.assertLess(
            occupational.index("'state_changed', $ref"),
            occupational.rindex("$request->getSession()->set('condor_sgsst_synthetic'"),
        )

    def test_sin_datos_reales(self) -> None:
        bad_payloads = [
            {**PAYLOAD, "record_ref": "103248999999"},
            {**PAYLOAD, "record_ref": "someone@example.com"},
            {**PAYLOAD, "record_ref": "fixture:sgsst-record/12345678"},
            {**PAYLOAD, "source_ref": "3115551234"},
            {**PAYLOAD, "source_ref": "fixture:sgsst-source/a1234567"},
            {**PAYLOAD, "source_ref": "fixture:sgsst-source/../../etc/passwd"},
            {**PAYLOAD, "source_ref": "fixture:sgsst-source/fake", "diagnosis": "private"},
            {**PAYLOAD, "name": "Real Person"},
            {**PAYLOAD, "email": "somebody@example.com"},
            {**PAYLOAD, "phone": "3115551234"},
            {**PAYLOAD, "kind": "UNKNOWN"},
            {**PAYLOAD, "source_ref": ["fixture:sgsst-source/fake"]},
            {**PAYLOAD, "kind": {"nested": "PERSON"}},
        ]
        got = execute([op("POST", payload=p) for p in bad_payloads])
        self.assertEqual([{"error": "denied"}] * len(bad_payloads), got)
        source = SERVICE.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "file_get_contents(", "httpclient", "doctrine", "diagnosis", "document_number"):
            self.assertNotIn(forbidden, source)
        self.assertNotIn("someone@example.com", json.dumps(got))

    def test_estados_explicitos(self) -> None:
        steps = [
            op("POST", payload=PAYLOAD),
            op("PATCH", ref=REF, state="EN_REVISION"),
            op("PATCH", ref=REF, state="CERRADO"),
            op("PATCH", ref=REF, state="APROBADO"),
            op("PATCH", ref=REF, state="CERTIFICADO"),
            op("PATCH", ref=REF, state="PENDIENTE"),
            op("PATCH", ref=REF, state="REQUIERE_REVISION"),
            op("VIEW", ref=REF),
            op("POST", payload=PAYLOAD),
        ]
        got = execute(steps)
        self.assertEqual(
            ["PENDIENTE", "EN_REVISION", "denied", "denied", "denied",
             "denied", "REQUIERE_REVISION", "REQUIERE_REVISION", "denied"],
            [x.get("state", "denied") if isinstance(x, dict) else "denied" for x in got],
        )
        for response in (got[0], got[1], got[6], got[7]):
            self.assertIs(response["automatically_approved"], False)
            self.assertIs(response["automatically_closed"], False)
            self.assertIs(response["requires_professional_review"], True)
        # Session tampering cannot upgrade a synthetic record into a legal result.
        forged = {**got[7], "state": "APROBADO"}
        self.assertEqual(
            ["injected", {"error": "denied"}],
            execute([op("INJECT", ref=REF, payload=forged), op("GET")]),
        )


if __name__ == "__main__":
    unittest.main()
