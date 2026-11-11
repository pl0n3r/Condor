import { expect, test } from '@playwright/test';
import { build } from 'vite';
import react from '@vitejs/plugin-react';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { extname, join, resolve, sep } from 'node:path';

// Real AdminApp and dependencies bundled in Vite production mode.
// Every HTTP API response is synthetic and intercepted; never call Hostinger.
const TENANT = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const COMPANY = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const BRANCH = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const CONTEXT = {
  tenant: { id: TENANT, name: 'Empresa ficticia', slug: 'test-only' },
  legal_entities: [{ id: COMPANY, name: 'Razón social ficticia' }],
  active_legal_entity: { id: COMPANY, name: 'Razón social ficticia' },
  branches: [{
    id: BRANCH, name: 'Sede ficticia', slug: 'synthetic',
    is_default: true, legal_entity: { id: COMPANY, name: 'Razón social ficticia' },
  }],
  active_branch: {
    id: BRANCH, name: 'Sede ficticia', slug: 'synthetic',
    is_default: true, legal_entity: { id: COMPANY, name: 'Razón social ficticia' },
  },
  permissions: ['inventory.view', 'catalog.view', 'users.view'],
  version: '0.1.199',
};
let output;
let server;
let origin;

function fixtureEntry() {
  return {
    name: 'condor-admin-shell-production-fixture',
    resolveId(id) { if (id === 'virtual:condor-admin-shell-fixture') return '\0condor-shell-fixture'; },
    load(id) {
      if (id !== '\0condor-shell-fixture') return;
      return [
        "import React from 'react';",
        "import { createRoot } from 'react-dom/client';",
        "import { AdminApp } from '/frontend/admin/AdminApp.tsx';",
        "const root = document.getElementById('condor-admin-root');",
        "createRoot(root).render(React.createElement(AdminApp, { version: '0.1.199',",
        "  logoutToken: '', accessToken: '', section: 'home' }));",
      ].join('\n');
    },
  };
}

test.describe('AdminApp: shell real compilada en producción con datos ficticios', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    test.setTimeout(120_000);
    output = await mkdtemp(join(tmpdir(), 'condor-admin-shell-'));
    await build({
      configFile: false, mode: 'production', root: process.cwd(),
      plugins: [fixtureEntry(), react()],
      build: {
        outDir: output, emptyOutDir: true,
        rollupOptions: {
          input: 'virtual:condor-admin-shell-fixture',
          output: { entryFileNames: 'shell.js', chunkFileNames: 'chunks/[name]-[hash].js' },
        },
      },
    });
    server = createServer(async (req, res) => {
      const url = new URL(req.url, 'http://localhost');
      if (url.pathname === '/admin') {
        const enabled = url.searchParams.get('hr') === 'on' ? 'true' : 'false';
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<!doctype html><html lang="es"><body>'
          + '<div id="condor-admin-root" data-hr-real-data-enabled="' + enabled + '"></div>'
          + '<script type="module" src="/shell.js"></script></body></html>');
        return;
      }
      const filename = resolve(output, '.' + url.pathname);
      if (!filename.startsWith(output + sep)) {
        res.writeHead(404); res.end(); return;
      }
      try {
        const bytes = await readFile(filename);
        res.writeHead(200, { 'Content-Type': extname(filename) === '.js'
          ? 'text/javascript; charset=utf-8' : 'application/octet-stream' });
        res.end(bytes);
      } catch {
        res.writeHead(404); res.end();
      }
    });
    await new Promise(resolveListening => server.listen(0, '127.0.0.1', resolveListening));
    origin = 'http://127.0.0.1:' + server.address().port;
  });

  test.afterAll(async () => {
    if (server) await new Promise((done, reject) => server.close(err => err ? reject(err) : done()));
    if (output) await rm(output, { recursive: true, force: true });
  });

  async function syntheticApi(page, paths) {
    await page.route('**/api/v1/**', async route => {
      const url = new URL(route.request().url());
      paths.push(url.pathname);
      const payload = url.pathname === '/api/v1/context'
        ? CONTEXT
        : url.pathname.endsWith('/hr/records')
          ? { records: [] }
          : { products: [], services: [], items: [], records: [], orders: [], inventory: [] };
      await route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify(payload) });
    });
  }

  test('Inicio no monta paneles; navegación/refresh/back preservan módulo activo', async ({ page }) => {
    const calls = [];
    await syntheticApi(page, calls);
    await page.goto(origin + '/admin');
    await expect(page.locator('.module-directory')).toBeVisible();
    expect(calls.filter(path => path.startsWith('/api/v1/branches/'))).toEqual([]);
    await page.evaluate(() => { window.location.hash = '#catalog'; });
    await expect(page.locator('#catalog')).toBeVisible();
    await expect(page.locator('#orders')).toHaveCount(0);
    await expect(page.locator('#inventory')).toHaveCount(0);
    await page.waitForLoadState('networkidle');
    const catalogUrls = calls.filter(path => path.startsWith('/api/v1/branches/'));
    expect(catalogUrls.some(path => path.endsWith('/catalog/products'))).toBe(true);
    expect(catalogUrls.some(path => /\/orders|\/inventory|\/hr\//.test(path))).toBe(false);

    const beforeInventory = calls.length;
    await page.evaluate(() => { window.location.hash = '#inventory'; });
    await expect(page.locator('#inventory')).toBeVisible();
    await page.waitForLoadState('networkidle');
    const inventoryUrls = calls.slice(beforeInventory);
    expect(inventoryUrls.some(path => path.endsWith('/inventory'))).toBe(true);
    expect(inventoryUrls.some(path => path.endsWith('/catalog/products'))).toBe(false);
    await expect(page.locator('#catalog')).toHaveCount(0);
    await page.goBack();
    await expect(page.locator('#catalog')).toBeVisible();
    await page.reload();
    await expect(page.locator('#catalog')).toBeVisible();
    await page.evaluate(() => { window.location.hash = ''; });
    await expect(page.locator('.module-directory')).toBeVisible();
    await expect(page.locator('#catalog')).toHaveCount(0);
  });

  test('HR OFF sin menú ni peticiones; ON monta RRHH real solo en el bundle productivo', async ({ page }) => {
    const calls = [];
    await syntheticApi(page, calls);
    await page.goto(origin + '/admin?hr=off#human-resources-management');
    await expect(page.locator('.workspace')).toBeVisible();
    await expect(page.locator('a[href="/admin#human-resources-management"]')).toHaveCount(0);
    await expect(page.locator('#human-resources-management')).toHaveCount(0);
    expect(calls.filter(path => path.includes('/hr/'))).toEqual([]);

    calls.length = 0;
    await page.goto(origin + '/admin?hr=on#human-resources-management');
    await expect(page.locator('a[href="/admin#human-resources-management"]')).toHaveCount(1);
    await expect(page.locator('#human-resources-management')).toBeVisible();
    await expect(page.locator('#human-resources-management')).toContainText('Talento humano');
    await expect.poll(() => calls.filter(path => path.endsWith('/hr/records')).length)
      .toBeGreaterThan(0);
    expect(calls.every(path => !path.includes('/hr/') || path.endsWith('/hr/records'))).toBe(true);
    expect(page.url()).toContain('#human-resources-management');
  });
});
