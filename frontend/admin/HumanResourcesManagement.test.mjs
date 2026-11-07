/** Synthetic-only API and closed-by-default HR UI regressions for #1651. */
import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { build } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const temp = mkdtempSync(join(root, 'node_modules', '.cache-hr1651-'));
const output = join(temp, 'component.cjs');
after(() => rmSync(temp, { recursive: true, force: true }));
await build({ entryPoints: [join(root, 'frontend/admin/HumanResourcesManagement.tsx')],
  outfile: output, platform: 'node', format: 'cjs', bundle: true, jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'], logLevel: 'silent' });
const {
  HumanResourcesManagement, isHrRealDataEnabled,
  readHrRecords, readHrDetail, writeHrRecord,
} = createRequire(import.meta.url)(output);

const tenantId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const companyId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const branchId = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const record = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAZ', tenant_id: tenantId,
  company_id: companyId, record_ref: 'demo_interno', collaborator_ref: 'colaborador_demo',
  position_ref: 'cargo_demo', kind: 'vinculacion', state: 'BORRADOR', version: 1 };
const on = { enabled: true, allowed: true, tenantId, companyId, branchId };
const endpoint = `/api/v1/branches/${branchId}/hr/records`;
const json = (value, status = 200) => new Response(JSON.stringify(value), {
  status, headers: { 'Content-Type': 'application/json' },
});

test('interruptor ausente/apagado: sin solicitudes HTTP, sin datos y mensaje seguro', async () => {
  let calls = 0;
  const failTransport = () => { calls += 1; throw Error('no HTTP allowed'); };
  for (const value of [undefined, '', 'false', 'TRUE', '1']) {
    const rootNode = value === undefined ? null : { dataset: { hrRealDataEnabled: value } };
    assert.equal(isHrRealDataEnabled(rootNode), false);
  }
  assert.equal(isHrRealDataEnabled({ dataset: { hrRealDataEnabled: 'true' } }), true);
  const off = { ...on, enabled: false };
  assert.deepEqual(await readHrRecords(off, failTransport), { status: 'disabled', records: [] });
  assert.deepEqual(await readHrDetail(off, 'demo_interno', failTransport), { status: 'disabled' });
  assert.equal(await writeHrRecord(off, 'POST', endpoint, {}, 'csrf-test', failTransport), 'disabled');
  assert.equal(calls, 0);
  const oldDocument = globalThis.document;
  try {
    globalThis.document = { getElementById: () => null };
    const html = renderToStaticMarkup(React.createElement(HumanResourcesManagement, {
      tenantId, companyId, branchId, permissions: ['users.view'],
    }));
    assert.match(html, /Talento humano todavía no está habilitado en el servidor/);
    assert.doesNotMatch(html, /demo_interno|colaborador_demo|pruebas|ficticios/);
    assert.equal(calls, 0);
  } finally { globalThis.document = oldDocument; }
});

test('lista sintética: GET autenticado, sin caché, filtro por empresa y estados reales', async () => {
  let count = 0;
  const transport = async (url, options) => {
    count += 1;
    assert.equal(url, endpoint);
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.method, undefined);
    return json({ records: [record] });
  };
  assert.deepEqual(await readHrRecords(on, transport), { status: 'ready', records: [record] });
  assert.equal(count, 1);
  assert.deepEqual(await readHrRecords({ ...on, allowed: false }, transport), { status: 'denied', records: [] });
  assert.equal(count, 1, 'sin permisos no hay tráfico');
  assert.deepEqual(await readHrRecords(on, () => json({ records: [] })), { status: 'ready', records: [] });
  assert.equal((await readHrRecords(on, () => json({ records: [{ ...record, company_id: tenantId }] }))).status, 'error');
  assert.equal((await readHrRecords(on, () => json({ records: [{ ...record, state: 'APROBADO' }] }))).status, 'error');
  assert.equal((await readHrRecords(on, () => json({ records: [record, record] }))).status, 'error');
  assert.equal((await readHrRecords(on, () => json({ error: 'forbidden' }, 403))).status, 'denied');
  assert.equal((await readHrRecords(on, () => json({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  assert.equal((await readHrRecords(on, () => json({ error: 'absent' }, 404))).status, 'error');
});

test('detalle real: GET individual, verifica referencia y ámbito, no expone filas ajenas', async () => {
  let target = '';
  const ok = await readHrDetail(on, 'demo_interno', async (url, options) => {
    target = url;
    assert.equal(options.cache, 'no-store');
    return json({ record });
  });
  assert.equal(target, endpoint + '/demo_interno');
  assert.deepEqual(ok, { status: 'ready', record });
  assert.equal((await readHrDetail(on, 'demo_interno', () => json({ record: {
    ...record, record_ref: 'otro_registro',
  } }))).status, 'error');
  assert.equal((await readHrDetail(on, 'demo_interno', () => json({ record: {
    ...record, tenant_id: companyId,
  } }))).status, 'error');
  assert.equal((await readHrDetail(on, '../../secret', () => { throw Error('no HTTP'); })).status, 'error');
  assert.equal((await readHrDetail(on, 'demo_interno', () => json({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  assert.equal((await readHrDetail({ ...on, allowed: false }, 'demo_interno', () => {
    throw Error('no HTTP');
  })).status, 'denied');
});

test('creación y cambio de estado: API, CSRF, versión; denegación sin red', async () => {
  let calls = 0;
  const create = { record_ref: record.record_ref, collaborator_ref: record.collaborator_ref,
    position_ref: record.position_ref, kind: record.kind };
  const post = await writeHrRecord(on, 'POST', endpoint, create, 'synthetic-csrf', async (url, options) => {
    calls += 1;
    assert.equal(url, endpoint);
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-CSRF-Token'], 'synthetic-csrf');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.deepEqual(JSON.parse(options.body), create);
    return json({ record }, 201);
  });
  assert.equal(post, 'saved');
  const next = { ...record, state: 'EN_REVISION', version: 2 };
  const patch = await writeHrRecord(on, 'PATCH', endpoint + '/demo_interno/state',
    { state: 'EN_REVISION', expected_version: 1 }, 'synthetic-csrf', async (url, options) => {
      calls += 1;
      assert.equal(options.method, 'PATCH');
      assert.equal(url, endpoint + '/demo_interno/state');
      assert.deepEqual(JSON.parse(options.body), { state: 'EN_REVISION', expected_version: 1 });
      return json({ record: next });
    });
  assert.equal(patch, 'saved');
  assert.equal(calls, 2);
  const forbidden = () => { calls += 1; throw Error('unexpected request'); };
  assert.equal(await writeHrRecord({ ...on, enabled: false }, 'POST', endpoint, create, 'csrf', forbidden), 'disabled');
  assert.equal(await writeHrRecord({ ...on, allowed: false }, 'PATCH', endpoint, create, 'csrf', forbidden), 'denied');
  assert.equal(await writeHrRecord(on, 'POST', endpoint, create, '', forbidden), 'error');
  assert.equal(calls, 2, 'desactivación y falta de permisos/CSRF prohíben HTTP');
  assert.equal(await writeHrRecord(on, 'POST', endpoint, create, 'csrf', () => json({ record: {
    ...record, company_id: branchId,
  } }, 201)), 'error');
  assert.equal(await writeHrRecord(on, 'POST', endpoint, create, 'csrf', () => json({ error: 'forbidden' }, 403)), 'denied');
});

test('código y contrato: ninguna fuente de prueba fabricada ni activación de producción', () => {
  const component = readFileSync(join(root, 'frontend/admin/HumanResourcesManagement.tsx'), 'utf8');
  const backend = readFileSync(join(root, 'src/Http/Controller/HumanResourcesController.php'), 'utf8');
  const app = readFileSync(join(root, 'frontend/admin/AdminApp.tsx'), 'utf8');
  assert.match(backend, /getenv\('HR_REAL_DATA_ENABLED'\) === 'true'/);
  assert.match(backend, /'feature_unavailable'/);
  assert.match(component, /readHrDetail\(/);
  assert.match(component, /writeHrRecord\(/);
  assert.doesNotMatch(component, /fixture:|localStorage|sessionStorage|Math\.random\(/);
  // The independent admin-shell activation and the legal/owner go-live gates remain unchanged.
  assert.match(app, /import\.meta\.env\.DEV && DevHumanResourcesManagement/);
});
