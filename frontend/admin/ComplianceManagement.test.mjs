/** #1661: regression of real SG-SST, server flag and scoped synthetic requests. */
import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { build } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const temp = mkdtempSync(join(root, 'node_modules', '.cache-ohs1661-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const output = join(temp, 'ohs.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/ComplianceManagement.tsx')],
  outfile: output, format: 'cjs', platform: 'node', bundle: true, jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'],
  define: { 'import.meta.env.DEV': 'true' }, logLevel: 'silent' });
const { OccupationalRealManagement, occupationalRealRequest } =
  createRequire(import.meta.url)(output);

const options = { enabled: true, allowed: true,
  tenantId: 'tenant_demo', companyId: 'company_demo', branchId: 'branch_demo' };
const rootPath = '/api/v1/branches/branch_demo/sgsst/real-records';
const record = { tenant_id: options.tenantId, company_id: options.companyId,
  record_ref: 'inspeccion_demo', kind: 'INSPECCION', state: 'PENDIENTE', version: 1 };
const reply = (body, status = 200) => new Response(JSON.stringify(body), {
  status, headers: { 'Content-Type': 'application/json' },
});

test('OFF, RBAC, scope inválido, método y escrituras sin CSRF impiden llamadas HTTP', async () => {
  const noHttp = () => { throw Error('HTTP inesperado'); };
  const bad = [
    { ...options, enabled: false },
    { ...options, allowed: false },
    { ...options, tenantId: '' },
    { ...options, companyId: '__unassigned__' },
    { ...options, branchId: '' },
    { ...options, method: 'DELETE' },
    { ...options, method: 'POST', ref: record.record_ref, kind: 'INSPECCION' },
    { ...options, method: 'POST', ref: 'fixture:sgsst/real', kind: 'INSPECCION', csrf: 'token' },
    { ...options, method: 'POST', ref: 'identificacion1234567', kind: 'CONTROL', csrf: 'token' },
    { ...options, method: 'POST', ref: 'inspeccion', kind: 'DIAGNOSTICO', csrf: 'token' },
    { ...options, method: 'PATCH', ref: record.record_ref, state: 'APROBADO', expectedVersion: 1, csrf: 'token' },
    { ...options, method: 'PATCH', ref: record.record_ref, state: 'EN_REVISION', expectedVersion: 0, csrf: 'token' },
    { ...options, method: 'PATCH', ref: record.record_ref, state: 'EN_REVISION', expectedVersion: 1 },
    { ...options, method: 'GET', ref: '../tenant_ajeno' },
  ];
  for (const item of bad) {
    const result = await occupationalRealRequest(item, noHttp);
    assert.notEqual(result.status, 'ready');
  }
});

test('SSR flag OFF, sin empresa y sin permiso mantiene vista fail-closed', () => {
  const props = { ...options, permissions: ['settings.view'], csrfToken: 'token' };
  const off = renderToStaticMarkup(React.createElement(OccupationalRealManagement, {
    ...props, enabled: false,
  }));
  assert.match(off, /aún no está habilitado/);
  assert.match(off, /no se consultaron datos/);
  assert.doesNotMatch(off, /inspeccion_demo|Crear registro ficticio|fixture:|Enviar a revisión/);
  const denied = renderToStaticMarkup(React.createElement(OccupationalRealManagement, {
    ...props, enabled: true, companyId: '__unassigned__', permissions: [],
  }));
  assert.match(denied, /Acceso denegado/);
});

test('GET lista y vacío, scope, versión, duplicados y datos ajenos fail-closed', async () => {
  const result = await occupationalRealRequest(options, async (url, init) => {
    assert.equal(url, rootPath);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers.Accept, 'application/json');
    assert.equal(init.headers['X-CSRF-Token'], undefined);
    return reply({ records: [record] });
  });
  assert.deepEqual(result, { status: 'ready', records: [{
    ref: 'inspeccion_demo', tenantId: options.tenantId, companyId: options.companyId,
    kind: 'INSPECCION', state: 'PENDIENTE', version: 1,
  }] });
  assert.deepEqual(await occupationalRealRequest(options,
    () => reply({ records: [] })), { status: 'ready', records: [] });
  for (const rows of [
    [{ ...record, tenant_id: 'tenant_ajeno' }],
    [{ ...record, company_id: 'empresa_ajena' }],
    [{ ...record, kind: 'DIAGNOSTICO' }],
    [{ ...record, state: 'CERRADO' }],
    [{ ...record, record_ref: 'persona@dominio.test' }],
    [{ ...record, record_ref: 'fixture:inspeccion/demo' }],
    [{ ...record, version: 0 }],
    [record, record],
    Array.from({ length: 101 }, (_, i) => ({ ...record, record_ref: 'ref_' + i })),
  ]) assert.equal((await occupationalRealRequest(options,
    () => reply({ records: rows }))).status, 'error');
});

test('GET detalle, POST y PATCH envían referencias opacas, CSRF y versión exacta', async () => {
  const detail = await occupationalRealRequest({ ...options, ref: record.record_ref },
    async (url, init) => {
      assert.equal(url, rootPath + '/inspeccion_demo');
      assert.equal(init.method, 'GET');
      return reply({ record });
    });
  assert.equal(detail.status, 'ready');
  assert.equal(detail.record.ref, record.record_ref);
  const created = await occupationalRealRequest({
    ...options, method: 'POST', ref: record.record_ref, kind: 'INSPECCION', csrf: 'csrf_demo',
  }, async (url, init) => {
    assert.equal(url, rootPath);
    assert.equal(init.method, 'POST');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.deepEqual(JSON.parse(init.body), { record_ref: record.record_ref, kind: 'INSPECCION' });
    return reply({ record }, 201);
  });
  assert.equal(created.status, 'ready');
  const patch = await occupationalRealRequest({
    ...options, method: 'PATCH', ref: record.record_ref, state: 'EN_REVISION',
    expectedVersion: 1, csrf: 'csrf_demo',
  }, async (url, init) => {
    assert.equal(url, rootPath + '/inspeccion_demo/state');
    assert.equal(init.method, 'PATCH');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.deepEqual(JSON.parse(init.body), { state: 'EN_REVISION', expected_version: 1 });
    return reply({ record: { ...record, state: 'EN_REVISION', version: 2 } });
  });
  assert.equal(patch.status, 'ready');
  assert.equal(patch.record.version, 2);
  assert.equal((await occupationalRealRequest({
    ...options, method: 'PATCH', ref: record.record_ref, state: 'REQUIERE_REVISION',
    expectedVersion: 1, csrf: 'csrf_demo',
  }, () => reply({ record: { ...record, state: 'REQUIERE_REVISION', version: 7 } }))).status, 'error');
});

test('red, denegado, flag servidor OFF, error y JSON corrupto no acreditan datos', async () => {
  for (const status of [401, 403]) {
    assert.equal((await occupationalRealRequest(options,
      () => reply({ error: 'forbidden' }, status))).status, 'denied');
  }
  assert.equal((await occupationalRealRequest(options,
    () => reply({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  assert.equal((await occupationalRealRequest(options,
    () => reply({ error: 'not_found' }, 404))).status, 'error');
  assert.equal((await occupationalRealRequest(options,
    () => reply({ error: 'internal' }, 500))).status, 'error');
  assert.equal((await occupationalRealRequest(options,
    () => { throw Error('network'); })).status, 'error');
  assert.equal((await occupationalRealRequest(options,
    () => new Response('{', { status: 200 }))).status, 'error');
  assert.equal((await occupationalRealRequest({ ...options, ref: 'inspeccion_demo' },
    () => reply({ record: { ...record, company_id: 'company_ajena' } }))).status, 'error');
});

test('contrato SSR/menú real: flag de servidor explícito y laboratorio no montado', () => {
  const php = readFileSync(join(root, 'src/Http/Controller/AdminController.php'), 'utf8');
  const twig = readFileSync(join(root, 'templates/admin/index.html.twig'), 'utf8');
  const app = readFileSync(join(root, 'frontend/admin/AdminApp.tsx'), 'utf8');
  assert.match(php, /'ohs_real_data_enabled' => getenv\('OHS_REAL_DATA_ENABLED'\) === 'true'/);
  assert.match(twig, /data-ohs-real-data-enabled/);
  assert.match(app, /dataset\.ohsRealDataEnabled === 'true'/);
  assert.match(app, /enabled=\{ohsRealDataEnabled\}/);
  assert.match(app, /<ComplianceManagement/);
  assert.match(app, /ohsRealDataEnabled \? \[\{ href: '\/admin#compliance-management', label: 'SG-SST'/);
  assert.doesNotMatch(app, /<ComplianceSyntheticPreview/);
});
