import { expect, test } from '@playwright/test';

const enabled = Boolean(process.env.PLAYWRIGHT_BASE_URL
  && process.env.E2E_PLATFORM_EMAIL && process.env.E2E_PASSWORD);
const pages = [
  { href: '/adminpl0n3r/empresas', title: 'Empresas', key: 'empresas' },
  { href: '/adminpl0n3r/catalogo?vista=planes', title: 'Planes y precios', key: 'planes' },
  { href: '/adminpl0n3r/integraciones', title: 'Integraciones', key: 'integraciones' },
  { href: '/adminpl0n3r/diagnosticos', title: 'Diagnósticos', key: 'diagnosticos' },
];

test.describe('Propietario #1961: módulo arriba, navegación clara y diagnóstico responsivo', () => {
  test.skip(!enabled, 'Requiere app y credenciales E2E sintéticas. Skip no acredita AC-01.');

  test('h1 y ruta accesibles a 390/1440; Diagnósticos sin scroll horizontal', async ({ page }, testInfo) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(process.env.E2E_PLATFORM_EMAIL);
    await page.getByLabel('Contraseña').fill(process.env.E2E_PASSWORD);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r/);

    // Base reproducible: assets publicados en el main exacto antes de este PR.
    // Se usan con la MISMA app y cuenta sintética; nunca son recreaciones HTML.
    const baseline = '2571536a6c32b8c3a1b328e71d978afd9eeca45b';
    const assetURL = (filename) => 'https://raw.githubusercontent.com/pl0n3r/Condor/'
      + baseline + '/public/build/' + filename;
    const [oldJs, oldCss] = await Promise.all([
      fetch(assetURL('admin.js')), fetch(assetURL('admin.css')),
    ]);
    expect(oldJs.ok(), 'bundle JS del main fijado').toBeTruthy();
    expect(oldCss.ok(), 'CSS del main fijado').toBeTruthy();
    const jsSource = Buffer.from(await oldJs.arrayBuffer());
    const cssSource = Buffer.from(await oldCss.arrayBuffer());

    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.route('**/build/admin.js*', (route) => route.fulfill({
        status: 200, contentType: 'text/javascript', body: jsSource,
      }));
      await page.route('**/build/admin.css*', (route) => route.fulfill({
        status: 200, contentType: 'text/css', body: cssSource,
      }));
      await page.goto('/adminpl0n3r/empresas');
      await expect(page.locator('.control-kicker')).toContainText('CONTROL CENTER');
      await page.screenshot({
        path: testInfo.outputPath('owner-support-before-console-' + width + '.png'),
        fullPage: true,
      });
      await page.unroute('**/build/admin.js*');
      await page.unroute('**/build/admin.css*');

      for (const { href, title, key } of pages) {
        await page.goto(href);
        const workspace = page.locator('.admin-shell-platform .workspace');
        await expect(workspace.getByRole('heading', { level: 1 })).toHaveText(title);
        await expect(workspace).not.toContainText('Administración global');
        await expect(workspace).not.toContainText('CONTROL CENTER');
        await expect(workspace).not.toContainText('ACTOR REAL');

        const breadcrumb = workspace.getByRole('navigation', { name: 'Ruta' });
        await expect(breadcrumb).toBeVisible();
        const positions = await page.evaluate(() => {
          const nav = document.querySelector('.admin-shell-platform .module-breadcrumb');
          const titleNode = document.querySelector('.admin-shell-platform .platform-heading h1');
          return { navBottom: nav?.getBoundingClientRect().bottom ?? Infinity,
            titleTop: titleNode?.getBoundingClientRect().top ?? -Infinity };
        });
        expect(positions.navBottom, href).toBeLessThanOrEqual(positions.titleTop);
        if (key === 'empresas') {
          await expect(breadcrumb.getByText('Empresas', { exact: true })).toHaveCount(1);
        }

        if (key === 'diagnosticos') {
          await expect(workspace.locator('.platform-diagnostics-content')).toContainText('p50');
          await expect(workspace.locator('.platform-diagnostics-content')).toContainText('p95');
          await expect(workspace.locator('.platform-diagnostics-content')).toContainText('Tasa de error 5xx');
          // El visor protegido conserva su enlace pero el token nunca se
          // convierte en texto visible, seleccionable o capturado.
          await expect(workspace).not.toContainText('platform-fatal-log.php');
          const overflow = await page.evaluate(() => ({
            documentWidth: document.documentElement.scrollWidth,
            windowWidth: window.innerWidth,
          }));
          expect(overflow.documentWidth, 'Diagnósticos sin desborde').toBeLessThanOrEqual(overflow.windowWidth);
          const code = workspace.locator('.platform-diagnostics-page code');
          if (await code.count() > 0) {
            await expect(code.first()).toHaveCSS('overflow-wrap', 'anywhere');
          }
        }

        // Captura real de la sesión sintética para el reporte visual del CI.
        await page.screenshot({
          path: testInfo.outputPath('owner-support-after-console-' + key + '-' + width + '.png'),
          fullPage: true,
        });
      }
    }
  });
  test('Diagnósticos conserva métricas y navegación si la API de contexto falla con 5xx', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(process.env.E2E_PLATFORM_EMAIL);
    await page.getByLabel('Contraseña').fill(process.env.E2E_PASSWORD);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r/);

    // La plantilla Twig del propietario no depende de la API del dashboard.
    // Falla la API de manera aislada para detectar regresiones de montaje.
    await page.route('**/adminpl0n3r/api/context*', async (route) => {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Contexto sintético temporalmente no disponible' }),
      });
    });
    await page.goto('/adminpl0n3r/diagnosticos');
    const workspace = page.locator('.admin-shell-platform .workspace');
    await expect(workspace.getByRole('heading', { level: 1 })).toHaveText('Diagnósticos');
    await expect(workspace.locator('.platform-diagnostics-content')).toContainText('p50');
    await expect(workspace.locator('.platform-diagnostics-content')).toContainText('p95');
    await expect(page.getByRole('navigation', { name: 'Navegación de plataforma' }).first()).toBeVisible();
    await expect(workspace.getByRole('alert')).toHaveCount(0);
  });

});
