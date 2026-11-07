/** GEST-M02 S04–S08: sólo fixtures, nunca integración productiva. */
import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { mkdtempSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { build } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const tmp = mkdtempSync(join(root, 'node_modules', '.cache-contract-lifecycle-'));
after(() => rmSync(tmp, { recursive: true, force: true }));
const outfile = join(tmp, 'component.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/ContractsLifecyclePreview.tsx')],
  outfile, bundle: true, format: 'cjs', platform: 'node', jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'], define: { 'import.meta.env.DEV': 'true' },
  logLevel: 'silent' });
const { syntheticLifecycleScope, syntheticLifecycleRequest, ContractsLifecyclePreview } =
  createRequire(import.meta.url)(outfile);
const options = { enabled: true, allowed: true, tenantId: 'tenant-a', companyId: 'empresa-a',
  branchId: 'sede-a', csrf: 'csrf-sintetico', referenceDate: '2026-11-07' };
const sha = s => createHash('sha256').update(s).digest('hex').slice(0, 15);
const expected = { tenant_ref: 'tenant-test-t' + sha(options.tenantId),
  company_ref: 'fixture:company/c' + sha('tenant-a|empresa-a|sede-a') };
const ref = name => 'fixture:' + name + '/demo';
const fakeResponse = (scope = expected) => ({
  lifecycle_preview: { valid: true, state: 'REQUIERE_REVISION', ...scope, error: null,
    contracts: [{ ref: ref('contract'), worker_ref: ref('worker'), start_on: '2026-01-01',
      end_on: '2030-12-31', state: 'REQUIERE_REVISION', signature_validated: false,
      closed: false, renewal_attention_candidate: false }],
    signature_evidence: [{ ref: ref('signature-evidence'), contract_ref: ref('contract'),
      captured_on: '2026-11-07', state: 'REQUIERE_REVISION',
      synthetic_evidence_only: true, signature_validated: false }],
    renewals: [{ ref: ref('renewal'), contract_ref: ref('contract'),
      proposed_end_on: '2031-12-31', state: 'REQUIERE_REVISION',
      applied: false, notification_sent: false }],
    terminations: [{ ref: ref('termination'), contract_ref: ref('contract'),
      effective_on: '2026-11-07', reason_key: 'TERM_REVIEW',
      state: 'REQUIERE_REVISION', contract_closed: false }],
    settlement_drafts: [{ ref: ref('settlement-draft'), termination_ref: ref('termination'),
      state: 'REQUIERE_REVISION', legal_value: false, notice: 'SIN_VALOR_LEGAL',
      amount_cop: null, approved: false }],
  }, synthetic_only: true, requires_human_review: true, legal_value: false,
  signature_validated: false, notification_sent: false,
});
const http = (data, status = 200) => new Response(JSON.stringify(data), { status,
  headers: { 'Content-Type': 'application/json' } });

test('OFF, sin permiso o CSRF: no crea requests ni lee datos', async () => {
  let calls = 0;
  const forbidden = () => { calls++; throw Error('HTTP no autorizado'); };
  for (const input of [
    { ...options, enabled: false }, { ...options, allowed: false },
    { ...options, companyId: '__unassigned__' }, { ...options, csrf: '' },
    { ...options, tenantId: 'empresa con espacios' }, { ...options, referenceDate: 'ayer' },
  ]) {
    const result = await syntheticLifecycleRequest(input, forbidden);
    assert.notEqual(result.status, 'ready');
  }
  assert.equal(calls, 0);
});

test('S04–S08: scope SHA256, POST, CSRF, revisión humana sin efectos legales', async () => {
  assert.deepEqual(await syntheticLifecycleScope('tenant-a', 'empresa-a', 'sede-a'), expected);
  let count = 0;
  const result = await syntheticLifecycleRequest(options, async (url, init) => {
    count++;
    assert.equal(url, '/api/v1/branches/sede-a/contracts/lifecycle/preview');
    assert.equal(init.method, 'POST');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf-sintetico');
    const data = JSON.parse(init.body);
    assert.deepEqual({ tenant_ref: data.tenant_ref, company_ref: data.company_ref }, expected);
    assert.deepEqual(Object.keys(data).sort(), ['tenant_ref', 'company_ref', 'reference_date',
      'contracts', 'signature_evidence', 'renewals', 'terminations', 'settlement_drafts'].sort());
    assert.equal(data.contracts.length, 1);
    assert.equal(data.signature_evidence.length, 1);
    assert.equal(data.renewals[0].contract_ref, data.contracts[0].ref);
    assert.equal(data.terminations[0].reason_key, 'TERM_REVIEW');
    assert.equal(data.settlement_drafts[0].termination_ref, data.terminations[0].ref);
    assert.equal(JSON.stringify(data).includes('identificacion'), false);
    return http(fakeResponse());
  });
  assert.equal(count, 1);
  assert.equal(result.status, 'ready');
  assert.equal(result.preview?.signature_evidence.length, 1);
  assert.equal(result.preview?.settlement_drafts[0].amount_cop, null);
});

test('Aislamiento por sede y empresa: la respuesta cruzada falla cerrada', async () => {
  const other = { ...options, branchId: 'sede-b' };
  const different = await syntheticLifecycleScope('tenant-a', 'empresa-a', 'sede-b');
  assert.notDeepEqual(different, expected);
  assert.equal((await syntheticLifecycleRequest(other, () => http(fakeResponse()))).status, 'error');
  assert.equal((await syntheticLifecycleRequest(options, () => http(fakeResponse({
    ...expected, tenant_ref: 'tenant-test-t000000000000000',
  })))).status, 'error');
});

test('401/403/404, red y JSON inválido reportan estados distintos', async () => {
  for (const status of [401, 403]) {
    assert.equal((await syntheticLifecycleRequest(options, () => http({}, status))).status, 'denied');
  }
  assert.equal((await syntheticLifecycleRequest(options, () => http({}, 404))).status, 'disabled');
  assert.equal((await syntheticLifecycleRequest(options, () => http({}, 422))).status, 'error');
  assert.equal((await syntheticLifecycleRequest(options, () => { throw Error('network'); })).status,
    'error');
  assert.equal((await syntheticLifecycleRequest(options,
    () => new Response('{', { status: 200 }))).status, 'error');
});

test('Respuesta falsificada: nunca certifica firma, notificación o liquidación', async () => {
  const mutations = [
    data => { data.synthetic_only = false; },
    data => { data.requires_human_review = false; },
    data => { data.legal_value = true; },
    data => { data.signature_validated = true; },
    data => { data.notification_sent = true; },
    data => { data.lifecycle_preview.valid = false; },
    data => { data.lifecycle_preview.state = 'APROBADO'; },
    data => { data.lifecycle_preview.contracts[0].closed = true; },
    data => { data.lifecycle_preview.signature_evidence[0].signature_validated = true; },
    data => { data.lifecycle_preview.renewals[0].applied = true; },
    data => { data.lifecycle_preview.terminations[0].contract_closed = true; },
    data => { data.lifecycle_preview.settlement_drafts[0].amount_cop = 100000; },
    data => { data.lifecycle_preview.settlement_drafts[0].approved = true; },
    data => { data.lifecycle_preview.contracts[0].worker_name = 'Persona real'; },
    data => { data.lifecycle_preview.renewals.push(data.lifecycle_preview.renewals[0]); },
  ];
  for (const mutate of mutations) {
    const value = fakeResponse(); mutate(value);
    assert.equal((await syntheticLifecycleRequest(options, () => http(value))).status, 'error');
  }
});

test('Estado vacío y guard de montaje servidor, sin datos de prueba al apagar', async () => {
  const data = fakeResponse();
  for (const section of ['contracts','signature_evidence','renewals','terminations','settlement_drafts']) {
    data.lifecycle_preview[section] = [];
  }
  assert.equal((await syntheticLifecycleRequest(options, () => http(data))).status, 'empty');
  const original = globalThis.document;
  try {
    globalThis.document = { getElementById: () => ({ dataset: { contractsSyntheticApiEnabled: 'false' } }) };
    const off = renderToStaticMarkup(React.createElement(ContractsLifecyclePreview, {
      tenantId: 'tenant-a', companyId: 'empresa-a', branchId: 'sede-a',
      permissions: ['inventory.view'],
    }));
    assert.match(off, /deshabilitada por el servidor/);
    assert.doesNotMatch(off, /Contratos revisables|fixture:/);
    globalThis.document = { getElementById: () => ({ dataset: { contractsSyntheticApiEnabled: 'true' } }) };
    const denied = renderToStaticMarkup(React.createElement(ContractsLifecyclePreview, {
      tenantId: 'tenant-a', companyId: 'empresa-a', branchId: 'sede-a', permissions: [],
    }));
    assert.match(denied, /Sin permiso/);
  } finally { globalThis.document = original; }
});
