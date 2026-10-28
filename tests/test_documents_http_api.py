#!/usr/bin/env python3
"""Issue #1045: run the real PHP service against synthetic request sequences."""

from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Documents/DocumentApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/DocumentsController.php"

PHP_HARNESS = r'''<?php
require $argv[1];
$service = new \App\Application\Documents\DocumentApiService();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$state = [];
$out = [];
foreach ($input['steps'] as $step) {
    try {
        $scope = $step['scope'];
        switch ($step['action']) {
            case 'create':
                $result = $service->create($state, $scope, $step['reference'], $step['classification']);
                $state = $result['state'];
                $value = $result['document'];
                break;
            case 'list':
                $value = $service->listing($state, $scope);
                break;
            case 'detail':
                $value = $service->detail($state, $scope, $step['reference']);
                break;
            case 'review':
                $result = $service->requestReview($state, $scope, $step['reference']);
                $state = $result['state'];
                $value = ['document' => $result['document'], 'changed' => $result['changed']];
                break;
            case 'key':
                $value = $service::referenceFromKey($step['key']);
                break;
            default:
                throw new \DomainException('Unknown action');
        }
        $out[] = ['ok' => true, 'value' => $value];
    } catch (\DomainException $error) {
        $out[] = ['ok' => false, 'error' => $error->getMessage()];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
'''


def exercise(steps: list[dict]) -> list[dict]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI es necesario para validar el servicio documental.")
    run = subprocess.run(
        [php, "-r", PHP_HARNESS[5:], str(SERVICE)],
        cwd=ROOT, input=json.dumps({"steps": steps}), text=True,
        capture_output=True, check=False,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr + run.stdout)
    return json.loads(run.stdout)


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        results = exercise([
            {"action": "create", "scope": "tenant-a|company-a|branch-a",
             "reference": "fixture:doc/mismo", "classification": "INTERNA"},
            {"action": "create", "scope": "tenant-b|company-b|branch-b",
             "reference": "fixture:doc/mismo", "classification": "RESERVADA"},
            {"action": "list", "scope": "tenant-a|company-a|branch-a"},
            {"action": "list", "scope": "tenant-b|company-b|branch-b"},
            {"action": "list", "scope": "tenant-a|company-b|branch-a"},
            {"action": "detail", "scope": "tenant-b|company-a|branch-a",
             "reference": "fixture:doc/mismo"},
        ])
        self.assertTrue(all(r["ok"] for r in results[:5]))
        self.assertEqual(results[2]["value"][0]["classification"], "INTERNA")
        self.assertEqual(results[3]["value"][0]["classification"], "RESERVADA")
        self.assertEqual(results[4]["value"], [])
        self.assertFalse(results[5]["ok"])
        code = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("branch->tenant()->id()", code)
        self.assertIn("legalEntity()", code)
        self.assertIn("hash('sha256'", code)
        self.assertIn("$this->authorizedBranchScope(", code)

    def test_permisos_y_auditoria(self) -> None:
        code = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("use BranchApiSupport;", code)
        for permission in ("settings.view", "settings.create", "settings.update"):
            self.assertIn("'" + permission + "'", code)
        self.assertEqual(code.count("$this->requireCsrf($request);"), 2)
        self.assertEqual(code.count("$this->audit("), 4)
        for action in ("documents_synthetic.listed", "documents_synthetic.read",
                       "documents_synthetic.created", "documents_synthetic.review_requested"):
            self.assertIn(action, code)
        self.assertIn("$this->entityManager->flush();", code)
        self.assertIn("$this->payload($request, []);", code)
        self.assertIn("if ($result['changed'])", code)
        self.assertIn("['dev', 'test']", code)
        self.assertNotIn("'documents.view'", code)  # not yet present in PermissionCatalog

    def test_sin_datos_reales(self) -> None:
        invalid = ["CC-12345", "person@example.com", "https://example.com",
                   "fixture:doc/../x", "fixture:doc/123", "fixture:doc/demo/a",
                   "fixture:doc/nombre real", "fixture:doc/" + "x" * 200]
        steps = [
            {"action": "create", "scope": "test", "reference": value,
             "classification": "INTERNA"} for value in invalid
        ]
        steps.extend([
            {"action": "create", "scope": "test", "reference": "fixture:doc/seguro",
             "classification": "NOMBRES_REALES"},
            {"action": "create", "scope": "test", "reference": "fixture:doc/seguro",
             "classification": "PUBLICA"},
            {"action": "list", "scope": "test"},
            {"action": "key", "scope": "test", "key": "../../secreto"},
        ])
        results = exercise(steps)
        self.assertTrue(all(not x["ok"] for x in results[:9]))
        self.assertTrue(results[9]["ok"])
        self.assertEqual(len(results[10]["value"]), 1)
        self.assertFalse(results[11]["ok"])
        code = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("$this->payload($request, ['reference', 'classification'])", code)
        self.assertIn("Módulo documental no disponible.", code)
        self.assertIn("SESSION_KEY", code)
        service = SERVICE.read_text(encoding="utf-8")
        self.assertIn("MAX_DOCUMENTS = 100", service)
        self.assertNotIn("file_put_contents(", service)
        self.assertNotIn("HttpClient", service)

    def test_estados_explicitos(self) -> None:
        results = exercise([
            {"action": "create", "scope": "scope", "reference": "fixture:doc/demo",
             "classification": "INTERNA"},
            {"action": "review", "scope": "scope", "reference": "fixture:doc/demo"},
            {"action": "review", "scope": "scope", "reference": "fixture:doc/demo"},
            {"action": "detail", "scope": "scope", "reference": "fixture:doc/demo"},
            {"action": "list", "scope": "scope"},
        ])
        self.assertEqual(results[0]["value"]["status"], "BORRADOR")
        self.assertEqual(results[1]["value"]["document"]["status"], "PENDIENTE_REVISION")
        self.assertTrue(results[1]["value"]["changed"])
        self.assertFalse(results[2]["value"]["changed"])
        self.assertEqual(results[3]["value"]["status"], "PENDIENTE_REVISION")
        self.assertEqual(len(results[4]["value"]), 1)
        service = SERVICE.read_text(encoding="utf-8")
        self.assertNotIn("'CERRADO'", service)
        self.assertNotIn("'APROBADO'", service)
        self.assertIn("'PENDIENTE_REVISION'", service)
        for path in (SERVICE, CONTROLLER):
            run = subprocess.run([shutil.which("php") or "php", "-l", str(path)],
                                 text=True, capture_output=True, check=False)
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()
