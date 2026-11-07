/** M02: contratos de sesión sintética, guard OFF y transporte autenticado. */
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
const temp = mkdtempSync(join(root, 'node_modules', '.cache-contracts1788-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const output = join(temp, 'contracts.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/ContractsManagement.tsx')],
  outfile: output, format: 'cjs', platform: 'node', bundle: true, jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'],
  define: { 'import.meta.env.DEV': 'true' }, logLevel: 'silent' });
const { ContractsManagement, contractsSyntheticEnabled, syntheticContractRequest,
  ContractsRealManagement, contractRealRequest } =
  createRequire(import.meta.url)(output);
const branchId = 'sede-ficticia';
const scope = 'empresa-ficticia|razon-social-a|sede-ficticia';
const enabled = { enabled: true, allowed: true, branchId, scope };
const endpoint = '/api/v1/branches/' + branchId + '/contracts';
const example = { kind: 'contract', ref: 'fixture:contract/demo', state: 'REQUIERE_REVISION',
  synthetic_only: true };
const json = (data, status = 200) => new Response(JSON.stringify(data), { status,
  headers: { 'Content-Type': 'application/json' } });
const transportFor = data => async (url, options) => {
  assert.equal(options.credentials, 'same-origin');
  assert.equal(options.cache, 'no-store');
  assert.equal(options.headers.Accept, 'application/json');
  assert.equal(url, endpoint);
  return json(data);
};

test('flag ausente u OFF y RBAC denegado: cero HTTP y mensaje seguro', async () => {
  const deny = () => { throw Error('HTTP no autorizado'); };
  for (const value of [undefined, 'false', 'TRUE', '1', '']) {
    const host = value === undefined ? null : { dataset: { contractsSyntheticApiEnabled: value } };
    assert.equal(contractsSyntheticEnabled(host), false);
  }
  assert.equal(contractsSyntheticEnabled({ dataset: { contractsSyntheticApiEnabled: 'true' } }), true);
  assert.deepEqual(await syntheticContractRequest({ ...enabled, enabled: false }, deny),
    { status: 'disabled' });
  assert.deepEqual(await syntheticContractRequest({ ...enabled, allowed: false }, deny),
    { status: 'denied' });
  const before = globalThis.document;
  try {
    globalThis.document = { getElementById: () => ({ dataset: { contractsSyntheticApiEnabled: 'false' } }) };
    const html = renderToStaticMarkup(React.createElement(ContractsManagement, {
      tenantId: 't', companyId: 'c', branchId: 'b', permissions: ['inventory.view'],
    }));
    assert.match(html, /deshabilitada por el servidor/);
    assert.doesNotMatch(html, /fixture:contract|demo|Crear borrador ficticio/);
    globalThis.document = { getElementById: () => ({ dataset: { contractsSyntheticApiEnabled: 'true' } }) };
    const authorized = renderToStaticMarkup(React.createElement(ContractsManagement, {
      tenantId: 't', companyId: 'c', branchId: 'b', permissions: ['inventory.view'],
    }));
    assert.match(authorized, /Cargando contratos sintéticos/);
    const denied = renderToStaticMarkup(React.createElement(ContractsManagement, {
      tenantId: 't', companyId: 'c', branchId: 'b', permissions: ['users.view'],
    }));
    assert.match(denied, /No tienes permiso/);
  } finally { globalThis.document = before; }
});

test('GET lista: scope de vista, permiso y validación estricta de datos sintéticos', async () => {
  const result = await syntheticContractRequest(enabled,
    transportFor({ records: [example], synthetic_only: true }));
  assert.deepEqual(result, { status: 'ready', records: [{
    ref: example.ref, category: example.kind, phase: example.state, scope,
  }] });
  assert.deepEqual(await syntheticContractRequest(enabled,
    transportFor({ records: [], synthetic_only: true })), { status: 'ready', records: [] });
  for (const invalid of [
    { records: [example], synthetic_only: false },
    { records: [{ ...example, state: 'APROBADO' }], synthetic_only: true },
    { records: [{ ...example, company_id: 'empresa-ajena' }], synthetic_only: true },
    { records: [example, example], synthetic_only: true },
    { records: [{ ...example, ref: 'fixture:contract/12345678' }], synthetic_only: true },
  ]) {
    assert.equal((await syntheticContractRequest(enabled, transportFor(invalid))).status, 'error');
  }
});

test('fallos HTTP: 401/403 denegados, 404 sin endpoint apagado, red y JSON inválido', async () => {
  for (const code of [401, 403]) {
    assert.equal((await syntheticContractRequest(enabled,
      () => json({ error: 'denied' }, code))).status, 'denied');
  }
  assert.equal((await syntheticContractRequest(enabled,
    () => json({ error: 'not_found' }, 404))).status, 'disabled');
  assert.equal((await syntheticContractRequest(enabled, () => { throw Error('network'); })).status,
    'error');
  assert.equal((await syntheticContractRequest(enabled,
    () => new Response('{', { status: 200 }))).status, 'error');
});

test('GET detalle, POST y PATCH usan el método, CSRF y solo transiciones ficticias', async () => {
  const ref = example.ref;
  const detail = await syntheticContractRequest({ ...enabled, ref }, async (url, options) => {
    assert.equal(url, endpoint + '/contract/demo');
    assert.equal(options.method, 'GET');
    return json({ record: example, synthetic_only: true });
  });
  assert.equal(detail.record?.ref, ref);
  let writes = 0;
  const write = async (url, options) => {
    writes += 1;
    assert.equal(options.headers['X-CSRF-Token'], 'csrf-sintetico');
    assert.equal(options.credentials, 'same-origin');
    if (options.method === 'POST') {
      assert.equal(url, endpoint);
      assert.deepEqual(JSON.parse(options.body), { kind: 'contract', ref });
      return json({ record: example, synthetic_only: true }, 201);
    }
    assert.equal(options.method, 'PATCH');
    assert.equal(url, endpoint + '/contract/demo/state');
    assert.deepEqual(JSON.parse(options.body), { state: 'EN_REVISION' });
    return json({ record: { ...example, state: 'EN_REVISION' }, synthetic_only: true });
  };
  const post = await syntheticContractRequest({ ...enabled, method: 'POST',
    ref, kind: 'contract', csrf: 'csrf-sintetico' }, write);
  assert.equal(post.record?.phase, 'REQUIERE_REVISION');
  const patch = await syntheticContractRequest({ ...enabled, method: 'PATCH',
    ref, state: 'EN_REVISION', csrf: 'csrf-sintetico' }, write);
  assert.equal(patch.record?.phase, 'EN_REVISION');
  assert.equal(writes, 2);
  for (const invalid of [
    { ...enabled, method: 'POST', ref, kind: 'contract' },
    { ...enabled, method: 'PATCH', ref, state: 'EN_REVISION' },
    { ...enabled, method: 'POST', ref: 'fixture:contract/12345678', kind: 'contract', csrf: 'x' },
    { ...enabled, method: 'POST', ref, kind: 'template', csrf: 'x' },
    { ...enabled, method: 'PATCH', ref, state: 'APROBADO', csrf: 'x' },
  ]) assert.equal((await syntheticContractRequest(invalid, () => {
    throw Error('No HTTP para solicitudes inválidas');
  })).status, 'error');
});

test('bootstrap de dev: solo servidor autorizado, no en producción', () => {
  const controller = readFileSync(join(root, 'src/Http/Controller/AdminController.php'), 'utf8');
  const twig = readFileSync(join(root, 'templates/admin/index.html.twig'), 'utf8');
  assert.match(controller, /contracts_synthetic_api_enabled.*getParameter\('kernel.environment'\) === 'dev'/s);
  assert.match(controller, /getenv\('CONDOR_CONTRACTS_SYNTHETIC_API'\) === '1'/);
  assert.match(twig, /data-contracts-synthetic-api-enabled=/);
  assert.match(twig, /contracts_synthetic_api_enabled \? 'true' : 'false'/);
});


const realRow = {
  tenant_id: 'tenant_demo', company_id: 'company_demo',
  record_ref: 'contract_demo', worker_ref: 'worker_demo', template_ref: 'template_demo',
  kind: 'contract', state: 'REQUIERE_REVISION', version: 1,
};
const realOpts = {
  enabled: true, allowed: true, tenantId: 'tenant_demo',
  companyId: 'company_demo', branchId: 'branch_demo',
};
const realUrl = '/api/v1/branches/branch_demo/contracts/real-records';
const realDeny = () => { throw Error('No debe hacerse HTTP'); };

test('Contratación persistida: OFF, RBAC, sin razón social y referencias inválidas nunca emiten HTTP', async () => {
  for (const x of [
    { ...realOpts, enabled: false },
    { ...realOpts, allowed: false },
    { ...realOpts, companyId: '__unassigned__' },
    { ...realOpts, tenantId: '' },
    { ...realOpts, method: 'POST', ref: 'contract_demo', workerRef: 'worker_demo',
      templateRef: 'template_demo', kind: 'contract' },
    { ...realOpts, method: 'POST', ref: 'contract_demo', workerRef: 'worker_demo',
      templateRef: 'template_demo', kind: 'contract', csrf: '' },
    { ...realOpts, method: 'POST', ref: 'contract_demo', workerRef: 'email@bad.test',
      templateRef: 'template_demo', kind: 'contract', csrf: 'csrf' },
    { ...realOpts, method: 'PATCH', ref: 'contract_demo',
      state: 'REQUIERE_REVISION', expectedVersion: 1, csrf: 'csrf' },
    { ...realOpts, method: 'PATCH', ref: 'contract_demo',
      state: 'EN_REVISION', expectedVersion: 0, csrf: 'csrf' },
    { ...realOpts, ref: '../other-company' },
  ]) {
    const out = await contractRealRequest(x, realDeny);
    assert.notEqual(out.status, 'ready');
  }
});

test('Contratación persistida: lista estricta por empresa y sin filtraciones cross-tenant', async () => {
  const successful = await contractRealRequest(realOpts, async (url, options) => {
    assert.equal(url, realUrl);
    assert.equal(options.method, 'GET');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.headers['X-CSRF-Token'], undefined);
    return json({ records: [realRow] });
  });
  assert.equal(successful.status, 'ready');
  assert.equal(successful.records?.[0]?.ref, 'contract_demo');
  for (const rows of [
    [{ ...realRow, tenant_id: 'tenant_other' }],
    [{ ...realRow, company_id: 'company_other' }],
    [{ ...realRow, state: 'FIRMADO' }],
    [{ ...realRow, worker_ref: 'name@private.test' }],
    [{ ...realRow, version: 0 }],
    [realRow, realRow],
    Array.from({ length: 101 }, (_, i) => ({ ...realRow, record_ref: 'contract_' + i })),
  ]) {
    const out = await contractRealRequest(realOpts, () => json({ records: rows }));
    assert.equal(out.status, 'error');
  }
});

test('Contratación persistida: POST con CSRF, PATCH versionado y GET de detalle', async () => {
  const ref = 'contract_demo';
  const csrf = 'csrf_ficticio';
  const post = await contractRealRequest({ ...realOpts,
    method: 'POST', ref, workerRef: 'worker_demo', templateRef: 'template_demo',
    kind: 'contract', csrf }, async (url, options) => {
    assert.equal(url, realUrl);
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-CSRF-Token'], csrf);
    assert.equal(options.credentials, 'same-origin');
    assert.deepEqual(JSON.parse(options.body), {
      record_ref: ref, worker_ref: 'worker_demo',
      template_ref: 'template_demo', kind: 'contract',
    });
    return json({ record: realRow }, 201);
  });
  assert.equal(post.status, 'ready');
  const detail = await contractRealRequest({ ...realOpts, ref }, async (url, options) => {
    assert.equal(url, realUrl + '/' + ref);
    assert.equal(options.method, 'GET');
    return json({ record: realRow });
  });
  assert.equal(detail.record?.state, 'REQUIERE_REVISION');
  const transition = await contractRealRequest({ ...realOpts, method: 'PATCH',
    ref, state: 'EN_REVISION', expectedVersion: 1, csrf },
    async (url, options) => {
      assert.equal(url, realUrl + '/' + ref + '/state');
      assert.equal(options.headers['X-CSRF-Token'], csrf);
      assert.deepEqual(JSON.parse(options.body), {
        state: 'EN_REVISION', expected_version: 1,
      });
      return json({ record: { ...realRow, state: 'EN_REVISION', version: 2 } });
    });
  assert.equal(transition.record?.version, 2);
});

test('Contratación persistida: errores HTTP fail-closed, formato y scope nunca suplantados', async () => {
  for (const status of [401, 403]) {
    assert.equal((await contractRealRequest(realOpts,
      () => json({ error: 'unauthorized' }, status))).status, 'denied');
  }
  assert.equal((await contractRealRequest(realOpts,
    () => json({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  assert.equal((await contractRealRequest(realOpts, () => { throw Error('network'); })).status, 'error');
  assert.equal((await contractRealRequest(realOpts, () => new Response('{', { status: 200 }))).status, 'error');
  for (const payload of [
    { record: { ...realRow, company_id: 'company_other' } },
    { record: { ...realRow, record_ref: 'other' } },
  ]) {
    assert.equal((await contractRealRequest({ ...realOpts, ref: 'contract_demo' },
      () => json(payload))).status, 'error');
  }
});

test('Contratación persistida: UI desactivada, denegación RBAC y loading sin inventar datos', () => {
  const baseProps = {
    enabled: false, tenantId: 'tenant_demo', companyId: 'company_demo',
    branchId: 'branch_demo', permissions: ['inventory.view'], csrfToken: 'csrf',
  };
  const off = renderToStaticMarkup(React.createElement(ContractsRealManagement, baseProps));
  assert.match(off, /permanece desactivada por el servidor/);
  assert.doesNotMatch(off, /contract_demo|fixture:contract/);
  const denied = renderToStaticMarkup(React.createElement(ContractsRealManagement, {
    ...baseProps, enabled: true, permissions: [],
  }));
  assert.match(denied, /Acceso denegado/);
  const readyForLoad = renderToStaticMarkup(React.createElement(ContractsRealManagement, {
    ...baseProps, enabled: true,
  }));
  assert.match(readyForLoad, /Cargando registros de contratación/);
});
