import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdtempSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { build } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const temp = mkdtempSync(join(root, 'node_modules', '.cache-governance1665-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const output = join(temp, 'governance.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/GovernanceManagement.tsx')],
  outfile: output, format: 'cjs', platform: 'node', bundle: true,
  jsx: 'automatic', external: ['react', 'react/jsx-runtime'], logLevel: 'silent' });
const { GovernanceManagement, governanceRequest } = createRequire(import.meta.url)(output);

const options = { enabled: true, allowed: true, tenantId: 'tenant_a',
  companyId: 'company_a', branchId: 'branch_a' };
const endpoint = '/api/v1/branches/branch_a/governance-real/records';
const row = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', tenant_id: 'tenant_a',
  company_id: 'company_a', record_ref: 'acta_demo', kind: 'ACTA',
  state: 'PENDIENTE', version: 1, previous_id: null, actor_id: 'actor_demo',
  occurred_at: '2026-11-08 02:00:00' };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status,
  headers: { 'Content-Type': 'application/json' } });

test('flag OFF, razón social ausente y permisos insuficientes: cero peticiones', async () => {
  const never = () => { throw new Error('No debe existir HTTP'); };
  assert.deepEqual(await governanceRequest({ ...options, enabled: false }, never), { status: 'disabled' });
  assert.deepEqual(await governanceRequest({ ...options, allowed: false }, never), { status: 'denied' });
  assert.deepEqual(await governanceRequest({ ...options, companyId: '__unassigned__' }, never),
    { status: 'denied' });
  assert.deepEqual(await governanceRequest({ ...options, branchId: '' }, never),
    { status: 'denied' });
  const base = { tenantId: 'tenant_a', companyId: 'company_a', branchId: 'branch_a',
    permissions: ['inventory.view', 'inventory.create', 'inventory.update'], csrfToken: 'csrf_test' };
  const off = renderToStaticMarkup(React.createElement(GovernanceManagement,
    { ...base, enabled: false }));
  assert.match(off, /módulo aún no está habilitado/);
  assert.match(off, /No se han consultado ni mostrado registros/);
  assert.doesNotMatch(off, /acta_demo|Nuevo instrumento/);
  const denied = renderToStaticMarkup(React.createElement(GovernanceManagement,
    { ...base, permissions: [], enabled: true }));
  assert.match(denied, /Acceso denegado/);
  const loading = renderToStaticMarkup(React.createElement(GovernanceManagement,
    { ...base, enabled: true }));
  assert.match(loading, /Cargando instrumentos/);
});

test('GET exige records válidos y respeta cursor para paginación', async () => {
  const result = await governanceRequest(options, async (url, init) => {
    assert.equal(url, endpoint);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    return json({ records: [row], next_cursor: 'acta_demo' });
  });
  assert.deepEqual(result, { status: 'ready', records: [{
    ref: 'acta_demo', kind: 'ACTA', state: 'PENDIENTE', version: 1,
    tenantId: 'tenant_a', companyId: 'company_a', occurredAt: '2026-11-08 02:00:00',
  }], nextCursor: 'acta_demo' });
  const next = await governanceRequest({ ...options, after: 'acta_demo' }, async (url, init) => {
    assert.equal(url, endpoint + '?after=acta_demo');
    assert.equal(init.method, 'GET');
    return json({ records: [{ ...row, record_ref: 'acta_nueva' }], next_cursor: null });
  });
  assert.equal(next.records?.[0].ref, 'acta_nueva');
  assert.equal(next.nextCursor, null);
  assert.deepEqual(await governanceRequest(options, () => json({ records: [], next_cursor: null })),
    { status: 'ready', records: [], nextCursor: null });
});

test('GET fail-closed: nunca muestra otra empresa, filas duplicadas ni estado no autorizado', async () => {
  const invalid = [
    { ...row, tenant_id: 'tenant_b' }, { ...row, company_id: 'company_b' },
    { ...row, kind: 'PERSONAL' }, { ...row, state: 'APROBADO' },
    { ...row, version: 0 }, { ...row, record_ref: 'fixture:acta/demo' },
    { ...row, occurred_at: 'abc' }, { ...row, record_ref: 'acta_12345678' },
  ];
  for (const value of invalid) {
    assert.equal((await governanceRequest(options, () => json({
      records: [value], next_cursor: null,
    }))).status, 'error');
  }
  for (const data of [
    { records: 'bad', next_cursor: null },
    { records: [row, row], next_cursor: null },
    { records: [row], next_cursor: 'otro' },
    { records: [], next_cursor: 'acta_demo' },
    { records: Array(101).fill(row), next_cursor: null },
    { records: [row] },
  ]) {
    assert.equal((await governanceRequest(options, () => json(data))).status, 'error');
  }
  assert.equal((await governanceRequest({ ...options, after: 'acta_nueva' },
    () => json({ records: [row], next_cursor: null }))).status, 'error');
});

test('POST y PATCH transmiten CSRF, estado y versión optimista exacta', async () => {
  const created = await governanceRequest({ ...options,
    method: 'POST', ref: 'acta_demo', kind: 'ACTA', csrf: 'csrf_test',
  }, async (url, init) => {
    assert.equal(url, endpoint);
    assert.equal(init.method, 'POST');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_test');
    assert.deepEqual(JSON.parse(init.body), { record_ref: 'acta_demo', kind: 'ACTA' });
    return json({ record: row }, 201);
  });
  assert.equal(created.record?.ref, 'acta_demo');
  const updated = await governanceRequest({ ...options, method: 'PATCH',
    ref: 'acta_demo', state: 'EN_REVISION', expectedVersion: 1, csrf: 'csrf_test',
  }, async (url, init) => {
    assert.equal(url, endpoint + '/acta_demo/state');
    assert.equal(init.method, 'PATCH');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_test');
    assert.deepEqual(JSON.parse(init.body), { state: 'EN_REVISION', expected_version: 1 });
    return json({ record: { ...row, state: 'EN_REVISION', version: 2 } });
  });
  assert.equal(updated.record?.version, 2);
  const detail = await governanceRequest({ ...options, ref: 'acta_demo' }, async (url, init) => {
    assert.equal(url, endpoint + '/acta_demo');
    assert.equal(init.method, 'GET');
    return json({ record: row });
  });
  assert.equal(detail.record?.kind, 'ACTA');
});

test('sin CSRF, alias inválido o versión inválida no realiza HTTP', async () => {
  const never = () => { throw Error('No debe emitir HTTP'); };
  for (const request of [
    { ...options, method: 'POST', ref: 'acta_demo', kind: 'ACTA' },
    { ...options, method: 'PATCH', ref: 'acta_demo', state: 'EN_REVISION', expectedVersion: 1 },
    { ...options, method: 'POST', ref: 'fixture:acta/demo', kind: 'ACTA', csrf: 'csrf' },
    { ...options, method: 'POST', ref: 'acta_demo', kind: 'PERSONAL', csrf: 'csrf' },
    { ...options, method: 'PATCH', ref: 'acta_demo', state: 'APROBADO', expectedVersion: 1, csrf: 'csrf' },
    { ...options, method: 'PATCH', ref: 'acta_demo', state: 'EN_REVISION', expectedVersion: 0, csrf: 'csrf' },
    { ...options, after: 'fixture:acta/demo' },
  ]) assert.equal((await governanceRequest(request, never)).status, 'error');
});

test('401/403/404, errores de servidor, rechazo y JSON corrupto se aíslan', async () => {
  for (const code of [401, 403]) {
    assert.deepEqual(await governanceRequest(options, () => json({ error: 'denied' }, code)),
      { status: 'denied' });
  }
  assert.deepEqual(await governanceRequest(options,
    () => json({ error: 'feature_unavailable' }, 404)), { status: 'disabled' });
  assert.deepEqual(await governanceRequest({
    ...options, method: 'POST', ref: 'acta_demo', kind: 'ACTA', csrf: 'csrf_test',
  }, () => json({ error: 'feature_unavailable' }, 404)), { status: 'disabled' });
  for (const request of [
    options,
    { ...options, method: 'POST', ref: 'acta_demo', kind: 'ACTA', csrf: 'csrf_test' },
    { ...options, method: 'PATCH', ref: 'acta_demo', state: 'EN_REVISION',
      expectedVersion: 1, csrf: 'csrf_test' },
  ]) {
    assert.equal((await governanceRequest(request,
      () => json({ error: 'not_found' }, 404))).status, 'error');
  }
  assert.equal((await governanceRequest(options,
    () => new Response('<html>Not Found</html>', { status: 404 }))).status, 'error');
  assert.equal((await governanceRequest({ ...options, ref: 'acta_demo' },
    () => json({ error: 'not_found' }, 404))).status, 'error');
  assert.equal((await governanceRequest(options, () => json({ error: 'server' }, 500))).status, 'error');
  assert.equal((await governanceRequest(options, () => { throw Error('offline'); })).status, 'error');
  assert.equal((await governanceRequest(options, () => new Response('{', { status: 200 }))).status, 'error');
});
