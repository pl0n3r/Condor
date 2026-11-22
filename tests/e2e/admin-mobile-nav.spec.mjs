import { test, expect } from '@playwright/test';
import { build } from 'vite';
import react from '@vitejs/plugin-react';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { extname, join, resolve, sep } from 'node:path';

// Shell real compilado con datos inventados, sin credenciales ni peticiones productivas.
let output;
let server;
let origin;
let stylesheet;
const navigation = [
  { href: '/admin', label: 'Inicio', current: true, icon: 'home' },
  { group: 'Ventas', icon: 'sales', items: [
    { href: '/admin#orders', label: 'Pedidos' },
    { href: '/admin#commerce', label: 'Comercio' },
  ] },
  { group: 'Catálogo e inventario', icon: 'catalog', items: [
    { href: '/admin#catalog', label: 'Catálogo' },
    { href: '/admin#inventory', label: 'Inventario' },
  ] },
  { group: 'Administración', icon: 'admin', items: [
    { href: '/admin#roles', label: 'Roles' },
  ] },
];
const platformNavigation = [
  { group: 'Resumen', icon: 'home', items: [{ href: '/adminpl0n3r', label: 'Inicio', current: false }] },
  { group: 'Comercial', icon: 'sales', items: [{ href: '/adminpl0n3r/suscripciones', label: 'Suscripciones', current: true }] },
  { group: 'Plataforma', icon: 'admin', items: [{ href: '/adminpl0n3r/staff', label: 'Staff', current: false }] },
];

test.describe('Condor #1960: navegación compacta y accesible', () => {
  test.describe.configure({ mode: 'serial' });
  test.beforeAll(async () => {
    test.setTimeout(120_000);
    output = await mkdtemp(join(tmpdir(), 'condor-admin-mobile-nav-'));
    const built = await build({
      configFile: false, mode: 'production', root: process.cwd(),
      plugins: [{
        name: 'condor-nav-fixture',
        resolveId(id) { if (id === 'virtual:condor-nav') return '\0condor-nav'; },
        load(id) {
          if (id !== '\\0condor-nav') return;
          return [
            "import React from 'react';",
            "import { createRoot } from 'react-dom/client';",
            "import { AdminShell } from '/frontend/admin/AdminShell.tsx';",
            "import '/frontend/admin/admin.css';",
            "import '/frontend/admin/admin-ui.css';",
            `const tenant=${JSON.stringify(navigation)};`,
            `const platform=${JSON.stringify(platformNavigation)};`,
            "const owner=window.location.pathname.startsWith('/adminpl0n3r');",
            "const root=document.getElementById('condor-admin-root');",
            "createRoot(root).render(React.createElement(AdminShell,{",
            "  version:'0.1.202',logoutToken:'',ariaLabel:'Administrador',",
            "  navLabel:owner?'Navegación de plataforma':'Navegación principal',",
            "  variant:owner?'platform':'tenant',navItems:owner?platform:tenant,",
            "  children:React.createElement('section',{id:'fixture-content','data-testid':'module-content'},'Contenido del módulo'),",
            "}));",
          ].join('\n');
        },
      }, react()],
      build: {
        outDir: output, emptyOutDir: true,
        rollupOptions: {
          input: 'virtual:condor-nav',
          output: { entryFileNames: 'shell.js', chunkFileNames: 'chunks/[name]-[hash].js' },
        },
      },
    });
    stylesheet = built.output.find(item => item.type === 'asset' && item.fileName.endsWith('.css'))?.fileName;
    if (!stylesheet) throw new Error('No se generó el CSS de la shell');
    server = createServer(async (req, res) => {
      const url = new URL(req.url, 'http://localhost');
      if (url.pathname === '/admin' || url.pathname.startsWith('/adminpl0n3r')) {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<!doctype html><html lang="es"><head><link rel="stylesheet" href="/' + stylesheet + '"></head><body>'
          + '<div id="condor-admin-root"></div>'
          + '<script type="module" src="/shell.js"></script></body></html>');
        return;
      }
      const filename = resolve(output, '.' + url.pathname);
      if (!filename.startsWith(output + sep)) { res.writeHead(404); res.end(); return; }
      try {
        const bytes = await readFile(filename);
        res.writeHead(200, { 'Content-Type': extname(filename) === '.js' ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8' });
        res.end(bytes);
      } catch { res.writeHead(404); res.end(); }
    });
    await new Promise(done => server.listen(0, '127.0.0.1', done));
    origin = 'http://127.0.0.1:' + server.address().port;
  });
  test.afterAll(async () => {
    if (server) await new Promise(done => server.close(done));
    if (output) await rm(output, { recursive: true, force: true });
  });

  // La referencia anterior es una reconstrucción del comportamiento observado en
  // #1960 sobre el MISMO shell de prueba: sidebar apilado y todos los grupos
  // expandidos. No se presenta como una captura histórica de producción.
  async function captureComparison(page, info, width) {
    const after = info.outputPath('owner-support-after-condor1960-' + width + '.png');
    const before = info.outputPath('owner-support-before-condor1960-' + width + '.png');
    await page.screenshot({ path: after, fullPage: true });
    await page.addStyleTag({ content: `
      .admin-shell .mobile-module-header { display: none !important; }
      .admin-shell .nav-group .t-acc-panel { display: block !important; max-height: none !important; }
      @media (max-width: 860px) {
        .admin-shell > .sidebar { display: flex !important; flex-direction: column !important; }
        .admin-shell .sidebar nav { display: flex !important; flex-direction: column !important; max-height: none !important; overflow: visible !important; }
        .admin-shell .sidebar form { display: block !important; }
      }
    ` });
    await page.evaluate(() => {
      const sidebar = document.querySelector('.admin-shell > .sidebar');
      if (sidebar) sidebar.dataset.mobileOpen = 'true';
      document.querySelectorAll('.sidebar .nav-group').forEach(group => {
        group.dataset.open = 'true';
        group.querySelector('.nav-group-head')?.setAttribute('aria-expanded', 'true');
        group.querySelector('.t-acc-panel')?.removeAttribute('inert');
      });
    });
    await page.screenshot({ path: before, fullPage: true });
    await info.attach('antes-' + width + '-reconstruccion', { path: before, contentType: 'image/png' });
    await info.attach('despues-' + width, { path: after, contentType: 'image/png' });
    await page.reload();
    await expect(page.getByTestId('module-content')).toBeVisible();
  }

  test('390 px: módulo en primer viewport, drawer modal, Escape y retorno de foco', async ({ page }, info) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(origin + '/admin#orders');
    const header = page.locator('.mobile-module-header');
    const toggle = header.getByRole('button', { name: 'Menú' });
    await expect(header).toBeVisible();
    await expect(header).toContainText('Pedidos');
    await expect(page.locator('.sidebar')).toBeHidden();
    const content = page.getByTestId('module-content');
    await expect(content).toBeVisible();
    expect((await content.boundingBox()).y).toBeLessThan(844);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
    await captureComparison(page, info, 390);
    await toggle.click();
    const drawer = page.getByRole('dialog', { name: 'Menú de navegación' });
    await expect(drawer).toBeVisible();
    await expect(drawer.getByRole('button', { name: 'Cerrar navegación' })).toBeFocused();
    await expect(drawer.locator('a[href="/admin#orders"]')).toHaveAttribute('aria-current', 'page');
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(toggle).toBeFocused();
    await toggle.press('Enter');
    await expect(drawer).toBeVisible();
    await drawer.getByRole('button', { name: 'Catálogo e inventario' }).click();
    await expect(drawer.getByRole('button', { name: 'Ventas' })).toHaveAttribute('aria-expanded', 'false');
    await expect(drawer.getByRole('button', { name: 'Catálogo e inventario' })).toHaveAttribute('aria-expanded', 'true');
    await drawer.getByRole('button', { name: 'Cerrar navegación' }).click();
    await expect(toggle).toBeFocused();
  });

  test('1440 px: solo grupo activo abierto y página actual marcada', async ({ page }, info) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(origin + '/admin#orders');
    await expect(page.locator('.sidebar')).toBeVisible();
    await expect(page.locator('.mobile-module-header')).toBeHidden();
    const nav = page.getByRole('navigation', { name: 'Navegación principal' });
    await expect(nav.getByRole('button', { name: 'Ventas' })).toHaveAttribute('aria-expanded', 'true');
    await expect(nav.getByRole('button', { name: 'Catálogo e inventario' })).toHaveAttribute('aria-expanded', 'false');
    await expect(nav.locator('[aria-expanded="true"]')).toHaveCount(1);
    await expect(nav.locator('a[href="/admin#orders"]')).toHaveAttribute('aria-current', 'page');
    await captureComparison(page, info, 1440);
    await nav.getByRole('button', { name: 'Catálogo e inventario' }).click();
    await expect(nav.getByRole('button', { name: 'Ventas' })).toHaveAttribute('aria-expanded', 'false');
    await page.reload();
    await expect(nav.getByRole('button', { name: 'Ventas' })).toHaveAttribute('aria-expanded', 'true');
  });

  test('/adminpl0n3r: también oculta sidebar móvil y conserva selección accesible', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(origin + '/adminpl0n3r/suscripciones');
    await expect(page.locator('.mobile-module-header')).toContainText('Suscripciones');
    await expect(page.locator('.sidebar')).toBeHidden();
    await page.getByRole('button', { name: 'Menú' }).click();
    const drawer = page.getByRole('dialog', { name: 'Menú de navegación' });
    await expect(drawer.locator('a[href="/adminpl0n3r/suscripciones"]')).toHaveAttribute('aria-current', 'page');
    await expect(drawer.getByRole('button', { name: 'Comercial' })).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Menú' })).toBeFocused();
  });
});
