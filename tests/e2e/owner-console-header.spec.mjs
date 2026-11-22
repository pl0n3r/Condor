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

    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
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
          path: testInfo.outputPath('owner-console-after-' + key + '-' + width + '.png'),
          fullPage: true,
        });
      }
    }
  });
});
