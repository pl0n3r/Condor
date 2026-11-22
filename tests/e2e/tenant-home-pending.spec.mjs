import { expect, test } from '@playwright/test';
import { build } from 'vite';
import react from '@vitejs/plugin-react';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { extname, join, resolve, sep } from 'node:path';

// Solo fixture Vite/React real y respuestas interceptadas: NO Hostinger.
const TENANT = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const COMPANY = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const BRANCH = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const GROUPS = [
  { group: 'Ventas', items: [{ href: '/admin#orders', label: 'Pedidos' }] },
  { group: 'Catálogo e inventario', items: [
    { href: '/admin#stock-alerts', label: 'Alertas de stock' },
  ] },
  { group: 'Personas', items: [{ href: '/admin#payroll-management', label: 'Nómina' }] },
];

const HISTORICAL_MODULE_DIRECTORY = "type AdminNavEntry = { group: string; items: { href: string; label: string }[] } | { href: string; label: string };\n\n/** Descripción de una línea por módulo, para el directorio del inicio. */\nconst HINTS: Record<string, string> = {\n  commerce: 'Clientes, categorías y listas de precios.',\n  orders: 'Crear, seguir y exportar pedidos.',\n  'sales-reports': 'Ventas por periodo y por sede.',\n  'reports-downloads': 'Descarga de informes en CSV.',\n  'customer-service': 'Casos y atención a clientes.',\n  catalog: 'Productos, variantes y políticas de stock.',\n  inventory: 'Existencias, fuentes y ajustes.',\n  'stock-alerts': 'Avisos de stock bajo.',\n  'stock-transfers': 'Traslados entre sedes.',\n  production: 'Recetas y órdenes de producción.',\n  purchasing: 'Solicitudes y órdenes de compra.',\n  'assets-management': 'Activos de la empresa.',\n  'seo-settings': 'Títulos y descripciones para buscadores.',\n  'employees-management': 'Empleados, cargos y asistencia.',\n  'human-resources-management': 'Vinculaciones, novedades y movimientos.',\n  'payroll-management': 'Nómina y novedades de pago.',\n  'contracts-management': 'Contratos de trabajo y su vigencia.',\n  'social-security-management': 'Afiliaciones y aportes.',\n  'finance-management': 'Asientos, periodos y cierres.',\n  'compliance-management': 'Seguridad y salud en el trabajo.',\n  'compliance-evidence-management': 'Evidencia de cumplimiento legal.',\n  'governance-management': 'Órganos, actas y decisiones.',\n  workflows: 'Flujos de trabajo y aprobaciones.',\n  'operations-processes': 'Procesos y procedimientos.',\n  roles: 'Roles, permisos y accesos.',\n  documents: 'Expedientes y evidencias sintéticas; sin firma legal.',\n};\n\nfunction moduleId(href: string): string {\n  const at = href.indexOf('#');\n  return at >= 0 ? href.slice(at + 1) : '';\n}\n\n/** Mapa del administrador: cada módulo agrupado igual que el menú, con una línea que explica para qué sirve. */\nexport function ModuleDirectory({ entries }: Readonly<{ entries: readonly AdminNavEntry[] }>) {\n  const groups = entries.filter((entry): entry is Extract<AdminNavEntry, { group: string }> => 'group' in entry);\n  return (\n    <section className=\"module-directory\" aria-labelledby=\"module-directory-title\">\n      <h2 id=\"module-directory-title\">Módulos</h2>\n      <div className=\"module-directory-groups\">\n        {groups.map((group) => (\n          <div className=\"module-directory-group\" key={group.group}>\n            <h3>{group.group}</h3>\n            <ul>\n              {group.items.map((item) => (\n                <li key={item.href}>\n                  <a href={item.href}>\n                    <strong>{item.label.replace(' (pruebas)', '')}</strong>\n                    <span>{HINTS[moduleId(item.href)] ?? ''}</span>\n                    {item.label.includes('(pruebas)') && <em>En pruebas</em>}\n                  </a>\n                </li>\n              ))}\n            </ul>\n          </div>\n        ))}\n      </div>\n    </section>\n  );\n}\n";
const HISTORICAL_OVERVIEW_GRID = "type OverviewItem = Readonly<{\n  label: string;\n  value: string | number;\n  detail?: string;\n}>;\n\ntype OverviewGridProps = Readonly<{\n  ariaLabel: string;\n  items: readonly OverviewItem[];\n  variant?: 'standard' | 'control';\n}>;\n\nexport function OverviewGrid({\n  ariaLabel,\n  items,\n  variant = 'standard',\n}: OverviewGridProps) {\n  return (\n    <section\n      className={'foundation-grid foundation-grid-' + variant}\n      aria-label={ariaLabel}\n    >\n      {items.map((item) => (\n        <article key={item.label}>\n          <strong>{item.label}</strong>\n          <span className=\"overview-value\">{item.value}</span>\n          {item.detail && (\n            <small className=\"overview-detail\">{item.detail}</small>\n          )}\n        </article>\n      ))}\n    </section>\n  );\n}\n";

let output;
let server;
let origin;

function fixtureEntry() {
  return {
    name: 'condor-tenant-home-pending-fixture',
    resolveId(id) {
      if (id === 'virtual:condor-tenant-home-pending') return '\0condor-tenant-home-pending';
      if (id === 'virtual:condor-tenant-home-before') return '\0condor-tenant-home-before';
      if (id === 'virtual:condor-legacy-module') return '\0condor-legacy-module.tsx';
      if (id === 'virtual:condor-legacy-overview') return '\0condor-legacy-overview.tsx';
    },
    load(id) {
      if (id === '\0condor-legacy-module.tsx') return HISTORICAL_MODULE_DIRECTORY;
      if (id === '\0condor-legacy-overview.tsx') return HISTORICAL_OVERVIEW_GRID;
      if (!['\0condor-tenant-home-pending', '\0condor-tenant-home-before'].includes(id)) return;
      const before = id === '\0condor-tenant-home-before';
      return [
        "import React from 'react';",
        "import { createRoot } from 'react-dom/client';",
        "import { ModuleDirectory } from '" + (before ? 'virtual:condor-legacy-module' : '/frontend/admin/ModuleDirectory.tsx') + "';",
        "import { OverviewGrid } from '" + (before ? 'virtual:condor-legacy-overview' : '/frontend/admin/OverviewGrid.tsx') + "';",
        "import '/frontend/admin/admin-ui.css';",
        "import '/frontend/admin/admin.css';",
        'const groups = ' + JSON.stringify(GROUPS) + ';',
        "const root = document.querySelector('.workspace');",
        "createRoot(root).render(React.createElement(React.Fragment, null,",
        "  React.createElement(OverviewGrid, {ariaLabel:'Estado de la empresa',",
        "    items:[{label:'Tenant',value:'Activo'},",
        "      {label:'Permisos efectivos',value:40}]}),",
        "  React.createElement(ModuleDirectory, {entries:groups})));",
      ].join('\n');
    },
  };
}

function context(permissions = ['orders.view', 'inventory.view', 'users.view']) {
  return {
    tenant: { id: TENANT, name: 'Empresa ficticia', slug: 'fixture' },
    active_legal_entity: { id: COMPANY, name: 'Razón social ficticia' },
    active_branch: { id: BRANCH, name: 'Sede ficticia' },
    permissions,
  };
}

const orders = [
  { id: 'a', order_status: 'open', fulfillment_status: 'reserved' },
  { id: 'b', order_status: 'open', fulfillment_status: 'reserved' },
  { id: 'c', order_status: 'open', fulfillment_status: 'consumed' },
];
const alerts = [
  { id: 'alert-1', status: 'pending' },
  { id: 'alert-2', status: 'delivered' },
];
const payroll = [{ tenant_id: TENANT, company_id: COMPANY, state: 'EN_REVISION' }];

test.describe('Inicio tenant / Para hoy - datos exclusivamente sintéticos', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    test.setTimeout(120_000);
    output = await mkdtemp(join(tmpdir(), 'condor-home-pending-'));
    await build({
      configFile: false, mode: 'production', root: process.cwd(),
      plugins: [fixtureEntry(), react()],
      build: {
        outDir: output, emptyOutDir: true, cssCodeSplit: false,
        rollupOptions: {
          input: { after: 'virtual:condor-tenant-home-pending', before: 'virtual:condor-tenant-home-before' },
          output: { entryFileNames: '[name].js', chunkFileNames: 'chunks/[name]-[hash].js', assetFileNames: 'styles.css' },
        },
      },
    });
    server = createServer(async (req, res) => {
      const url = new URL(req.url, 'http://localhost');
      if (url.pathname === '/admin') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><body>'
          + '<div id="condor-admin-root" class="admin-shell" data-payroll-real-data-enabled="true">'
          + '<div class="workspace-heading"><label>Sede activa'
          + '<select aria-label="Sede activa"><option value="' + BRANCH + '">Sede ficticia</option>'
          + '</select></label></div><main class="workspace" data-module="inicio"></main></div>'
          + '<script type="module" src="' + (url.searchParams.get('view') === 'before' ? '/before.js' : '/after.js') + '"></script></body></html>');
        return;
      }
      const filename = resolve(output, '.' + url.pathname);
      if (!filename.startsWith(output + sep)) {
        res.writeHead(404); res.end(); return;
      }
      try {
        const bytes = await readFile(filename);
        const ext = extname(filename);
        res.writeHead(200, { 'Content-Type': ext === '.js'
          ? 'text/javascript; charset=utf-8' : ext === '.css'
            ? 'text/css; charset=utf-8' : 'application/octet-stream' });
        res.end(bytes);
      } catch {
        res.writeHead(404); res.end();
      }
    });
    await new Promise(done => server.listen(0, '127.0.0.1', done));
    origin = 'http://127.0.0.1:' + server.address().port;
  });

  test.afterAll(async () => {
    if (server) await new Promise((resolveClose, reject) =>
      server.close(error => error ? reject(error) : resolveClose()));
    if (output) await rm(output, { recursive: true, force: true });
  });

  async function mockApi(page, mode = 'pending') {
    const called = [];
    await page.route('**/api/v1/**', async route => {
      const url = new URL(route.request().url());
      called.push(url.pathname);
      if (url.pathname === '/api/v1/context') {
        expect(url.searchParams.get('branch')).toBe(BRANCH);
      }
      const denied = mode === 'denied';
      if (url.pathname === '/api/v1/context') {
        await route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify(context(denied ? [] : undefined)) });
        return;
      }
      if (mode === 'partial' && url.pathname.includes('/orders')) {
        await route.fulfill({ status: 503, body: 'Servicio temporalmente no disponible' });
        return;
      }
      let payload;
      if (url.pathname.endsWith('/orders')) {
        // La consulta de inicio usa filtros del backend + pagination.total.
        expect(url.searchParams.get('status')).toBe('open');
        expect(url.searchParams.get('fulfillment_status')).toBe('reserved');
        const filtered = mode === 'empty' ? [] : orders.filter(item =>
          item.order_status === 'open' && item.fulfillment_status === 'reserved');
        payload = { orders: filtered.slice(0, 1),
          pagination: { page: 1, per_page: 1, total: filtered.length,
            has_more: filtered.length > 1 } };
      }
      else if (url.pathname.endsWith('/inventory/stock-alerts')) payload = {
        source_id: 'synthetic', alerts: mode === 'empty' ? [] : alerts,
      };
      else if (url.pathname.endsWith('/payroll/real-records')) payload = {
        records: mode === 'empty' ? [] : payroll,
      };
      else throw new Error('API no autorizada en fixture: ' + url.pathname);
      await route.fulfill({
        status: 200, contentType: 'application/json', body: JSON.stringify(payload),
      });
    });
    return called;
  }

  test('antes histórico: React original con fixture sintético a 390/1440 px', async ({ page }, testInfo) => {
    await page.goto(origin + '/admin?view=before');
    await expect(page.getByRole('heading', { name: 'Módulos' })).toBeVisible();
    await expect(page.getByText('Permisos efectivos')).toBeVisible();
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await testInfo.attach('owner-support-before-home-' + width + '.png', {
        body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
      });
    }
  });

  test('pendientes reales del contrato, enlaces, sin cifras decorativas', async ({ page }, testInfo) => {
    const called = await mockApi(page);
    await page.goto(origin + '/admin');
    await expect(page.getByRole('heading', { name: 'Para hoy' })).toBeVisible();
    const entries = [
      ['Pedidos por despachar: 2', '/admin#orders'],
      ['Alertas de stock: 1', '/admin#stock-alerts'],
      ['Nómina para revisión: 1', '/admin#payroll-management'],
    ];
    for (const [name, href] of entries) {
      await expect(page.getByRole('link', { name })).toHaveAttribute('href', href);
    }
    expect(called.some(path => path.endsWith('/orders'))).toBe(true);
    expect(called.some(path => path.endsWith('/inventory/stock-alerts'))).toBe(true);
    expect(called.some(path => path.endsWith('/payroll/real-records'))).toBe(true);
    await expect(page.getByText('Permisos efectivos')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Módulos' })).toHaveCount(0);
    await expect(page.locator('.module-directory-group')).toHaveCount(1);

    // Capturas reales de este componente tras la intervención, no de la versión anterior.
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(page.getByRole('heading', { name: 'Para hoy' })).toBeVisible();
      await testInfo.attach('owner-support-after-home-' + width + '.png', {
        body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
      });
    }
  });

  test('sin pendientes ofrece siguiente paso útil sin afirmar pagos', async ({ page }) => {
    await mockApi(page, 'empty');
    await page.goto(origin + '/admin');
    await expect(page.getByText(/Todo al día/)).toBeVisible();
    await expect(page.locator('.module-directory-group li')).toHaveCount(0);
    await expect(page.getByText('Permisos efectivos')).toHaveCount(0);
  });

  test('sin permisos no llama APIs de sedes ni revela contadores', async ({ page }) => {
    const called = await mockApi(page, 'denied');
    await page.goto(origin + '/admin');
    await expect(page.getByText(/No tienes permiso/)).toBeVisible();
    expect(called).toEqual(['/api/v1/context']);
    await expect(page.locator('.module-directory-group li')).toHaveCount(0);
  });

  test('una fuente caída no genera el falso vacío Todo al día', async ({ page }) => {
    await mockApi(page, 'partial');
    await page.goto(origin + '/admin');
    await expect(page.getByText(/fuentes no están disponibles/)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Alertas de stock: 1' })).toBeVisible();
    await expect(page.getByText(/Todo al día/)).toHaveCount(0);
  });
});
