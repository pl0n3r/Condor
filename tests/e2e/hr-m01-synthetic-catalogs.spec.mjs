import { expect, test } from '@playwright/test';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

// Browser fixture imports the real TSX module through Vite. The only network
// responses are synthetic and intercepted; no production login or real people.
const TENANT = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const COMPANY = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const BRANCH = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const PROPS = { tenantId: TENANT, companyId: COMPANY, branchId: BRANCH,
  permissions: ['users.view'] };
const CATALOGS = [
  ['S02', 'Cargos', 'positions', 'positions'],
  ['S03', 'Áreas', 'areas', 'areas'],
  ['S04', 'Sedes', 'branch-preview', 'branches'],
  ['S05', 'Organigrama', 'org-chart', 'nodes'],
  ['S06', 'Novedades', 'changes', 'changes'],
  ['S07', 'Permisos', 'permits', 'permits'],
  ['S08', 'Vacaciones', 'vacations', 'vacations'],
  ['S09', 'Incapacidades', 'incapacities', 'incapacities'],
  ['S10', 'Evaluaciones', 'evaluations', 'evaluations'],
  ['S11', 'Portal ficticio', 'employee-portal', 'items'],
];
let vite;
let origin;

function entry() {
  return {
    name: 'hr-m01-synthetic-chromium-entry',
    resolveId(id) {
      if (id === '/__m01_entry__.js') return '\0m01:entry';
    },
    load(id) {
      if (id !== '\0m01:entry') return;
      return [
        "import React from 'react';",
        "import { createRoot } from 'react-dom/client';",
        "import { HumanResourcesManagement } from '/frontend/admin/HumanResourcesManagement.tsx';",
        "const root = createRoot(document.getElementById('app'));",
        "window.renderM01 = props => root.render(React.createElement(HumanResourcesManagement, props));",
      ].join('\n');
    },
  };
}

async function open(page) {
  const html = [
    '<!doctype html><html lang="es"><body>',
    '<div id="condor-admin-root" data-hr-synthetic-preview-enabled="true"',
    ' data-hr-real-data-enabled="false"></div>',
    '<main id="app"></main>',
    '<script type="module" src="/__m01_entry__.js"></script>',
    '</body></html>',
  ].join('');
  const transformed = await vite.transformIndexHtml('/__m01_fixture__', html);
  await page.route(origin + '/__m01_fixture__', route => route.fulfill({
    status: 200, contentType: 'text/html; charset=utf-8', body: transformed,
  }));
  await page.goto(origin + '/__m01_fixture__');
  await page.waitForFunction(() => typeof window.renderM01 === 'function');
  await page.evaluate(props => window.renderM01(props), PROPS);
  await expect(page.getByRole('heading', { name: 'Catálogos de talento humano · pruebas' })).toBeVisible();
}

test.describe('M01 S02–S11: visualización de catálogos estrictamente sintéticos', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    vite = await createServer({
      configFile: false, root: process.cwd(), appType: 'custom',
      plugins: [entry(), react()],
      server: { host: '127.0.0.1', port: 0, strictPort: false },
    });
    await vite.listen();
    origin = 'http://127.0.0.1:' + vite.httpServer.address().port;
  });

  test.afterAll(async () => {
    await vite?.close();
    vite = undefined;
  });

  test('diez catálogos: carga, dato ficticio, vacío, error, denegación y rechazo de scope', async ({ page }) => {
    test.setTimeout(90_000);
    const requests = [];
    let mode = 'ready';
    await page.route('**/api/v1/branches/**', async route => {
      const request = route.request();
      const url = new URL(request.url());
      requests.push({ url, method: request.method(), headers: request.headers() });
      const catalog = CATALOGS.find(row => url.pathname.endsWith('/hr/' + row[2]));
      if (!catalog) return route.fulfill({ status: 404, body: '{}' });
      const [, label, segment, field] = catalog;
      const ref = mode === 'foreign' ? 'fixture-' + COMPANY + '-' + segment
        : 'fixture-' + segment.replace(/-/g, '_') + '-scope_abcd1234567890';
      const body = { synthetic: true, persisted: false, juridically_binding: false,
        [field]: mode === 'empty' ? [] : [
          { ref, label: 'Entrada ficticia ' + label, synthetic: true },
        ] };
      await new Promise(resolve => setTimeout(resolve, 30));
      await route.fulfill({
        status: mode === 'denied' ? 403 : mode === 'error' ? 500 : 200,
        contentType: 'application/json',
        body: JSON.stringify(mode === 'error' ? { error: 'synthetic_failure' } : body),
      });
    });
    await open(page);
    const select = page.getByLabel('Submódulo M01');
    const reload = page.getByRole('button', { name: 'Reintentar catálogo' });
    for (const [id, label, segment] of CATALOGS) {
      mode = 'ready';
      await select.selectOption(id);
      await reload.click();
      await expect(page.getByRole('table', { name: 'Catálogo sintético de ' + label }))
        .toContainText('Entrada ficticia ' + label);

      for (const scenario of ['empty', 'error', 'denied', 'foreign']) {
        mode = scenario;
        await reload.click();
        if (scenario === 'empty') {
          await expect(page.getByText('No hay registros ficticios para este ámbito.')).toBeVisible();
        } else if (scenario === 'denied') {
          await expect(page.getByRole('alert')).toContainText('Acceso denegado');
        } else {
          await expect(page.getByRole('alert')).toContainText('No se pudo consultar');
        }
      }
      const catalogRequests = requests.filter(q => q.url.pathname.endsWith('/hr/' + segment));
      expect(catalogRequests.length).toBeGreaterThanOrEqual(5);
      for (const req of catalogRequests) {
        expect(req.method).toBe('GET');
        expect(req.url.pathname).toBe('/api/v1/branches/' + BRANCH + '/hr/' + segment);
        expect(req.url.searchParams.get('company_id')).toBe(COMPANY);
      }
    }
    mode = 'ready';
    await reload.click();
    await expect(page.getByRole('table')).toBeVisible();
    expect(requests.length).toBeGreaterThanOrEqual(50);
  });

  test('sin permisos: no hay solicitudes; sin opt-in: la pantalla sigue apagada', async ({ page }) => {
    let calls = 0;
    await page.route('**/api/v1/branches/**', async route => {
      calls += 1;
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });
    await open(page);
    await page.evaluate(props => window.renderM01({ ...props, permissions: [] }), PROPS);
    await expect(page.getByRole('alert')).toContainText('Acceso denegado');
    const countDenied = calls;
    await page.getByRole('button', { name: 'Reintentar catálogo' }).click();
    await expect(page.getByRole('alert')).toContainText('Acceso denegado');
    expect(calls).toBe(countDenied);

    await page.evaluate(props => {
      document.getElementById('condor-admin-root').removeAttribute('data-hr-synthetic-preview-enabled');
      window.renderM01(props);
    }, { ...PROPS, permissions: [] });
    await expect(page.getByText('Talento humano todavía no está habilitado en el servidor.'))
      .toBeVisible();
    expect(calls).toBe(countDenied);
  });
});
