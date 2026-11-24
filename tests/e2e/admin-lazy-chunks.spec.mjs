import { expect, test } from '@playwright/test';
import { build } from 'vite';
import { createServer } from 'node:http';
import { mkdtemp, readFile, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { extname, join, resolve, sep } from 'node:path';

// #1994: real production build + synthetic admin context; no production calls.
const TENANT = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const COMPANY = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const BRANCH = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const BRANCH_DATA = {
  id: BRANCH, name: 'Sede ficticia', slug: 'synthetic',
  is_default: true, legal_entity: { id: COMPANY, name: 'Empresa ficticia' },
};
const CONTEXT = {
  tenant: { id: TENANT, name: 'Tenant ficticio', slug: 'test-only' },
  legal_entities: [{ id: COMPANY, name: 'Empresa ficticia' }],
  active_legal_entity: { id: COMPANY, name: 'Empresa ficticia' },
  branches: [BRANCH_DATA],
  active_branch: BRANCH_DATA,
  permissions: ['inventory.view', 'catalog.view', 'users.view'],
  version: '0.1.204',
};
const MODULES = [
  ['customer-service', 'CustomerServiceManagement'],
  ['assets-management', 'AssetsManagement'],
  ['operations-processes', 'OperationsProcessesPanel'],
];

let directory;
let server;
let origin;

test.describe('Admin: chunks lazy con base pública /build/ (#1994)', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    test.setTimeout(120_000);
    directory = await mkdtemp(join(tmpdir(), 'condor-lazy-chunks-'));
    // Import the repository's actual vite.config.ts: this is the regression guard.
    await build({
      configFile: resolve('vite.config.ts'),
      mode: 'production',
      build: { outDir: directory, emptyOutDir: true },
    });
    const chunks = await readdir(join(directory, 'chunks'));
    for (const [, name] of MODULES) {
      expect(chunks.some(file => file.startsWith(name + '-') && file.endsWith('.js'))).toBe(true);
    }
    server = createServer(async (request, response) => {
      const path = new URL(request.url, 'http://localhost').pathname;
      if (path === '/favicon.ico') { response.writeHead(204); response.end(); return; }
      if (path === '/admin') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end('<!doctype html><html lang="es"><head>'
          + '<link rel="stylesheet" href="/build/admin.css"></head><body>'
          + '<div id="condor-admin-root" data-version="0.1.204" '
          + 'data-customer-service-real-data-enabled="true" '
          + 'data-assets-real-data-enabled="true" '
          + 'data-operations-processes-real-data-enabled="true" '
          + 'data-enabled-modules="customer-service,assets-management,operations-processes"></div>'
          + '<script type="module" src="/build/admin.js"></script></body></html>');
        return;
      }
      if (!path.startsWith('/build/')) {
        response.writeHead(404);
        response.end();
        return;
      }
      const filename = resolve(directory, '.' + path.slice('/build'.length));
      if (!filename.startsWith(directory + sep)) {
        response.writeHead(404);
        response.end();
        return;
      }
      try {
        const bytes = await readFile(filename);
        const contentType = extname(filename) === '.js' ? 'text/javascript; charset=utf-8'
          : extname(filename) === '.css' ? 'text/css; charset=utf-8'
            : 'application/octet-stream';
        response.writeHead(200, { 'Content-Type': contentType });
        response.end(bytes);
      } catch {
        response.writeHead(404);
        response.end();
      }
    });
    await new Promise(done => server.listen(0, '127.0.0.1', done));
    origin = 'http://127.0.0.1:' + server.address().port;
  });

  test.afterAll(async () => {
    if (server) await new Promise((done, fail) => server.close(error => error ? fail(error) : done()));
    if (directory) await rm(directory, { recursive: true, force: true });
  });

  for (const width of [390, 1440]) {
    for (const [section, chunkName] of MODULES) {
      test(`${section} se monta a ${width}px sin 404 ni errores de consola`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        const chunks = [];
        const errors = [];
        page.on('response', response => {
          const path = new URL(response.url()).pathname;
          if (path.includes('/chunks/') || path.startsWith('/build/')) {
            if (response.status() === 404) errors.push('404: ' + path);
            if (path.includes('/chunks/')) chunks.push({ path, status: response.status() });
          }
        });
        page.on('pageerror', error => errors.push('pageerror: ' + error.message));
        page.on('console', message => {
          if (message.type() === 'error') errors.push('console: ' + message.text());
        });
        await page.route('**/api/v1/**', async route => {
          const path = new URL(route.request().url()).pathname;
          const payload = path === '/api/v1/context'
            ? CONTEXT : { records: [], next_cursor: null, items: [], alerts: [], source_id: 'synthetic' };
          await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });
        });
        await page.goto(origin + '/admin#' + section);
        await expect(page.locator('.workspace > section#' + section)).toBeVisible();
        await expect.poll(() => chunks.some(chunk =>
          chunk.path.startsWith('/build/chunks/' + chunkName + '-') && chunk.status === 200,
        )).toBe(true);
        expect(chunks.every(chunk => chunk.path.startsWith('/build/chunks/') && chunk.status === 200))
          .toBe(true);
        expect(errors).toEqual([]);

        const configurator = await page.request.get(origin + '/build/configurator.js');
        expect(configurator.status()).toBe(200);
        const css = await page.request.get(origin + '/build/admin.css');
        expect(css.status()).toBe(200);
      });
    }
  }
});
