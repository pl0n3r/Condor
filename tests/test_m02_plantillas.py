#!/usr/bin/env python3
"""GEST-M02: four executable real-PHP synthetic-only acceptance cases."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'src/Application/Contracts/ContractTemplatesProjection.php'


def fixture(tenant='tenant-a', company='fixture:company/example-a'):
    scope = {'tenant_ref': tenant, 'company_ref': company}
    return {
        **scope,
        'templates': [{**scope, 'ref': 'fixture:template/base-a', 'version': 2,
                       'reviewer_ref': 'fixture:reviewer/person-a'}],
        'contracts': [{**scope, 'ref': 'fixture:contract/sample-a',
                       'template_ref': 'fixture:template/base-a', 'template_version': 2,
                       'worker_ref': 'fixture:worker/worker-a'}],
        'addenda': [{**scope, 'ref': 'fixture:addendum/first-a',
                     'contract_ref': 'fixture:contract/sample-a', 'reason_key': 'SCOPE_CHANGE'}],
    }


def project(payload):
    php = r'''
require $argv[1];
use App\Application\Contracts\ContractTemplatesProjection;
$input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(ContractTemplatesProjection::project($input), JSON_THROW_ON_ERROR);
'''
    run = subprocess.run(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', php,
         str(SOURCE), json.dumps(payload)], cwd=ROOT, text=True,
        capture_output=True, check=True, timeout=20,
    )
    return json.loads(run.stdout)


class ContractTemplatesProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self):
        original = fixture()
        a = project(original)
        self.assertEqual(a, project(copy.deepcopy(original)))
        self.assertTrue(a['valid'])
        self.assertEqual('REQUIERE_REVISION', a['state'])
        self.assertEqual(2, a['templates'][0]['version'])
        self.assertTrue(a['templates'][0]['review_required'])
        self.assertEqual('fixture:contract/sample-a', a['contracts'][0]['ref'])
        self.assertEqual('fixture:addendum/first-a', a['addenda'][0]['ref'])
        self.assertEqual(original, fixture())

    def test_aislamiento_por_empresa(self):
        for tenant, company in [('tenant-a', 'fixture:company/example-a'),
                                ('tenant-b', 'fixture:company/example-b')]:
            with self.subTest(tenant=tenant):
                x = project(fixture(tenant, company))
                self.assertEqual(tenant, x['tenant_ref'])
                self.assertEqual(company, x['company_ref'])
                self.assertNotIn('example-b' if tenant == 'tenant-a' else 'example-a',
                                 json.dumps(x))
        bad = fixture()
        bad['contracts'][0]['tenant_ref'] = 'tenant-b'
        self.assertFalse(project(bad)['valid'])
        self.assertIsNone(project(bad)['tenant_ref'])
        bad = fixture()
        bad['addenda'][0]['company_ref'] = 'fixture:company/foreign'
        self.assertFalse(project(bad)['valid'])
        self.assertEqual([], project(bad)['addenda'])

    def test_rechaza_datos_reales(self):
        for category, name, content in [
            ('templates', 'reviewer_ref', 'person@example.com'),
            ('contracts', 'worker_ref', '3115551234'),
            ('contracts', 'worker_ref', '1020304050'),
            ('contracts', 'worker_ref', 'https://example.com/real'),
            ('addenda', 'ref', 'user@real.example'),
        ]:
            bad = fixture()
            bad[category][0][name] = content
            with self.subTest(content=content):
                x = project(bad)
                self.assertFalse(x['valid'])
                self.assertNotIn(content, json.dumps(x))
        bad = fixture()
        bad['contracts'][0]['worker_email'] = 'person@example.com'
        self.assertFalse(project(bad)['valid'])
        self.assertFalse(project({**fixture(), 'nit': '900123456'})['valid'])

    def test_sin_cierre_automatico(self):
        for patch in (
            {'templates': [{**fixture()['templates'][0], 'state': 'APROBADO'}]},
            {'contracts': [{**fixture()['contracts'][0], 'signature_validated': True}]},
            {'contracts': [{**fixture()['contracts'][0], 'template_version': 1}]},
            {'addenda': [{**fixture()['addenda'][0], 'reason_key': 'AUTO_RENEWED'}]},
            {'templates': fixture()['templates'] * 2},
            {'contracts': fixture()['contracts'] * 2},
            {'addenda': fixture()['addenda'] * 2},
        ):
            bad = {**fixture(), **copy.deepcopy(patch)}
            with self.subTest(patch=patch):
                x = project(bad)
                self.assertEqual('REQUIERE_REVISION', x['state'])
                self.assertNotIn('APROBADO', json.dumps(x))
                self.assertNotIn('CERRADO', json.dumps(x))
                self.assertFalse(x['valid'])
        self.assertFalse(project({'tenant_ref': 'tenant-a'})['valid'])
        good = project(fixture())
        self.assertFalse(good['contracts'][0]['signature_validated'])
        self.assertTrue(good['addenda'][0]['review_required'])
        src = SOURCE.read_text(encoding='utf-8').lower()
        for prohibited in ('pdo(', 'doctrine', 'curl_', 'file_get_contents(', 'entitymanager'):
            self.assertNotIn(prohibited, src)


if __name__ == '__main__':
    unittest.main()
