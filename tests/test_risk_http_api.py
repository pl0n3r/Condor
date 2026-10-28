#!/usr/bin/env python3
"""M12 #1051: offline behavior plus HTTP controller authorization contracts."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Risk/RiskApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/RiskController.php"

PHP_SMOKE = r'''
require $argv[1];
use App\Application\Risk\RiskApiService as R;
$ops = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$store = [];
$out = [];
foreach ($ops as $op) {
    try {
        $scope = hash('sha256', $op['tenant'].'|'.$op['branch']);
        switch ($op['method']) {
            case 'POST':
                $result = R::createRecord($store, $scope, $op['payload']);
                $store = $result['store'];
                $out[] = $result['record'];
                break;
            case 'GET':
                $out[] = R::listRecords($store, $scope);
                break;
            case 'VIEW':
                $out[] = R::viewRecord($store, $scope, $op['ref']);
                break;
            case 'PATCH':
                $result = R::changeState($store, $scope, $op['ref'], $op['state']);
                $store = $result['store'];
                $out[] = $result['record'];
                break;
            default:
                throw new \DomainException('Unsupported');
        }
    } catch (\DomainException $e) {
        $out[] = ['error' => 'denied'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
'''


def run(ops: list[dict]) -> list[object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI requerido")
    result = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_SMOKE,
         str(SERVICE), json.dumps(ops)],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


def op(method: str, tenant: str = "tenant-a", branch: str = "branch-a", **params: object) -> dict:
    return {"method": method, "tenant": tenant, "branch": branch, **params}


def payload(ref: str = "fixture:risk/sample") -> dict:
    return {"risk_ref": ref, "category": "operativo", "probability": 3, "impact": 4}


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        got = run([
            op("POST", payload=payload()),
            op("GET", "tenant-b"),
            op("GET", "tenant-a", "branch-b"),
            op("VIEW", "tenant-b", ref="fixture:risk/sample"),
            op("GET"),
        ])
        self.assertEqual([], got[1])
        self.assertEqual([], got[2])
        self.assertIsNone(got[3])
        self.assertEqual([got[0]], got[4])
        self.assertIn(
            "hash('sha256', $company->id().'|'.$office->id())",
            CONTROLLER.read_text(encoding="utf-8"),
        )

    def test_permisos_y_auditoria(self) -> None:
        text = CONTROLLER.read_text(encoding="utf-8")
        for value in (
            "'settings.view'", "'settings.create'", "'settings.update'",
            "use BranchApiSupport;", "$this->authorizedBranchScope(",
            "$this->requireCsrf($request);", "$this->audit(", "$this->entityManager->flush()",
            "'risk.synthetic.'.$event", "methods: ['GET']", "methods: ['POST']",
            "methods: ['PATCH']", "getenv('CONDOR_RISK_SYNTHETIC_API')",
            "getenv('CONDOR_PRODUCTION_STAGE')", "kernel.environment",
        ):
            self.assertIn(value, text)
        self.assertEqual(4, text.count("$this->access($request, $branchId,"))
        self.assertEqual(4, text.count("$this->auditPreview("))
        self.assertEqual(2, text.count(", true);"))
        self.assertIn("risk.synthetic.", text)
        self.assertIn("'synthetic_only' => true", text)
        self.assertIn("'test'", text)
        self.assertIn("'dev'", text)
        self.assertNotIn("$request->request->get('tenant", text)
        self.assertNotIn("$payload['tenant_id']", text)
        self.assertLess(text.index("'created'"), text.index("set('condor_risk_synthetic'"))
        self.assertLess(text.index("'state_changed'"), text.rindex("set('condor_risk_synthetic'"))

    def test_sin_datos_reales(self) -> None:
        cases = [
            {**payload(), "risk_ref": "someone@example.com"},
            {**payload(), "risk_ref": "3115551212"},
            {**payload(), "risk_ref": "fixture:risk/123456789"},
            {**payload(), "risk_ref": "https://example.com"},
            {**payload(), "person_name": "Actual Person"},
            {**payload(), "category": "real-person"},
            {**payload(), "probability": "5"},
            {**payload(), "impact": 7},
        ]
        for bad in cases:
            with self.subTest(payload=bad):
                self.assertEqual([{"error": "denied"}], run([op("POST", payload=bad)]))
        text = SERVICE.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "httpclient", "file_get_contents(", "document_number"):
            self.assertNotIn(forbidden, text)
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("$environment !== 'dev'", controller)

    def test_estados_explicitos(self) -> None:
        ref = "fixture:risk/sample"
        got = run([
            op("POST", payload=payload(ref)),
            op("PATCH", ref=ref, state="EN_REVISION"),
            op("PATCH", ref=ref, state="APROBADO"),
            op("PATCH", ref=ref, state="CERRADO"),
            op("PATCH", ref=ref, state="EN_REVISION"),
            op("PATCH", ref=ref, state="REQUIERE_REVISION"),
            op("VIEW", ref=ref),
            op("POST", payload=payload(ref)),
        ])
        self.assertEqual(
            ["PENDIENTE", "EN_REVISION", "denied", "denied", "denied",
             "REQUIERE_REVISION", "REQUIERE_REVISION", "denied"],
            [r.get("state", "denied") for r in got],
        )
        self.assertNotIn("CERRADO", json.dumps(got))
        self.assertEqual([{"error": "denied"}],
                         run([op("PATCH", ref=ref, state="EN_REVISION")]))


if __name__ == "__main__":
    unittest.main()
