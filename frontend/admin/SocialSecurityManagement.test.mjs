/** Contrato UI M05: flag OFF, RBAC, ámbito y HTTP sintético controlado. */
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
const temp = mkdtempSync(join(root, 'node_modules', '.cache-social1657-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const output = join(temp, 'social.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/SocialSecurityManagement.tsx')],
  outfile: output, format: 'cjs', platform: 'node', bundle: true, jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'],
  define: { 'import.meta.env.DEV': 'true' }, logLevel: 'silent' });
const { SocialSecurityRealManagement, socialSecurityRealRequest } = createRequire(import.meta.url)(output);
const realRow = { tenant_id: 'tenant_demo', company_id: 'company_demo',
  record_ref: 'afiliacion_demo', category: 'afiliacion', state: 'PENDIENTE', version: 1 };
const options = { enabled: true, allowed: true,
  tenantId: 'tenant_demo', companyId: 'company_demo', branchId: 'branch_demo' };
const base = '/api/v1/branches/branch_demo/social-security/records';
const json = (value, status = 200) => new Response(JSON.stringify(value),
  { status, headers: { 'Content-Type': 'application/json' } });
const noHttp = () => { throw Error('HTTP prohibido'); };

test('OFF, permisos, empresa o CSRF ausentes bloquean HTTP antes de salir', async () => {
  for (const opts of [
    { ...options, enabled: false }, { ...options, allowed: false },
    { ...options, companyId: '__unassigned__' }, { ...options, branchId: '' },
    { ...options, tenantId: '' }, { ...options, method: 'DELETE' },
    { ...options, method: 'POST', ref: realRow.record_ref, category: 'afiliacion' },
    { ...options, method: 'POST', ref: realRow.record_ref, category: 'alta', csrf: 'token' },
    { ...options, method: 'POST', ref: 'fixture:afiliacion/demo', category: 'afiliacion', csrf: 'token' },
    { ...options, method: 'PATCH', ref: realRow.record_ref, state: 'PENDIENTE', expectedVersion: 1 },
    { ...options, method: 'PATCH', ref: realRow.record_ref, state: 'COMPLETADO', expectedVersion: 1, csrf: 'token' },
    { ...options, method: 'PATCH', ref: realRow.record_ref, state: 'REQUIERE_REVISION', expectedVersion: 0, csrf: 'token' },
    { ...options, method: 'GET', ref: '../otra-empresa' },
  ]) assert.notEqual((await socialSecurityRealRequest(opts, noHttp)).status, 'ready');
});

test('SSR con OFF no incluye datos ni inicia solicitudes de API real', () => {
  const result = renderToStaticMarkup(React.createElement(SocialSecurityRealManagement,
    { enabled: false, tenantId: 'tenant_demo', companyId: 'company_demo',
      branchId: 'branch_demo', permissions: ['users.view'], csrfToken: 'token' }));
  assert.match(result, /aún no está habilitado/);
  assert.doesNotMatch(result, /fixture:|afiliacion_demo|Guardar registro|M05 · laboratorio/);
  const denied = renderToStaticMarkup(React.createElement(SocialSecurityRealManagement,
    { enabled: true, tenantId: 'tenant_demo', companyId: '__unassigned__',
      branchId: 'branch_demo', permissions: [], csrfToken: 'token' }));
  assert.match(denied, /Acceso denegado/);
});

test('GET lista, vacío, límite y defensa de aislamiento tenant-empresa', async () => {
  const result = await socialSecurityRealRequest(options, async (url, init) => {
    assert.equal(url, base);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers.Accept, 'application/json');
    assert.equal(init.headers['X-CSRF-Token'], undefined);
    return json({ records: [realRow] });
  });
  assert.deepEqual(result, { status: 'ready', records: [{
    ref: 'afiliacion_demo', tenantId: 'tenant_demo', companyId: 'company_demo',
    category: 'afiliacion', state: 'PENDIENTE', version: 1,
  }] });
  assert.deepEqual(await socialSecurityRealRequest(options,
    () => json({ records: [] })), { status: 'ready', records: [] });
  for (const rows of [
    [{ ...realRow, tenant_id: 'tenant_extraño' }],
    [{ ...realRow, company_id: 'company_extraña' }],
    [{ ...realRow, category: 'desconocida' }],
    [{ ...realRow, state: 'PAGADO' }],
    [{ ...realRow, record_ref: 'persona@correo.test' }],
    [{ ...realRow, version: 0 }],
    [realRow, realRow],
    Array.from({ length: 101 }, (_, i) => ({ ...realRow, record_ref: 'reg_' + i })),
  ]) assert.equal((await socialSecurityRealRequest(options,
    () => json({ records: rows }))).status, 'error');
});

test('GET detalle, POST y PATCH respetan CSRF, URL y versión', async () => {
  const detail = await socialSecurityRealRequest({ ...options, ref: realRow.record_ref },
    async (url, init) => {
      assert.equal(url, base + '/' + realRow.record_ref);
      assert.equal(init.method, 'GET');
      return json({ record: realRow });
    });
  assert.equal(detail.status, 'ready');
  assert.equal(detail.record?.ref, realRow.record_ref);
  const created = await socialSecurityRealRequest({ ...options,
    method: 'POST', ref: realRow.record_ref, category: 'afiliacion', csrf: 'csrf_demo' },
  async (url, init) => {
    assert.equal(url, base);
    assert.equal(init.method, 'POST');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.deepEqual(JSON.parse(init.body), { record_ref: realRow.record_ref, category: 'afiliacion' });
    return json({ record: realRow }, 201);
  });
  assert.equal(created.status, 'ready');
  const updated = await socialSecurityRealRequest({ ...options,
    method: 'PATCH', ref: realRow.record_ref, state: 'REQUIERE_REVISION',
    expectedVersion: 1, csrf: 'csrf_demo' },
  async (url, init) => {
    assert.equal(url, base + '/' + realRow.record_ref + '/state');
    assert.equal(init.method, 'PATCH');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.deepEqual(JSON.parse(init.body), { state: 'REQUIERE_REVISION', expected_version: 1 });
    return json({ record: { ...realRow, state: 'REQUIERE_REVISION', version: 2 } });
  });
  assert.equal(updated.status, 'ready');
  assert.equal(updated.record?.version, 2);
});

test('HTTP 401/403 denegado, 404 apagado; fallo, JSON corrupto y scope ilegítimo no listos', async () => {
  for (const status of [401, 403]) {
    assert.equal((await socialSecurityRealRequest(options,
      () => json({ error: 'forbidden' }, status))).status, 'denied');
  }
  assert.equal((await socialSecurityRealRequest(options,
    () => json({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  assert.equal((await socialSecurityRealRequest(options,
    () => json({ error: 'server' }, 500))).status, 'error');
  assert.equal((await socialSecurityRealRequest(options,
    () => { throw Error('network'); })).status, 'error');
  assert.equal((await socialSecurityRealRequest(options,
    () => new Response('{', { status: 200 }))).status, 'error');
  assert.equal((await socialSecurityRealRequest(options,
    () => json({ records: [realRow], record: null }))).status, 'ready');
  assert.equal((await socialSecurityRealRequest(options,
    () => json({ record: { ...realRow, company_id: 'otra_empresa' } }),)).status, 'error');
});

test('el montaje real solo depende del servidor y evita fixture cuando OFF', () => {
  const controller = readFileSync(join(root, 'src/Http/Controller/AdminController.php'), 'utf8');
  const twig = readFileSync(join(root, 'templates/admin/index.html.twig'), 'utf8');
  const app = readFileSync(join(root, 'frontend/admin/AdminApp.tsx'), 'utf8');
  assert.match(controller, /social_security_real_data_enabled.*getenv\('SOCIAL_SECURITY_REAL_DATA_ENABLED'\) === 'true'/s);
  assert.match(twig, /data-social-security-real-data-enabled/);
  assert.match(app, /dataset\.socialSecurityRealDataEnabled === 'true'/);
  assert.match(app, /enabled=\{socialSecurityRealDataEnabled\}/);
  assert.doesNotMatch(app, /<DevSocialSecurityManagement/);
});
