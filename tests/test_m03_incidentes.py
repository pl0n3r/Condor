"""SG-SST M03 synthetic-only contract. Exercises actual PHP; no network, mocks or skips."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_FILE = ROOT / 'src/Application/Compliance/OccupationalIncidentInvestigation.php'
SCRIPT = r'''
require $argv[1];
use App\Application\Compliance\OccupationalIncidentInvestigation;
try {
    $data = json_decode($argv[2], true, 64, JSON_THROW_ON_ERROR);
    echo json_encode(OccupationalIncidentInvestigation::project($data), JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'REJECTED_SYNTHETIC_FIXTURE');
    exit(4);
}
'''


def fixture(tenant: str = 'fixture:tenant/empresa-a') -> dict:
    return {
        'tenant_ref': tenant,
        'reference_date': '2026-10-27',
        'incidents': [{
            'tenant_ref': tenant, 'incident_ref': 'fixture:incident/evento-a',
            'kind': 'INCIDENT', 'occurred_on': '2026-10-15',
            'reported_on': '2026-10-16', 'summary_ref': 'fixture:summary/reporte-a',
        }],
        'investigations': [{
            'tenant_ref': tenant, 'incident_ref': 'fixture:incident/evento-a',
            'started_on': '2026-10-17', 'due_on': '2026-10-22',
            'deadline_ref': 'fixture:deadline/plazo-a',
            'actions': [{'action_ref': 'fixture:action/accion-a',
                         'description_ref': 'fixture:summary/medida-a'}],
        }],
        'sensitive_fixtures': [{
            'tenant_ref': tenant, 'incident_ref': 'fixture:incident/evento-a',
            'sensitive_ref': 'fixture:sensitive/participante-a',
        }],
    }


def run_php(payload: dict) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', SCRIPT, str(PHP_FILE),
         json.dumps(payload, ensure_ascii=False)], cwd=ROOT,
        capture_output=True, text=True, timeout=15, check=False,
    )


def project(payload: dict) -> dict:
    result = run_php(payload)
    if result.returncode != 0:
        raise AssertionError(result.stderr)
    return json.loads(result.stdout)


class OccupationalIncidentInvestigationTests(unittest.TestCase):
    def test_resultado_determinista(self):
        data = fixture()
        self.assertEqual(project(data), project(copy.deepcopy(data)))
        result = project(data)
        self.assertEqual(result['tenant_ref'], 'fixture:tenant/empresa-a')
        self.assertEqual(result['incidents'][0]['deadline']['due_on'], '2026-10-22')
        self.assertTrue(result['incidents'][0]['deadline']['attention_candidate'])
        self.assertEqual(result['sensitive_compartment']['redacted_count'], 1)
        self.assertNotIn('fixture:sensitive/participante-a', json.dumps(result))

    def test_aislamiento_por_empresa(self):
        a = fixture()
        b = fixture('fixture:tenant/empresa-b')
        combined = copy.deepcopy(a)
        for field in ('incidents', 'investigations', 'sensitive_fixtures'):
            combined[field].extend(copy.deepcopy(b[field]))
        first = project(combined)
        combined['tenant_ref'] = 'fixture:tenant/empresa-b'
        second = project(combined)
        self.assertEqual(len(first['incidents']), 1)
        self.assertEqual(len(second['incidents']), 1)
        self.assertNotEqual(first['tenant_ref'], second['tenant_ref'])
        self.assertEqual(first['sensitive_compartment']['redacted_count'], 1)
        self.assertEqual(second['sensitive_compartment']['redacted_count'], 1)
        self.assertNotIn('empresa-b', json.dumps(first))
        self.assertNotIn('empresa-a', json.dumps(second))

    def test_rechaza_datos_reales(self):
        source = fixture()
        variants = []
        for field, value in (
            ('email', 'real@empresa.co'), ('cedula', '1234567890'),
            ('telefono', '+573001112233'), ('name', 'Persona Real'),
            ('status', 'CERRADO'),
        ):
            changed = copy.deepcopy(source)
            changed['incidents'][0][field] = value
            variants.append(changed)
        for key, value in (
            ('tenant_ref', '900123456'), ('tenant_ref', 'empresa-real'),
        ):
            changed = copy.deepcopy(source)
            changed[key] = value
            variants.append(changed)
        changed = copy.deepcopy(source)
        changed['sensitive_fixtures'][0]['sensitive_ref'] = 'persona@empresa.co'
        variants.append(changed)
        changed = copy.deepcopy(source)
        changed['incidents'][0]['summary_ref'] = 'fixture:summary/123456789'
        variants.append(changed)
        for data in variants:
            with self.subTest(data=data):
                result = run_php(data)
                self.assertEqual(result.returncode, 4, result.stderr)
                self.assertEqual(result.stdout, '')
                self.assertEqual(result.stderr, 'REJECTED_SYNTHETIC_FIXTURE')

    def test_sin_cierre_automatico(self):
        result = project(fixture())
        self.assertTrue(result['requires_professional_review'])
        self.assertFalse(result['automatic_approval'])
        self.assertFalse(result['automatic_closure'])
        row = result['incidents'][0]
        self.assertTrue(row['requires_professional_review'])
        self.assertFalse(row['automatic_approval'])
        self.assertFalse(row['automatic_closure'])
        self.assertEqual(row['investigation_state'], 'EN_REVISION')
        self.assertEqual(row['actions'][0]['state'], 'PENDIENTE_REVISION')
        self.assertEqual(row['deadline']['basis'], 'FIXTURE_ONLY_NOT_LEGAL')
        without = fixture()
        without['investigations'] = []
        missing = project(without)['incidents'][0]
        self.assertEqual(missing['investigation_state'], 'PENDIENTE_REVISION')
        self.assertIsNone(missing['deadline'])
        self.assertEqual(missing['actions'], [])

    def test_falla_cerrado_ante_plazos_o_referencias_ambiguos(self):
        for change in ('date', 'duplicate', 'duplicate_incident', 'unknown', 'action', 'badlist'):
            data = fixture()
            if change == 'date':
                data['investigations'][0]['due_on'] = '2026-02-30'
            elif change == 'duplicate':
                data['investigations'].append(copy.deepcopy(data['investigations'][0]))
            elif change == 'duplicate_incident':
                data['incidents'].append(copy.deepcopy(data['incidents'][0]))
            elif change == 'unknown':
                data['investigations'][0]['incident_ref'] = 'fixture:incident/noexiste'
            elif change == 'action':
                data['investigations'][0]['actions'][0]['description_ref'] = 'real-persona'
            else:
                data['incidents'] = {'row': data['incidents'][0]}
            with self.subTest(change=change):
                self.assertEqual(run_php(data).returncode, 4)


if __name__ == '__main__':
    unittest.main()
