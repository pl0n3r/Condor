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
const { ContractsManagement, contractsSyntheticEnabled, syntheticContractRequest } =
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
