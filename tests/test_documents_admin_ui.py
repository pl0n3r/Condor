#!/usr/bin/env python3
"""Contratos reales de funciones TSX M08, compiladas con TypeScript y Node; sin mocks de lógica."""
from __future__ import annotations
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / 'frontend' / 'admin' / 'DocumentsManagement.tsx'
NODE_HARNESS = r'''
const fs = require('fs');
const vm = require('vm');
const ts = require('typescript');
const filename = process.argv[1];
const scenarios = JSON.parse(process.argv[2]);
const compiled = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
 compilerOptions: { target: ts.ScriptTarget.ES2020, module: ts.ModuleKind.CommonJS,
 jsx: ts.JsxEmit.ReactJSX }, fileName: filename
}).outputText.replace(/import\.meta\.env\.DEV/g, 'false');
const module = { exports: {} };
vm.runInNewContext(compiled, { module, exports: module.exports,
  require: (key) => {
    if (key === 'react') return {useState: () => { throw Error('No UI in unit test'); }};
    if (key === 'react/jsx-runtime') return { jsx() {}, jsxs() {} };
    throw Error('Untrusted dependency: ' + key);
  }
}, {filename});
const p = module.exports;
const output = scenarios.map(s => {
  let state = p.initialDocumentWorkspace(s.scope);
  for (const step of s.steps) state = p.applyDocumentAction(state, step.scope || s.scope, step.permissions, step.action);
  return {state, visible: p.visibleSyntheticDocuments(state, s.scope, s.canView, 'TODOS')};
});
process.stdout.write(JSON.stringify(output));
'''

VIEW = {'view': True, 'create': False, 'update': False}
ALL = {'view': True, 'create': True, 'update': True}
NONE = {'view': False, 'create': False, 'update': False}


def run_scenarios(*scenarios: dict) -> list[dict]:
    result = subprocess.run(
        ['node', '-e', NODE_HARNESS, str(COMPONENT), json.dumps(scenarios)],
        check=True, text=True, capture_output=True, cwd=ROOT,
        env={**os.environ, 'NODE_PATH': os.pathsep.join((
            str(ROOT / 'node_modules'),
            subprocess.check_output(['npm', 'root', '-g'], text=True).strip(),
        ))},
    )
    return json.loads(result.stdout)


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        a, b = run_scenarios(
            {'scope': 'tenant-A|empresa-1|sede-1', 'canView': True, 'steps': [
                {'permissions': ALL, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/mismo', 'classification': 'INTERNA'}}
            ]},
            {'scope': 'tenant-B|empresa-2|sede-1', 'canView': True, 'steps': [
                {'permissions': ALL, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/mismo', 'classification': 'INTERNA'}}
            ]},
        )
        self.assertNotEqual(a['state']['documents'][0]['scope'], b['state']['documents'][0]['scope'])
        self.assertEqual(len(a['visible']), 1)
        self.assertEqual(len(b['visible']), 1)
        cross = run_scenarios({'scope': 'tenant-A|empresa-1|sede-1', 'canView': True, 'steps': [
            {'permissions': ALL, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/origen', 'classification': 'INTERNA'}},
            {'scope': 'tenant-B|empresa-2|sede-1', 'permissions': ALL, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/otro', 'classification': 'INTERNA'}},
        ]})[0]
        self.assertEqual(cross['visible'], [])
        self.assertEqual(cross['state']['scope'], 'tenant-B|empresa-2|sede-1')

    def test_permisos_y_auditoria(self) -> None:
        result = run_scenarios({'scope': 't|empresa|sede', 'canView': True, 'steps': [
            {'permissions': NONE, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/demo', 'classification': 'PUBLICA'}},
            {'permissions': ALL, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/demo', 'classification': 'PUBLICA'}},
            {'permissions': VIEW, 'action': {'type': 'VIEW', 'reference': 'fixture:doc/demo'}},
            {'permissions': VIEW, 'action': {'type': 'REQUEST_REVIEW', 'reference': 'fixture:doc/demo'}},
            {'permissions': ALL, 'action': {'type': 'REQUEST_REVIEW', 'reference': 'fixture:doc/demo'}},
        ]})[0]
        self.assertEqual([e['action'] for e in result['state']['events']],
                         ['CREAR', 'CONSULTAR', 'SOLICITAR_REVISION'])
        self.assertEqual(result['state']['documents'][0]['status'], 'PENDIENTE_REVISION')
        self.assertIn('documents.view', COMPONENT.read_text())
        self.assertIn('documents.create', COMPONENT.read_text())
        self.assertIn('documents.update', COMPONENT.read_text())
        self.assertNotIn('fetch(', COMPONENT.read_text())

    def test_sin_datos_reales(self) -> None:
        invalid = ('alguien@example.com', '3115551212', '1002030405', 'https://example.co',
                   'fixture:doc/../otro', 'fixture:doc/usuario@correo.com', 'fixture:doc/x/otro')
        result = run_scenarios({'scope': 't|empresa|sede', 'canView': True, 'steps': [
            {'permissions': ALL, 'action': {'type': 'CREATE', 'reference': value, 'classification': 'PUBLICA'}}
            for value in invalid
        ]})[0]
        self.assertEqual(result['state']['documents'], [])
        code = COMPONENT.read_text()
        for unwanted in ('localStorage', 'sessionStorage', 'indexedDB', 'axios', 'XMLHttpRequest'):
            self.assertNotIn(unwanted, code)
        self.assertIn('if (!import.meta.env.DEV) return null;', code)

    def test_estados_explicitos(self) -> None:
        result = run_scenarios({'scope': 't|empresa|sede', 'canView': True, 'steps': [
            {'permissions': ALL, 'action': {'type': 'CREATE', 'reference': 'fixture:doc/revision', 'classification': 'RESERVADA'}},
            {'permissions': ALL, 'action': {'type': 'REQUEST_REVIEW', 'reference': 'fixture:doc/revision'}},
            {'permissions': ALL, 'action': {'type': 'REQUEST_REVIEW', 'reference': 'fixture:doc/revision'}},
        ]})[0]
        self.assertEqual(result['state']['documents'][0]['status'], 'PENDIENTE_REVISION')
        self.assertEqual(len(result['state']['events']), 2)
        code = COMPONENT.read_text()
        self.assertIn('role="alert"', code)
        self.assertIn('role="status"', code)
        self.assertIn('type="button"', code)
        self.assertIn('No hay aprobación, cierre ni borrado automático.', code)
        self.assertNotIn("status: 'APROBADO'", code)
        self.assertNotIn("status: 'CERRADO'", code)


if __name__ == '__main__':
    unittest.main()