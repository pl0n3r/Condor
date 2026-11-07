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
const temp = mkdtempSync(join(root, 'node_modules', '.cache-payroll1653-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const output = join(temp, 'payroll.cjs');
await build({ entryPoints: [join(root, 'frontend/admin/PayrollManagement.tsx')],
  outfile: output, format: 'cjs', platform: 'node', bundle: true,
  jsx: 'automatic', external: ['react', 'react/jsx-runtime'], logLevel: 'silent' });
const { PayrollManagement, payrollRequest } = createRequire(import.meta.url)(output);

const args = { enabled: true, allowed: true, tenantId: 'tenantA',
  companyId: 'companyA', branchId: 'sedeA' };
const endpoint = '/api/v1/branches/sedeA/payroll/real-records';
const row = { tenant_id: 'tenantA', company_id: 'companyA',
  record_ref: 'registro_demo', employee_ref: 'colaborador_demo',
  period_ref: 'periodo_demo', state: 'BORRADOR', version: 1 };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status,
  headers: { 'Content-Type': 'application/json' } });

test('flag OFF y permisos insuficientes jamás emiten HTTP', async () => {
  const never = () => { throw new Error('No debe ejecutarse HTTP'); };
  assert.deepEqual(await payrollRequest({ ...args, enabled: false }, never), { status: 'disabled' });
  assert.deepEqual(await payrollRequest({ ...args, allowed: false }, never), { status: 'denied' });
  assert.deepEqual(await payrollRequest({ ...args, companyId: '__unassigned__' }, never),
    { status: 'denied' });
  const common = { tenantId: 'tenantA', companyId: 'companyA', branchId: 'sedeA',
    permissions: ['users.view', 'users.create', 'users.update'], csrfToken: 'csrf_test' };
  const off = renderToStaticMarkup(React.createElement(PayrollManagement,
    { ...common, enabled: false }));
  assert.match(off, /aún no está habilitado/);
  assert.doesNotMatch(off, /registro_demo|Nómina \(pruebas\)/);
  const denied = renderToStaticMarkup(React.createElement(PayrollManagement,
    { ...common, enabled: true, permissions: [] }));
  assert.match(denied, /Acceso denegado/);
});

test('GET maneja vacío y registros válidos en ámbito autorizado', async () => {
  const network = async (url, init) => {
    assert.equal(url, endpoint);
    assert.equal(init.method, 'GET');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers.Accept, 'application/json');
    return json({ records: [row] });
  };
  const result = await payrollRequest(args, network);
  assert.deepEqual(result, { status: 'ready', records: [{
    ref: 'registro_demo', tenantId: 'tenantA', companyId: 'companyA',
    employeeRef: 'colaborador_demo', periodRef: 'periodo_demo',
    state: 'BORRADOR', version: 1,
  }] });
  assert.deepEqual(await payrollRequest(args, () => json({ records: [] })),
    { status: 'ready', records: [] });
});

test('falla cerrado ante registros ajenos, estado/version inválida o duplicados', async () => {
  const invalid = [
    { ...row, tenant_id: 'tenantB' },
    { ...row, company_id: 'companyB' },
    { ...row, state: 'PAGADA' },
    { ...row, version: 0 },
    { ...row, record_ref: 'fixture:payroll/demo' },
    { ...row, employee_ref: 'nombres y apellidos' },
  ];
  for (const bad of invalid) {
    assert.equal((await payrollRequest(args, () => json({ records: [bad] }))).status, 'error');
  }
  assert.equal((await payrollRequest(args, () => json({ records: [row, row] }))).status, 'error');
  assert.equal((await payrollRequest(args, () => json({ records: 'invalid' }))).status, 'error');
  assert.equal((await payrollRequest(args, () => json({ records: Array(101).fill(row) }))).status, 'error');
});

test('POST y PATCH envían CSRF, métodos, versión esperada y scope de sede', async () => {
  const create = await payrollRequest({ ...args, method: 'POST',
    ref: 'registro_demo', employeeRef: 'colaborador_demo', periodRef: 'periodo_demo',
    csrf: 'csrf_test' }, async (url, init) => {
      assert.equal(url, endpoint);
      assert.equal(init.method, 'POST');
      assert.equal(init.headers['X-CSRF-Token'], 'csrf_test');
      assert.equal(init.headers['Content-Type'], 'application/json');
      assert.equal(init.credentials, 'same-origin');
      assert.deepEqual(JSON.parse(init.body), { record_ref: 'registro_demo',
        employee_ref: 'colaborador_demo', period_ref: 'periodo_demo' });
      return json({ record: row }, 201);
    });
  assert.equal(create.record?.ref, 'registro_demo');
  const revise = await payrollRequest({ ...args, method: 'PATCH', ref: 'registro_demo',
    state: 'EN_REVISION', expectedVersion: 1, csrf: 'csrf_test' }, async (url, init) => {
      assert.equal(url, endpoint + '/registro_demo/state');
      assert.equal(init.method, 'PATCH');
      assert.equal(init.headers['X-CSRF-Token'], 'csrf_test');
      assert.deepEqual(JSON.parse(init.body), { state: 'EN_REVISION', expected_version: 1 });
      return json({ record: { ...row, state: 'EN_REVISION', version: 2 } });
    });
  assert.equal(revise.record?.version, 2);
});

test('sin CSRF, referencias opacas inválidas o revisión no válida: cero HTTP', async () => {
  const never = () => { throw new Error('No debe ejecutarse HTTP'); };
  for (const request of [
    { ...args, method: 'POST', ref: 'registro_demo', employeeRef: 'emp_demo', periodRef: 'p_demo' },
    { ...args, method: 'PATCH', ref: 'registro_demo', state: 'EN_REVISION', expectedVersion: 1 },
    { ...args, method: 'POST', ref: 'fixture:payroll/demo', employeeRef: 'emp_demo',
      periodRef: 'p_demo', csrf: 'token' },
    { ...args, method: 'PATCH', ref: 'registro_demo', state: 'PAGADA',
      expectedVersion: 1, csrf: 'token' },
    { ...args, method: 'PATCH', ref: 'registro_demo', state: 'EN_REVISION',
      expectedVersion: 0, csrf: 'token' },
  ]) assert.equal((await payrollRequest(request, never)).status, 'error');
});

test('401/403, flag backend OFF, red fallida y JSON inválido', async () => {
  for (const status of [401, 403]) assert.deepEqual(
    await payrollRequest(args, () => json({ error: 'denied' }, status)), { status: 'denied' });
  assert.deepEqual(await payrollRequest(args, () => json({ error: 'feature_unavailable' }, 404)),
    { status: 'disabled' });
  assert.equal((await payrollRequest(args, () => { throw Error('offline'); })).status, 'error');
  assert.equal((await payrollRequest(args, () => new Response('{', { status: 200 }))).status, 'error');
});
