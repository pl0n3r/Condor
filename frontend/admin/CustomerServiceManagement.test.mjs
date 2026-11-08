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
const workspace = mkdtempSync(join(cwd, 'node_modules', 'customer-qa-'));
const output = join(workspace, 'compiled.cjs');
after(() => rmSync(workspace, { recursive: true, force: true }));
await build({ entryPoints: [join(cwd, 'frontend/admin/CustomerServiceManagement.tsx')],
  outfile: output, bundle: true, platform: 'node', format: 'cjs', jsx: 'automatic',
  external: ['react', 'react/jsx-runtime'], logLevel: 'silent' });

const { requestCustomerServiceCase, CustomerServiceManagement } =
  createRequire(import.meta.url)(output);
const tenant = 'tenant_qa';
const company = 'company_qa';
const branch = 'branch_qa';
const base = { enabled: true, allowed: true, tenant, company, branch };
const path = '/api/v1/branches/branch_qa/customer-service-real/records';
const fixture = { tenant_id: tenant, company_id: company, record_ref: 'caso_qa',
  kind: 'PQRS', state: 'PENDIENTE', version: 1, occurred_at: '2026-11-08 16:00:00' };
const reply = (value, code = 200) => new Response(JSON.stringify(value),
  { status: code, headers: { 'Content-Type': 'application/json' } });
const never = () => { throw new Error('No se permite contacto HTTP'); };
const run = (options, transport) => requestCustomerServiceCase({ ...base, ...options }, transport);
const panelProps = { enabled: true, tenantId: tenant, companyId: company, branchId: branch,
  permissions: ['inventory.view', 'inventory.create', 'inventory.update'], csrfToken: 'csrf_qa' };

test('sin bandera, ámbito o autorización no hay requests ni casos visibles en SSR', async () => {
  for (const patch of [{ enabled: false }, { allowed: false }, { tenant: '' },
    { branch: '' }, { company: '' }, { company: '__unassigned__' }]) {
    const outcome = await run(patch, never);
    assert.notEqual(outcome.status, 'ready');
  }
  const disabled = renderToString(React.createElement(CustomerServiceManagement,
    { ...panelProps, enabled: false }));
  assert.match(disabled, /El módulo aún no está habilitado/);
  assert.doesNotMatch(disabled, /caso_qa|Nuevo caso/);
  const unauthorized = renderToString(React.createElement(CustomerServiceManagement,
    { ...panelProps, permissions: [] }));
  assert.match(unauthorized, /Acceso denegado/);
  const booting = renderToString(React.createElement(CustomerServiceManagement, panelProps));
  assert.match(booting, /Cargando casos/);
});

test('respuesta de casos paginados: orden, cursor y límites de tenant verificables', async () => {
  const first = await run({}, (url, init) => {
    assert.equal(url, path);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    return reply({ records: [fixture], next_cursor: null });
  });
  assert.equal(first.status, 'ready');
  assert.equal(first.rows?.[0].ref, 'caso_qa');
  assert.equal(first.cursor, null);

  const later = await run({ after: 'caso_qa' }, url => {
    assert.equal(url, path + '?after=caso_qa');
    return reply({ records: [{ ...fixture, record_ref: 'caso_qb' }], next_cursor: null });
  });
  assert.equal(later.rows?.[0].ref, 'caso_qb');
  const hundred = Array.from({ length: 100 }, (_, i) =>
    ({ ...fixture, record_ref: 'caso_' + String(i).padStart(3, '0') }));
  const page = await run({}, () => reply({ records: hundred, next_cursor: 'caso_099' }));
  assert.equal(page.rows?.length, 100);
  assert.equal(page.cursor, 'caso_099');
});

test('desconfía de JSON ajeno, paginación irregular y datos personales', async () => {
  const badRecords = [
    { ...fixture, tenant_id: 'otro_tenant' },
    { ...fixture, company_id: 'otra_empresa' },
    { ...fixture, record_ref: 'cliente@example.com' },
    { ...fixture, record_ref: 'caso_123456789' },
    { ...fixture, kind: 'DATO_PERSONAL' },
    { ...fixture, state: 'APROBADO' },
    { ...fixture, version: 0 },
    { ...fixture, occurred_at: 'invalid' },
  ];
  for (const wrong of badRecords) {
    const value = await run({}, () => reply({ records: [wrong], next_cursor: null }));
    assert.equal(value.status, 'error', JSON.stringify(wrong));
  }
  const invalidBodies = [
    { records: [fixture] },
    { records: 'no-array', next_cursor: null },
    { records: [fixture, fixture], next_cursor: null },
    { records: [fixture], next_cursor: 'caso_no_corresponde' },
    { records: [], next_cursor: 'caso_qa' },
    { records: Array(101).fill(fixture), next_cursor: null },
    { records: [{ ...fixture, record_ref: 'caso_qz' }, fixture], next_cursor: null },
  ];
  for (const body of invalidBodies) {
    assert.equal((await run({}, () => reply(body))).status, 'error');
  }
  assert.equal((await run({ after: 'caso_qz' }, () =>
    reply({ records: [fixture], next_cursor: null }))).status, 'error');
});

test('crear y revisar casos: payload mínimo, cookie, CSRF y versión optimista', async () => {
  const written = await run({ verb: 'POST', ref: 'caso_qa', kind: 'PQRS', csrf: 'csrf_qa' },
    (url, init) => {
      assert.equal(url, path);
      assert.equal(init.method, 'POST');
      assert.equal(init.headers['X-CSRF-Token'], 'csrf_qa');
      assert.equal(init.credentials, 'same-origin');
      assert.deepEqual(JSON.parse(init.body), { record_ref: 'caso_qa', kind: 'PQRS' });
      return reply({ record: fixture }, 201);
    });
  assert.equal(written.entry?.version, 1);

  const updated = await run({ verb: 'PATCH', ref: 'caso_qa', version: 1,
    next: 'EN_REVISION', csrf: 'csrf_qa' }, (url, init) => {
    assert.equal(url, path + '/caso_qa/state');
    assert.deepEqual(JSON.parse(init.body), { state: 'EN_REVISION', expected_version: 1 });
    return reply({ record: { ...fixture, state: 'EN_REVISION', version: 2 } });
  });
  assert.equal(updated.entry?.state, 'EN_REVISION');
  assert.equal(updated.entry?.version, 2);

  const detail = await run({ ref: 'caso_qa' }, url => {
    assert.equal(url, path + '/caso_qa');
    return reply({ record: fixture });
  });
  assert.equal(detail.entry?.kind, 'PQRS');
});

test('operaciones inválidas y estados HTTP desconocidos se detienen antes de revelar filas', async () => {
  const rejected = [
    { verb: 'POST', ref: 'caso_qa', kind: 'PQRS' },
    { verb: 'POST', ref: 'caso_123456789', kind: 'PQRS', csrf: 'x' },
    { verb: 'POST', ref: 'caso_qa', kind: 'PERSONAL', csrf: 'x' },
    { verb: 'PATCH', ref: 'caso_qa', next: 'APROBADO', version: 1, csrf: 'x' },
    { verb: 'PATCH', ref: 'caso_qa', next: 'EN_REVISION', version: 0, csrf: 'x' },
    { verb: 'PATCH', ref: 'caso_qa', next: 'EN_REVISION', version: 1 },
    { after: 'fixture:case/demo' },
  ];
  for (const options of rejected) {
    assert.equal((await run(options, never)).status, 'error');
  }

  for (const status of [401, 403]) {
    assert.equal((await run({}, () => reply({ error: 'unauthorized' }, status))).status, 'denied');
  }
  assert.equal((await run({}, () => reply({ error: 'feature_unavailable' }, 404))).status, 'disabled');
  for (const badResponse of [reply({ error: 'missing' }, 404), reply({}, 500),
    new Response('{', { status: 200 }), new Response('<html>404</html>', { status: 404 })]) {
    assert.equal((await run({}, () => badResponse)).status, 'error');
  }
  assert.equal((await run({}, () => { throw Error('offline'); })).status, 'error');
});
