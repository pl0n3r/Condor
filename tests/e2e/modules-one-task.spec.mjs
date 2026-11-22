import { test, expect } from '@playwright/test';
import { build } from 'vite';
import react from '@vitejs/plugin-react';
import { createServer } from 'node:http';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { relative, resolve, sep, join, extname } from 'node:path';

const baselineSha = '7da9013d7bff92bc87aa3b4527e40c6b9facad5c';
const base = process.cwd();
const modules = [
  'frontend/admin/OrdersManagement.tsx',
  'frontend/admin/InventoryManagement.tsx',
  'frontend/admin/PayrollManagement.tsx',
  'frontend/admin/FinanceManagement.tsx',
];
let output;
let httpServer;
let origin;
const builds = new Map();

const orders = {
  orders: [{
    id: 'order-fixture-0001', inventory_source_id: 'source-demo', sales_channel_id: null,
    customer_id: null, order_status: 'open', payment_status: 'pending',
    fulfillment_status: 'reserved', currency: 'COP', total_amount_minor: 120000,
    created_at: '2026-11-20T11:00:00Z', lines: [],
  }],
  pagination: { page: 1, per_page: 20, total: 1, has_more: false },
  sources: [{ id: 'source-demo', name: 'Sede principal', legal_entity_id: 'company-demo' }],
  price_lists: [{ id: 'price-demo', name: 'Lista de prueba', currency: 'COP' }],
  customers: [], channels: [],
  variants: [{ id: 'variant-demo', sku: 'SKU-DEMO', name: 'Única', product_name: 'Producto de prueba' }],
};
const inventory = {
  legal_entity: { id: 'company-demo', name: 'Empresa de prueba' },
  branch: { id: 'branch-demo', name: 'Sede principal' },
  sources: [{ id: 'source-demo', name: 'Sede principal', slug: 'principal', type: 'branch',
    branch_id: 'branch-demo', legal_entity_id: 'company-demo' }],
  variants: [{ id: 'variant-demo', sku: 'SKU-DEMO', name: 'Única', product_name: 'Producto de prueba' }],
  balances: [{ id: 'balance-demo', source_id: 'source-demo', legal_entity_id: 'company-demo',
    variant: { id: 'variant-demo', sku: 'SKU-DEMO', name: 'Única', product_name: 'Producto de prueba' },
    quantity: 12, version: 1 }],
  movements: [], can_manage_logical_sources: false,
};

async function buildFixture(phase) {
  const old = phase === 'before';
  const original = new Map();
  const replacements = new Set();
  if (old) {
    for (const path of modules) {
      original.set(path, execFileSync('git', ['show', baselineSha + ':' + path], {
        cwd: base, encoding: 'utf8', maxBuffer: 2_000_000,
      }));
    }
  }
  const virtual = 'virtual:condor-1963-one-task';
  const js = [
    "import React from 'react';",
    "import { createRoot } from 'react-dom/client';",
    "import '/frontend/admin/admin.css';",
    "import '/frontend/admin/admin-ui.css';",
    "import { OrdersManagement } from '/frontend/admin/OrdersManagement.tsx';",
    "import { InventoryManagement } from '/frontend/admin/InventoryManagement.tsx';",
    "import { PayrollManagement } from '/frontend/admin/PayrollManagement.tsx';",
    "import { FinanceManagement, FinanceRealManagement } from '/frontend/admin/FinanceManagement.tsx';",
    "const query = new URLSearchParams(window.location.search);",
    "const moduleName = query.get('module');",
    "const readOnly = query.has('readOnly');",
    "const permissions = readOnly ? ['users.view'] : ['orders.view','orders.create','inventory.view','inventory.create','inventory.update','users.view','users.create','settings.view','settings.create'];",
    "const components = {orders:OrdersManagement, inventory:InventoryManagement, payroll:PayrollManagement, finance:FinanceRealManagement, demo:FinanceManagement};",
    "const C = components[moduleName] || OrdersManagement;",
    "const props = {branchId:'branch-demo',tenantId:'tenant-demo',companyId:'company-demo',csrfToken:'fixture-only',enabled:true,permissions};",
    "createRoot(document.getElementById('fixture-root')).render(React.createElement(C, props));",
  ].join('\n');
  const result = await build({
    configFile: false, root: base, plugins: [
      {
        name: 'one-task-baseline',
        enforce: 'pre',
        load(id) {
          if (!old) return null;
          const pathname = id.split('?')[0];
          const path = relative(base, pathname).split(sep).join('/');
          if (original.has(path)) {
            replacements.add(path);
            return original.get(path);
          }
          return null;
        },
      },
      {
        name: 'one-task-fixture',
        resolveId(id) { return id === virtual ? virtual : null; },
        load(id) { return id === virtual ? js : null; },
      },
      react(),
    ],
    build: {
      outDir: join(output, phase), emptyOutDir: true, cssCodeSplit: true,
      rollupOptions: { input: virtual, output: { entryFileNames: 'app.js' } },
    },
  });
  const entries = (Array.isArray(result) ? result.flatMap(item => item.output) : result.output);
  const css = entries.find(item => item.type === 'asset' && item.fileName.endsWith('.css'));
  if (!css) throw new Error('El fixture no generó hojas de estilo');
  if (old && replacements.size !== modules.length) {
    throw new Error('No se pudo compilar el baseline exacto para los cuatro módulos: '
      + Array.from(replacements).join(', '));
  }
  builds.set(phase, { stylesheet: css.fileName });
}

async function mockApi(page, empty = false) {
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let payload = { records: [] };
    if (path.endsWith('/inventory')) payload = inventory;
    if (path.endsWith('/orders')) {
      const filtered = new URL(route.request().url()).searchParams.has('status');
      payload = empty || filtered ? { ...orders, orders: [], pagination: { ...orders.pagination, total: 0 } } : orders;
    }
    if (path.includes('restock')) payload = { suggestions: [] };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });
  });
}
async function openFixture(page, phase, moduleName, empty = false) {
  await mockApi(page, empty);
  await page.goto(origin + '/' + phase + '?module=' + moduleName);
}
async function evidence(page, info, label, phase, width) {
  await info.attach(label + '-' + phase + '-' + width + '.png', {
    body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
  });
}

test.describe('Condor #1963: un trabajo por pantalla con estados de primer uso', () => {
  test.describe.configure({ mode: 'serial' });
  test.beforeAll(async () => {
    test.setTimeout(180_000);
    output = await mkdtemp(join(tmpdir(), 'condor-1963-one-task-'));
    await buildFixture('before');
    await buildFixture('after');
    httpServer = createServer(async (request, response) => {
      const url = new URL(request.url, 'http://localhost');
      const parts = url.pathname.split('/').filter(Boolean);
      const phase = parts[0];
      if (!builds.has(phase)) { response.writeHead(404).end(); return; }
      if (parts.length === 1) {
        const css = builds.get(phase).stylesheet;
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end('<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
          + '<link rel="stylesheet" href="/' + phase + '/' + css + '"></head><body>'
          + '<div class="admin-shell admin-shell-tenant"><main class="workspace">'
          + '<div id="fixture-root"></div></main></div>'
          + '<script type="module" src="/' + phase + '/app.js"></script></body></html>');
        return;
      }
      const dirname = resolve(output, phase);
      const filename = resolve(dirname, parts.slice(1).join('/'));
      if (!filename.startsWith(dirname + sep)) { response.writeHead(404).end(); return; }
      try {
        const bytes = await readFile(filename);
        response.writeHead(200, {
          'Content-Type': extname(filename) === '.css' ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8',
        });
        response.end(bytes);
      } catch { response.writeHead(404).end(); }
    });
    await new Promise(done => httpServer.listen(0, '127.0.0.1', done));
    origin = 'http://127.0.0.1:' + httpServer.address().port;
  });
  test.afterAll(async () => {
    if (httpServer) await new Promise(done => httpServer.close(done));
    if (output) await rm(output, { recursive: true, force: true });
  });

  test('Pedidos: listado en viewport 1440, CSV secundario y filtro contextual', async ({ page }, info) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openFixture(page, 'before', 'orders');
    await expect(page.getByRole('table', { name: 'Pedidos registrados' })).toBeVisible();
    await evidence(page, info, 'pedidos', 'before', 1440);
    await openFixture(page, 'after', 'orders');
    const table = page.getByRole('table', { name: 'Pedidos registrados' });
    await expect(table).toBeVisible();
    expect((await table.boundingBox()).y).toBeLessThan(900);
    await expect(page.getByRole('form', { name: 'Exportar pedidos a CSV' })).toHaveCount(0);
    await evidence(page, info, 'pedidos', 'after', 1440);
    await page.getByRole('button', { name: 'Exportar CSV' }).click();
    await expect(page.getByRole('form', { name: 'Exportar pedidos a CSV' })).toBeVisible();
    await page.getByRole('button', { name: 'Ocultar exportación' }).click();
    await expect(page.getByRole('form', { name: 'Exportar pedidos a CSV' })).toHaveCount(0);
  });

  test('Inventario: existencias visibles antes de importar CSV o consultar resurtido', async ({ page }, info) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openFixture(page, 'before', 'inventory');
    await expect(page.getByRole('heading', { name: 'Inventario', exact: true })).toBeVisible();
    await evidence(page, info, 'inventario', 'before', 1440);
    await openFixture(page, 'after', 'inventory');
    const table = page.getByRole('table', { name: 'Saldos por fuente y producto' });
    await expect(table).toBeVisible();
    expect((await table.boundingBox()).y).toBeLessThan(900);
    await expect(page.locator('#inventory-secondary-tools')).toHaveCount(0);
    await evidence(page, info, 'inventario', 'after', 1440);
    await page.getByRole('button', { name: 'Importar CSV y resurtido' }).click();
    await expect(page.locator('#inventory-secondary-tools')).toBeVisible();
  });

  // Los dos módulos comparten el mismo contrato visual sin duplicar pasos de
  // captura, aserciones ni apertura de la acción principal.
  for (const scene of [
    {
      name: 'Nómina: sin datos muestra CTA real y sin indicadores cero',
      module: 'payroll', label: 'nomina', panel: '#payroll-management',
      before: 'No hay registros para este filtro.',
      after: 'Aún no hay registros de nómina',
      action: 'Registra la primera nómina', dialog: 'Nuevo registro de nómina',
      keyboard: true,
    },
    {
      name: 'Finanzas: primer uso con CTA, sin KPI artificiales',
      module: 'finance', label: 'finanzas', panel: '#finance-management',
      before: 'No hay registros para este ámbito y filtro.',
      after: 'Aún no hay registros financieros',
      action: 'Crear primer registro financiero', dialog: 'Crear registro financiero',
      keyboard: false,
    },
  ]) {
    test(scene.name, async ({ page }, info) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await openFixture(page, 'before', scene.module);
      await expect(page.getByText(scene.before)).toBeVisible();
      await evidence(page, info, scene.label, 'before', 390);
      await openFixture(page, 'after', scene.module);
      await expect(page.getByText(scene.after)).toBeVisible();
      await expect(page.getByText(scene.before)).toHaveCount(0);
      await expect(page.locator(scene.panel + ' .ui-metrics')).toHaveCount(0);
      await evidence(page, info, scene.label, 'after', 390);
      const action = page.getByRole('button', { name: scene.action });
      if (scene.keyboard) {
        await action.focus();
        await expect(action).toBeFocused();
        await page.keyboard.press('Enter');
      } else {
        await action.click();
      }
      await expect(page.getByRole('dialog', { name: scene.dialog })).toBeVisible();
      if (scene.keyboard) {
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog', { name: scene.dialog })).toBeHidden();
      }
    });
  }

  test('Comparativas complementarias 390/1440 de los cuatro módulos con su baseline real', async ({ page }, info) => {
    const scenes = [
      ['orders', 'Pedidos', 'pedidos', 390, 844],
      ['inventory', 'Inventario', 'inventario', 390, 844],
      ['payroll', 'Nómina', 'nomina', 1440, 900],
      ['finance', 'Finanzas', 'finanzas', 1440, 900],
    ];
    for (const [moduleName, title, label, width, height] of scenes) {
      await page.setViewportSize({ width, height });
      for (const phase of ['before', 'after']) {
        await openFixture(page, phase, moduleName);
        await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await evidence(page, info, label, phase, width);
      }
    }
  });

  test('Permisos: sin creación no se ofrece un CTA que no puede ejecutar', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.route('**/api/**', route => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ records: [] }),
    }));
    await page.goto(origin + '/after?module=payroll&readOnly=1');
    await expect(page.getByText('Aún no hay registros de nómina')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Registra la primera nómina' })).toHaveCount(0);
  });
});
