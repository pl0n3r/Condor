/** #1659: browser-independent real finance adapter and SSR fail-closed cases. */
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
const temp = mkdtempSync(join(root, 'node_modules', '.cache-finance1659-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const output = join(temp, 'finance.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/FinanceManagement.tsx')],
  outfile: output, format: 'cjs', platform: 'node', bundle: true, jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'],
  define: { 'import.meta.env.DEV': 'true' }, logLevel: 'silent' });
const { FinanceRealManagement, financeRealRequest } = createRequire(import.meta.url)(output);
const options = { enabled: true, allowed: true,
  tenantId: 'tenant_demo', companyId: 'company_demo', branchId: 'branch_demo' };
const row = { tenant_id: options.tenantId, company_id: options.companyId,
  entry_ref: 'balance_demo', kind: 'ajuste', state: 'PENDIENTE', version: 1 };
const base = '/api/v1/branches/branch_demo/finance/real-records';
const response = (body, status = 200) => new Response(JSON.stringify(body), {
  status, headers: { 'Content-Type': 'application/json' },
});

test('OFF, RBAC, scope y escritura sin CSRF jamás contactan transporte', async () => {
  const never = () => { throw Error('HTTP inesperado'); };
  for (const opts of [
    { ...options, enabled: false },
    { ...options, allowed: false },
    { ...options, tenantId: '' },
    { ...options, companyId: '__unassigned__' },
    { ...options, branchId: '' },
    { ...options, method: 'DELETE' },
    { ...options, method: 'POST', ref: row.entry_ref, kind: 'ajuste' },
    { ...options, method: 'POST', ref: 'person@example.com', kind: 'ajuste', csrf: 'token' },
    { ...options, method: 'POST', ref: row.entry_ref, kind: 'pago_bancario', csrf: 'token' },
    { ...options, method: 'PATCH', ref: row.entry_ref, state: 'PAGADO', expectedVersion: 1, csrf: 'token' },
    { ...options, method: 'PATCH', ref: row.entry_ref, state: 'REQUIERE_REVISION', expectedVersion: 0, csrf: 'token' },
    { ...options, method: 'GET', ref: '../empresa_ajena' },
  ]) assert.notEqual((await financeRealRequest(opts, never)).status, 'ready');
});

test('SSR OFF y sin permisos no revela registros ni activa la API', () => {
  const props = { tenantId: options.tenantId, companyId: options.companyId,
    branchId: options.branchId, csrfToken: 'token', permissions: ['settings.view'] };
  const off = renderToStaticMarkup(React.createElement(FinanceRealManagement, {
    ...props, enabled: false,
  }));
  assert.match(off, /no se consultaron datos/i);
  assert.doesNotMatch(off, /balance_demo|Guardar para revisión|finance\/real-records/);
  const denied = renderToStaticMarkup(React.createElement(FinanceRealManagement, {
    ...props, enabled: true, permissions: [],
  }));
  assert.match(denied, /Acceso denegado/);
});

test('GET valida datos y estado: empty, records, errores y aislamiento', async () => {
  const loaded = await financeRealRequest(options, async (url, init) => {
    assert.equal(url, base);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers.Accept, 'application/json');
    assert.equal(init.headers['X-CSRF-Token'], undefined);
    return response({ records: [row] });
  });
  assert.deepEqual(loaded, { status: 'ready', records: [{
    ref: row.entry_ref, tenantId: options.tenantId, companyId: options.companyId,
    kind: 'ajuste', state: 'PENDIENTE', version: 1,
  }] });
  assert.deepEqual(await financeRealRequest(options, () => response({ records: [] })),
    { status: 'ready', records: [] });
  for (const items of [
    [{ ...row, tenant_id: 'tenant_ajeno' }],
    [{ ...row, company_id: 'empresa_ajena' }],
    [{ ...row, entry_ref: 'person@example.com' }],
    [{ ...row, kind: 'transaccion_real' }],
    [{ ...row, state: 'APROBADO' }],
    [{ ...row, version: 0 }],
    [row, row],
    Array.from({ length: 101 }, (_, i) => ({ ...row, entry_ref: 'ref_' + i })),
  ]) assert.equal((await financeRealRequest(options, () => response({ records: items }))).status, 'error');
  assert.equal((await financeRealRequest(options, () => response({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  assert.equal((await financeRealRequest(options, () => response({ error: 'forbidden' }, 403))).status, 'denied');
  assert.equal((await financeRealRequest(options, () => response({ error: 'boom' }, 500))).status, 'error');
});

test('POST, PATCH y GET detalle envían scope, CSRF y versión sin acciones bancarias', async () => {
  const post = await financeRealRequest({ ...options, method: 'POST',
    ref: row.entry_ref, kind: row.kind, csrf: 'csrf_demo' }, async (url, init) => {
    assert.equal(url, base);
    assert.equal(init.method, 'POST');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.equal(init.credentials, 'same-origin');
    assert.deepEqual(JSON.parse(init.body), { entry_ref: row.entry_ref, kind: row.kind });
    return response({ record: row }, 201);
  });
  assert.equal(post.status, 'ready');
  const patch = await financeRealRequest({ ...options, method: 'PATCH', ref: row.entry_ref,
    state: 'REQUIERE_REVISION', expectedVersion: 1, csrf: 'csrf_demo' }, async (url, init) => {
    assert.equal(url, base + '/balance_demo/state');
    assert.equal(init.method, 'PATCH');
    assert.equal(init.headers['X-CSRF-Token'], 'csrf_demo');
    assert.deepEqual(JSON.parse(init.body), { state: 'REQUIERE_REVISION', expected_version: 1 });
    return response({ record: { ...row, state: 'REQUIERE_REVISION', version: 2 } });
  });
  assert.equal(patch.record.version, 2);
  const detail = await financeRealRequest({ ...options, ref: row.entry_ref }, async (url, init) => {
    assert.equal(url, base + '/balance_demo');
    assert.equal(init.method, 'GET');
    return response({ record: row });
  });
  assert.equal(detail.record.ref, row.entry_ref);
});

test('el bootstrap publica solo flag servidor exacto y separa demo DEV de UI real', () => {
  const app = readFileSync(join(root, 'frontend/admin/AdminApp.tsx'), 'utf8');
  const html = readFileSync(join(root, 'templates/admin/index.html.twig'), 'utf8');
  assert.match(app, /dataset\.financeRealDataEnabled === 'true'/);
  assert.match(app, /<FinanceRealManagement/);
  assert.match(app, /DevFinanceManagement && !financeRealDataEnabled/);
  assert.match(html, /data-finance-real-data-enabled/);
});
