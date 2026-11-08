import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdtempSync, rmSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { build } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const temp = mkdtempSync(join(root, 'node_modules', '.cache-customer1669-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const cjs = join(temp, 'customer.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/CustomerServiceManagement.tsx')],
  outfile: cjs, format: 'cjs', platform: 'node', bundle: true,
  jsx: 'automatic', external: ['react', 'react/jsx-runtime'], logLevel: 'silent' });
const { requestCustomerServiceCase: request, CustomerServiceManagement: Panel } =
  createRequire(import.meta.url)(cjs);
const scope = { enabled: true, allowed: true, tenant: 'tenant_a', company: 'company_a', branch: 'branch_a' };
const url = '/api/v1/branches/branch_a/customer-service-real/records';
const sample = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', tenant_id: 'tenant_a', company_id: 'company_a',
  record_ref: 'caso_a', kind: 'PQRS', state: 'PENDIENTE', version: 1,
  occurred_at: '2026-11-08 10:00:00' };
const json = (value, status = 200) => new Response(JSON.stringify(value),
  { status, headers: { 'Content-Type': 'application/json' } });
const props = { enabled: true, tenantId: 'tenant_a', companyId: 'company_a', branchId: 'branch_a',
  permissions: ['inventory.view', 'inventory.create', 'inventory.update'], csrfToken: 'csrf_demo' };

test('flag OFF, ámbito ajeno o permiso ausente jamás invocan transporte', async () => {
  const forbidden = () => { throw Error('HTTP unexpectedly called'); };
  for (const options of [
    { ...scope, enabled: false },
    { ...scope, allowed: false },
    { ...scope, company: '__unassigned__' },
    { ...scope, tenant: '' },
    { ...scope, branch: '' },
  ]) assert.notEqual((await request(options, forbidden)).status, 'ready');
  const html = renderToStaticMarkup(React.createElement(Panel, { ...props, enabled: false }));
  assert.match(html, /El módulo aún no está habilitado/);
  assert.doesNotMatch(html, /Nuevo caso|caso_a/);
  const denied = renderToStaticMarkup(React.createElement(Panel,
    { ...props, permissions: [] }));
  assert.match(denied, /Acceso denegado/);
  assert.match(renderToStaticMarkup(React.createElement(Panel, props)), /Cargando casos/);
});

test('GET lista y cursor keyset exigen respuesta ordenada, 100 filas máximo y mismo ámbito', async () => {
  const data = await request(scope, (endpoint, init) => {
    assert.equal(endpoint, url);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    return json({ records: [sample], next_cursor: null });
  });
  assert.deepEqual(data, { status: 'ready', rows: [{
    ref: 'caso_a', kind: 'PQRS', state: 'PENDIENTE', version: 1,
    tenant: 'tenant_a', company: 'company_a', occurredAt: '2026-11-08 10:00:00',
  }], cursor: null });
  const next = await request({ ...scope, after: 'caso_a' }, (target) => {
    assert.equal(target, url + '?after=caso_a');
    return json({ records: [{ ...sample, record_ref: 'caso_b' }], next_cursor: null });
  });
  assert.equal(next.rows[0].ref, 'caso_b');
  const all = Array.from({ length: 100 }, (_, i) => ({ ...sample,
    record_ref: 'caso_' + String(i).padStart(3, '0') }));
  const batch = await request(scope, () => json({ records: all, next_cursor: 'caso_099' }));
  assert.equal(batch.cursor, 'caso_099');
  assert.equal(batch.rows.length, 100);
});

test('GET falla cerrado ante filas de otra empresa, duplicadas, cursor falso y estructuras incorrectas', async () => {
  for (const wrong of [
    { ...sample, tenant_id: 'tenant_b' }, { ...sample, company_id: 'company_b' },
    { ...sample, record_ref: 'persona@example.com' }, { ...sample, record_ref: '123456789' },
    { ...sample, state: 'APROBADO' }, { ...sample, kind: 'DATOS_PERSONALES' },
    { ...sample, version: 0 }, { ...sample, occurred_at: 'bad' },
  ]) assert.equal((await request(scope, () => json({ records: [wrong], next_cursor: null }))).status, 'error');
  for (const malformed of [
    { records: [sample] }, { records: 'bad', next_cursor: null },
    { records: [sample, sample], next_cursor: null },
    { records: [sample], next_cursor: 'any_ref' },
    { records: Array(101).fill(sample), next_cursor: null },
    { records: [], next_cursor: 'caso_a' },
    { records: [{ ...sample, record_ref: 'caso_z' }, sample], next_cursor: null },
  ]) assert.equal((await request(scope, () => json(malformed))).status, 'error');
  assert.equal((await request({ ...scope, after: 'caso_z' },
    () => json({ records: [sample], next_cursor: null }))).status, 'error');
});

test('POST y PATCH usan CSRF, versión optimista, cookies same-origin y no aprueban actos', async () => {
  const created = await request({ ...scope, verb: 'POST',
    ref: 'caso_a', kind: 'PQRS', csrf: 'csrf_demo' }, (target, init) => {
    assert.equal(target, url);
    assert.equal(init.method, 'POST');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.equal(init.credentials, 'same-origin');
    assert.deepEqual(JSON.parse(init.body), { record_ref: 'caso_a', kind: 'PQRS' });
    return json({ record: sample }, 201);
  });
  assert.equal(created.entry?.ref, 'caso_a');
  const revision = await request({ ...scope, verb: 'PATCH', ref: 'caso_a',
    next: 'EN_REVISION', version: 1, csrf: 'csrf_demo' }, (target, init) => {
    assert.equal(target, url + '/caso_a/state');
    assert.deepEqual(JSON.parse(init.body), { state: 'EN_REVISION', expected_version: 1 });
    return json({ record: { ...sample, state: 'EN_REVISION', version: 2 } });
  });
  assert.equal(revision.entry?.version, 2);
  const detail = await request({ ...scope, ref: 'caso_a' }, (target) => {
    assert.equal(target, url + '/caso_a');
    return json({ record: sample });
  });
  assert.equal(detail.entry?.kind, 'PQRS');
});

test('validaciones de creación y edición niegan alias con PII, estados ilegales y CSRF ausente', async () => {
  const never = () => { throw Error('Should not send HTTP'); };
  for (const candidate of [
    { ...scope, verb: 'POST', ref: 'caso_a', kind: 'PQRS' },
    { ...scope, verb: 'POST', ref: 'caso_12345678', kind: 'PQRS', csrf: 'x' },
    { ...scope, verb: 'POST', ref: 'caso_a', kind: 'PERSONAL', csrf: 'x' },
    { ...scope, verb: 'PATCH', ref: 'caso_a', next: 'EN_REVISION', version: 0, csrf: 'x' },
    { ...scope, verb: 'PATCH', ref: 'caso_a', next: 'APROBADO', version: 1, csrf: 'x' },
    { ...scope, verb: 'PATCH', ref: 'caso_a', next: 'EN_REVISION', version: 1 },
    { ...scope, after: 'fixture:case/demo' },
  ]) assert.equal((await request(candidate, never)).status, 'error');
});

test('error HTTP, 404 ausente vs feature_unavailable, permisos y JSON corrupto no revelan filas', async () => {
  for (const code of [401, 403])
    assert.deepEqual(await request(scope, () => json({ error: 'unauthorized' }, code)), { status: 'denied' });
  assert.deepEqual(await request(scope, () => json({ error: 'feature_unavailable' }, 404)), { status: 'disabled' });
  for (const response of [json({ error: 'not_found' }, 404), json({ error: 'server' }, 500),
    new Response('{', { status: 200 }), new Response('<html>404</html>', { status: 404 })])
    assert.equal((await request(scope, () => response)).status, 'error');
  assert.equal((await request(scope, () => { throw Error('network offline'); })).status, 'error');
});
