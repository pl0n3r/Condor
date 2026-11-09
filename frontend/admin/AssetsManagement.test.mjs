import { test, after } from 'node:test';
import { strict as assert } from 'node:assert';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
import { mkdtempSync, rmSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToString } from 'react-dom/server';

const cwd = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const workspace = mkdtempSync(join(cwd, 'node_modules', 'assets-qa-'));
const output = join(workspace, 'compiled.cjs');
after(() => rmSync(workspace, { recursive: true, force: true }));
await build({
  entryPoints: [join(cwd, 'frontend/admin/AssetsManagement.tsx')],
  outfile: output, bundle: true, platform: 'node', format: 'cjs', jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'], logLevel: 'silent',
});
const { requestAssets, AssetsManagement } = createRequire(import.meta.url)(output);
const tenant = 'tenant_qa', company = 'company_qa', branch = 'branch_qa';
const options = { enabled: true, allowed: true, tenant, company, branch };
const path = '/api/v1/branches/branch_qa/assets-real/records';
const record = {
  tenant_id: tenant, company_id: company, record_ref: 'equipo_qa',
  category: 'EQUIPO', state: 'PENDIENTE', version: 1,
  responsible_ref: null, evidence_ref: null, occurred_at: '2026-11-09 10:00:00',
};
const response = (data, status = 200) => new Response(JSON.stringify(data), {
  status, headers: { 'Content-Type': 'application/json' },
});
const forbid = () => { throw Error('No puede accederse a HTTP'); };
const run = (patch, transport) => requestAssets({ ...options, ...patch }, transport);
const props = { enabled: true, tenantId: tenant, companyId: company, branchId: branch,
  permissions: ['inventory.view', 'inventory.create', 'inventory.update'], csrfToken: 'csrf_qa' };

test('flag OFF y ámbitos inválidos nunca hacen fetch ni revelan activos', async () => {
  for (const patch of [{ enabled: false }, { allowed: false }, { tenant: '' },
    { company: '__unassigned__' }, { branch: '' }]) {
    assert.notEqual((await run(patch, forbid)).status, 'ready');
  }
  const off = renderToString(React.createElement(AssetsManagement,
    { ...props, enabled: false }));
  assert.match(off, /El módulo aún no está habilitado/);
  assert.doesNotMatch(off, /Nuevo activo|equipo_qa/);
  const denied = renderToString(React.createElement(AssetsManagement,
    { ...props, permissions: [] }));
  assert.match(denied, /autorizadas/);
  const loading = renderToString(React.createElement(AssetsManagement, props));
  assert.match(loading, /Cargando activos/);
});

test('API real: consulta paginada, orden estable y ámbitos comprobados', async () => {
  const list = await run({}, (url, init) => {
    assert.equal(url, path);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    return response({ records: [record], next_cursor: null });
  });
  assert.equal(list.status, 'ready');
  assert.equal(list.rows?.[0].ref, 'equipo_qa');
  const later = await run({ after: 'equipo_qa' }, url => {
    assert.equal(url, path + '?after=equipo_qa');
    return response({ records: [{ ...record, record_ref: 'mueble_qa' }], next_cursor: null });
  });
  assert.equal(later.rows?.[0].ref, 'mueble_qa');
  const hundred = Array.from({ length: 100 }, (_, i) =>
    ({ ...record, record_ref: 'equipo_' + String(i).padStart(3, '0') }));
  const page = await run({}, () => response({ records: hundred, next_cursor: 'equipo_099' }));
  assert.equal(page.rows?.length, 100);
  assert.equal(page.cursor, 'equipo_099');
});

test('rechazo de datos ajenos, PII y respuestas sin contrato', async () => {
  for (const invalid of [
    { ...record, tenant_id: 'otro_tenant' },
    { ...record, company_id: 'otra_compania' },
    { ...record, record_ref: 'persona@example.com' },
    { ...record, record_ref: 'equipo_123456789' },
    { ...record, state: 'APROBADO' },
    { ...record, category: 'PERSONA' },
    { ...record, version: 0 },
    { ...record, occurred_at: 'ayer' },
    { ...record, responsible_ref: 'usuario@example.com' },
  ]) {
    assert.equal((await run({}, () => response({ records: [invalid], next_cursor: null }))).status,
      'error', JSON.stringify(invalid));
  }
  for (const invalid of [
    { records: [record] },
    { records: 'no-array', next_cursor: null },
    { records: [record, record], next_cursor: null },
    { records: [record], next_cursor: 'inventado' },
    { records: [], next_cursor: 'inventado' },
  ]) {
    assert.equal((await run({}, () => response(invalid))).status, 'error');
  }
});

test('alta y transición envían CSRF, versión y payload exactos', async () => {
  const created = await run({ verb: 'POST', ref: 'equipo_qa', category: 'EQUIPO', csrf: 'csrf_qa' },
    (url, init) => {
      assert.equal(url, path);
      assert.equal(init.method, 'POST');
      assert.equal(init.headers['X-CSRF-Token'], 'csrf_qa');
      assert.deepEqual(JSON.parse(init.body), { record_ref: 'equipo_qa', category: 'EQUIPO' });
      return response({ record }, 201);
    });
  assert.equal(created.entry?.version, 1);
  const assigned = await run({ verb: 'PATCH', ref: 'equipo_qa', next: 'ASIGNADO',
    version: 1, responsible: 'empleado_qa', csrf: 'csrf_qa' }, (url, init) => {
    assert.equal(url, path + '/equipo_qa/state');
    assert.deepEqual(JSON.parse(init.body), {
      state: 'ASIGNADO', expected_version: 1, responsible_ref: 'empleado_qa',
    });
    return response({ record: { ...record, state: 'ASIGNADO', version: 2,
      responsible_ref: 'empleado_qa' } });
  });
  assert.equal(assigned.entry?.version, 2);
  const detail = await run({ ref: 'equipo_qa' }, url => {
    assert.equal(url, path + '/equipo_qa');
    return response({ record });
  });
  assert.equal(detail.entry?.category, 'EQUIPO');
});

test('sin CSRF, versión o permiso se bloquea; errores HTTP son explícitos', async () => {
  for (const patch of [
    { verb: 'POST', ref: 'equipo_qa', category: 'EQUIPO' },
    { verb: 'POST', ref: 'fixture:asset/demo', category: 'EQUIPO', csrf: 'x' },
    { verb: 'POST', ref: 'equipo_qa', category: 'PII', csrf: 'x' },
    { verb: 'PATCH', ref: 'equipo_qa', next: 'ASIGNADO', version: 1, csrf: 'x' },
    { verb: 'PATCH', ref: 'equipo_qa', next: 'EN_REVISION', version: 0, csrf: 'x' },
    { verb: 'PATCH', ref: 'equipo_qa', next: 'EN_REVISION', version: 1 },
    { after: 'correo@example.com' },
  ]) {
    assert.equal((await run(patch, forbid)).status, 'error', JSON.stringify(patch));
  }
  for (const status of [401, 403]) {
    assert.equal((await run({}, () => response({ error: 'denied' }, status))).status, 'denied');
  }
  assert.equal((await run({}, () => response({ error: 'feature_unavailable' }, 404))).status,
    'disabled');
  assert.equal((await run({}, () => response({}, 500))).status, 'error');
  assert.equal((await run({}, () => new Response('{', { status: 200 }))).status, 'error');
  assert.equal((await run({}, () => { throw Error('offline'); })).status, 'error');
});
