#!/usr/bin/env python3
"""GEST-M02 S04-S08: executable acceptance over real PHP, synthetic only."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'src/Application/Contracts/ContractLifecycleProjection.php'


def fixture(tenant='tenant-a', company='fixture:company/example-a'):
    scope = {'tenant_ref': tenant, 'company_ref': company}
    contract = 'fixture:contract/contract-a'
    termination = 'fixture:termination/termination-a'
    return {
        **scope,
        'reference_date': '2026-10-27',
        'contracts': [{**scope, 'ref': contract, 'worker_ref': 'fixture:worker/person-a',
                       'start_on': '2026-01-01', 'end_on': '2026-11-10'}],
        'signature_evidence': [{**scope, 'ref': 'fixture:signature-evidence/signature-a',
                                'contract_ref': contract, 'reviewer_ref': 'fixture:reviewer/reviewer-a',
                                'captured_on': '2026-01-02'}],
        'renewals': [{**scope, 'ref': 'fixture:renewal/renewal-a',
                      'contract_ref': contract, 'proposed_end_on': '2027-01-01',
                      'reviewer_ref': 'fixture:reviewer/reviewer-a'}],
        'terminations': [{**scope, 'ref': termination, 'contract_ref': contract,
                          'effective_on': '2026-11-10', 'reason_key': 'TERM_REVIEW',
                          'reviewer_ref': 'fixture:reviewer/reviewer-a'}],
        'settlement_drafts': [{**scope, 'ref': 'fixture:settlement-draft/settlement-a',
                               'termination_ref': termination,
                               'reviewer_ref': 'fixture:reviewer/reviewer-a'}],
    }


def project(payload):
    php = r'''
require $argv[1];
use App\Application\Contracts\ContractLifecycleProjection;
$input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(ContractLifecycleProjection::project($input), JSON_THROW_ON_ERROR);
'''
    run = subprocess.run(
        ['php', '-n', '-d', 'allow_url_fopen=0', '-r', php,
         str(SOURCE), json.dumps(payload)], cwd=ROOT, text=True,
        capture_output=True, check=True, timeout=20,
    )
    return json.loads(run.stdout)


class ContractLifecycleProjectionTests(unittest.TestCase):
    def test_resultado_determinista(self):
        source = fixture()
        first = project(source)
        self.assertEqual(first, project(copy.deepcopy(source)))
        self.assertEqual(source, fixture())
        self.assertTrue(first['valid'])
        self.assertEqual('REQUIERE_REVISION', first['state'])
        self.assertTrue(first['contracts'][0]['renewal_attention_candidate'])
        self.assertEqual('fixture:signature-evidence/signature-a', first['signature_evidence'][0]['ref'])
        self.assertEqual('2027-01-01', first['renewals'][0]['proposed_end_on'])
        self.assertEqual('SIN_VALOR_LEGAL', first['settlement_drafts'][0]['notice'])
        future = fixture()
        future['reference_date'] = '2026-01-01'
        self.assertFalse(project(future)['contracts'][0]['renewal_attention_candidate'])

    def test_aislamiento_por_empresa(self):
        for tenant, company in [('tenant-a', 'fixture:company/example-a'),
                                ('tenant-b', 'fixture:company/example-b')]:
            with self.subTest(tenant=tenant):
                output = project(fixture(tenant, company))
                self.assertTrue(output['valid'])
                self.assertEqual(tenant, output['tenant_ref'])
                self.assertEqual(company, output['company_ref'])
                self.assertNotIn('example-b' if tenant == 'tenant-a' else 'example-a',
                                 json.dumps(output))
        for category in ('contracts', 'signature_evidence', 'renewals', 'terminations',
                         'settlement_drafts'):
            bad = fixture()
            bad[category][0]['tenant_ref'] = 'tenant-other'
            with self.subTest(category=category):
                rejected = project(bad)
                self.assertFalse(rejected['valid'])
                self.assertIsNone(rejected['tenant_ref'])
                self.assertEqual([], rejected[category])
        bad = fixture()
        bad['renewals'][0]['company_ref'] = 'fixture:company/foreign'
        self.assertFalse(project(bad)['valid'])

    def test_rechaza_datos_reales(self):
        for category, field, value in [
            ('contracts', 'worker_ref', '1020304050'),
            ('contracts', 'worker_ref', 'person@example.com'),
            ('signature_evidence', 'reviewer_ref', '3115551234'),
            ('renewals', 'ref', 'persona@real.example'),
            ('terminations', 'reason_key', 'https://example.com/private'),
            ('settlement_drafts', 'ref', '900123456'),
        ]:
            bad = fixture()
            bad[category][0][field] = value
            with self.subTest(category=category, value=value):
                output = project(bad)
                self.assertFalse(output['valid'])
                self.assertNotIn(value, json.dumps(output))
        for patch in ({'email': 'person@example.com'}, {'phone': '3115551234'},
                      {'id_number': '1020304050'}):
            self.assertFalse(project({**fixture(), **patch})['valid'])
        bad = fixture()
        bad['contracts'][0]['worker_email'] = 'person@example.com'
        self.assertFalse(project(bad)['valid'])

    def test_sin_cierre_automatico(self):
        good = project(fixture())
        self.assertFalse(good['contracts'][0]['signature_validated'])
        self.assertFalse(good['contracts'][0]['closed'])
        self.assertFalse(good['signature_evidence'][0]['signature_validated'])
        self.assertTrue(good['signature_evidence'][0]['synthetic_evidence_only'])
        self.assertFalse(good['renewals'][0]['applied'])
        self.assertFalse(good['renewals'][0]['notification_sent'])
        self.assertFalse(good['terminations'][0]['contract_closed'])
        self.assertFalse(good['settlement_drafts'][0]['legal_value'])
        self.assertFalse(good['settlement_drafts'][0]['approved'])
        self.assertIsNone(good['settlement_drafts'][0]['amount_cop'])
        for patch in (
            ('contracts', 'state', 'CERRADO'),
            ('signature_evidence', 'signature_validated', True),
            ('renewals', 'applied', True),
            ('terminations', 'contract_closed', True),
            ('settlement_drafts', 'amount_cop', 100000),
            ('renewals', 'proposed_end_on', '2026-01-01'),
            ('terminations', 'reason_key', 'AUTO_TERMINATED'),
        ):
            bad = fixture()
            bad[patch[0]][0][patch[1]] = patch[2]
            with self.subTest(patch=patch):
                rejected = project(bad)
                self.assertFalse(rejected['valid'])
                self.assertEqual('REQUIERE_REVISION', rejected['state'])
                self.assertNotIn('CERRADO', json.dumps(rejected))
        for category in ('contracts', 'signature_evidence', 'renewals', 'terminations', 'settlement_drafts'):
            bad = fixture()
            bad[category] *= 2
            with self.subTest(duplicate=category):
                self.assertFalse(project(bad)['valid'])
        bad = fixture()
        bad['settlement_drafts'][0]['termination_ref'] = 'fixture:termination/nonexistent'
        self.assertFalse(project(bad)['valid'])
        src = SOURCE.read_text(encoding='utf-8').lower()
        for prohibited in ('pdo(', 'doctrine', 'curl_', 'file_get_contents(', 'entitymanager'):
            self.assertNotIn(prohibited, src)


if __name__ == '__main__':
    unittest.main()
