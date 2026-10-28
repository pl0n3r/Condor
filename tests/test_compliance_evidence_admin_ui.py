#!/usr/bin/env python3
"""#1040: compliance admin construction-only API/RBAC/fixture acceptance."""
from __future__ import annotations

from pathlib import Path
import json
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / 'frontend/admin/ComplianceManagement.tsx'
APP = ROOT / 'frontend/admin/AdminApp.tsx'
CONTROLLER = ROOT / 'src/Http/Controller/ComplianceController.php'


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        self.assertIn("key={'compliance-evidence-' + context.data.tenant.id + '-'", app)
        self.assertIn("tenantId", app)
        self.assertIn("branchId={context.data.active_branch.id}", app)
        self.assertIn("pathFor(branchId)", code)
        self.assertIn("encodeURIComponent(branchId)", code)
        self.assertIn("requestId.current", code)
        self.assertIn("credentials: 'same-origin'", code)
        self.assertIn("setSelected(null)", code)
        self.assertNotIn("localStorage", code)
        self.assertNotIn("sessionStorage", code)
        self.assertNotIn("indexedDB", code)
        self.assertNotIn("tenant_id:", code)
        self.assertNotIn("legal_entity_id:", code)
        self.assertIn("hash('sha256', $company->id().'|'.$office->id())", CONTROLLER.read_text())

        # Verify isolation through the actual production-side synthetic service.
        result = self._service(r"""
            $a = hash('sha256', 'empresa-a|sede-x');
            $b = hash('sha256', 'empresa-b|sede-x');
            $created = $svc::createRecord([], $a, [
                'evidence_ref' => 'fixture:evidence/demo',
                'obligation_ref' => 'fixture:obligation/demo',
                'type' => 'EVIDENCIA',
            ]);
            echo json_encode([
                'own' => count($svc::listRecords($created['store'], $a)),
                'other' => count($svc::listRecords($created['store'], $b)),
                'other_detail' => $svc::viewRecord($created['store'], $b, 'fixture:evidence/demo'),
            ], JSON_THROW_ON_ERROR);
        """)
        self.assertEqual(result, {'own': 1, 'other': 0, 'other_detail': None})

    def test_permisos_y_auditoria(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        for permission in ('settings.view', 'settings.create', 'settings.update'):
            self.assertIn(permission, code)
        for gate in ('if (!canView)', 'if (!canCreate', 'if (!canUpdate'):
            self.assertIn(gate, code)
        self.assertIn("'X-CSRF-Token'", code)
        self.assertIn("csrfToken={accessToken}", app)
        self.assertIn("permissions={context.data.permissions}", app)
        self.assertIn("response.status === 401 || response.status === 403", code)
        self.assertIn("auditada en servidor", code)
        self.assertIn("AuditEvent", (ROOT / "src/Http/Controller/BranchApiSupport.php").read_text())
        api = CONTROLLER.read_text()
        for verb in ("methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']"):
            self.assertIn(verb, api)
        self.assertIn("$this->authorizedBranchScope(", api)
        self.assertIn("$this->requireCsrf($request)", api)

    def test_sin_datos_reales(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        expressions = [
            r"const evidencePattern = /^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/;",
            r"const obligationPattern = /^fixture:obligation\/[a-z][a-z0-9_-]{0,47}$/;",
        ]
        for expression in expressions:
            # The actual regex is checked as a literal contract rather than evaluated in Python.
            self.assertIn(expression, code)
        self.assertIn("!/[0-9]{7,}/.test(value)", code)
        self.assertIn("!import.meta.env.DEV", code)
        self.assertIn("import.meta.env.DEV && (", app)
        self.assertIn("No se ha activado el módulo real", code)
        for forbidden in ("window.localStorage", "axios", "document_number", "tax_id:"):
            self.assertNotIn(forbidden, code)

        # Reject plausible real-world identifiers, path tricks, unknown types,
        # and extra tenant fields at the actual API service boundary.
        rejected = self._service(r"""
            $scope = hash('sha256', 'empresa-a|sede-x');
            $valid = ['evidence_ref' => 'fixture:evidence/demo',
                      'obligation_ref' => 'fixture:obligation/demo',
                      'type' => 'EVIDENCIA'];
            $cases = [
                array_replace($valid, ['evidence_ref' => 'persona@example.org']),
                array_replace($valid, ['evidence_ref' => 'fixture:evidence/../privado']),
                array_replace($valid, ['obligation_ref' => '12345678']),
                array_replace($valid, ['type' => 'DOCUMENTO_REAL']),
                array_replace($valid, ['tenant_id' => 'empresa-real']),
            ];
            $count = 0;
            foreach ($cases as $payload) {
                try {
                    $svc::createRecord([], $scope, $payload);
                } catch (\DomainException $ex) {
                    $count++;
                }
            }
            echo json_encode($count, JSON_THROW_ON_ERROR);
        """)
        self.assertEqual(rejected, 5)

    def test_estados_explicitos(self) -> None:
        code, app = PANEL.read_text(), APP.read_text()
        for value in ("PENDIENTE", "EN_REVISION", "REQUIERE_REVISION"):
            self.assertIn(value, code)
        self.assertIn("onSubmit={(event) => { void create(event); }}", code)
        self.assertIn("onSubmit={(event) => { void changeState(event); }}", code)
        self.assertIn("Ningún estado se cierra automáticamente", code)
        self.assertNotIn("state: 'CERRADA'", code)
        self.assertNotIn("state: 'APROBADA'", code)
        self.assertIn("type=\"button\"", code)
        self.assertIn("role=\"alert\"", code)
        self.assertIn("role=\"status\"", code)
        self.assertIn("aria-live=\"polite\"", code)
        self.assertIn("<DevComplianceEvidenceManagement", app)

        # No implicit approval or closure is permitted by the service.
        states = self._service(r"""
            $scope = hash('sha256', 'empresa-a|sede-x');
            $created = $svc::createRecord([], $scope, [
                'evidence_ref' => 'fixture:evidence/demo',
                'obligation_ref' => 'fixture:obligation/demo',
                'type' => 'EVALUACION',
            ]);
            $initial = $created['record']['state'];
            $next = $svc::changeState($created['store'], $scope, 'fixture:evidence/demo', 'EN_REVISION');
            $rejected = false;
            try {
                $svc::changeState($next['store'], $scope, 'fixture:evidence/demo', 'APROBADA');
            } catch (\DomainException $ex) {
                $rejected = true;
            }
            echo json_encode([$initial, $next['record']['state'], $rejected,
                $svc::viewRecord($next['store'], $scope, 'fixture:evidence/demo')['state']],
                JSON_THROW_ON_ERROR);
        """)
        self.assertEqual(states, ['PENDIENTE', 'EN_REVISION', True, 'EN_REVISION'])


    def _service(self, body: str) -> object:
        """Run real synthetic business rules in PHP, not source-text simulations."""
        php = shutil.which('php')
        if php is None:
            self.skipTest('PHP CLI no disponible para ejercitar el servicio sintético')
        service_path = ROOT / 'src/Application/Compliance/ComplianceApiService.php'
        program = (
            'require ' + json.dumps(str(service_path)) + ';'
            '$svc = \\App\\Application\\Compliance\\ComplianceApiService::class;\n'
            + body
        )
        completed = subprocess.run(
            [php, '-r', program], cwd=ROOT, capture_output=True,
            text=True, timeout=15, check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        return json.loads(completed.stdout)


if __name__ == '__main__':
    unittest.main()
