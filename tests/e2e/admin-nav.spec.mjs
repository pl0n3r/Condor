import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const GROUPS = [
  'Ventas', 'Catálogo e inventario', 'Sitio web', 'Personas',
  'Finanzas y cumplimiento', 'Operación', 'Administración',
];
const REQUIRED_LINKS = [
  '/admin#commerce', '/admin#orders', '/admin#sales-reports',
  '/admin#reports-downloads', '/admin#catalog', '/admin#inventory',
  '/admin#stock-alerts', '/admin#stock-transfers', '/admin#production',
  '/admin#purchasing', '/admin/sitio', '/admin#seo-settings',
  '/admin#workflows', '/admin#roles',
];

test.describe('Administrador — menú agrupado y módulos aislados', () => {
  test.skip(!baseURL || !email || !password,
    'Requiere instancia E2E y credenciales de tenant.');

  test('grupos, enlaces, acordeón accesible y un módulo a la vez', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);

    const nav = page.getByRole('navigation', { name: 'Navegación principal' });
    await expect(nav.getByRole('link', { name: 'Inicio', exact: true }))
      .toHaveAttribute('aria-current', 'page');
    await expect(nav.locator('button.nav-group-head')).toHaveCount(7);
    for (const group of GROUPS) {
      await expect(nav.getByRole('button', { name: group })).toBeVisible();
    }

    const hrefs = await nav.locator('.nav-group a.nav-sub').evaluateAll(
      (links) => links.map((link) => link.getAttribute('href')),
    );
    expect(new Set(hrefs).size).toBe(hrefs.length);
    for (const href of REQUIRED_LINKS) expect(hrefs).toContain(href);
    expect(hrefs).not.toContain('/admin#employees-management');
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Para hoy' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Módulos', exact: true })).toHaveCount(0);
    await expect(page.getByText('Permisos efectivos')).toHaveCount(0);
    // El inicio deja de ser un directorio: las tareas solo aparecen con fuentes autorizadas.
    expect(await page.locator('.module-directory-group').count()).toBeLessThanOrEqual(1);
    await expect(page.locator('.workspace > section#catalog')).toHaveCount(0);
    await expect(page.locator('.workspace > section#orders')).toHaveCount(0);

    const catalog = nav.getByRole('button', { name: 'Catálogo e inventario' });
    const panel = catalog.locator('..').locator('.t-acc-panel');
    await expect(catalog).toHaveAttribute('aria-expanded', 'false');
    await expect(panel).toHaveAttribute('inert', '');
    await catalog.click();
    await expect(catalog).toHaveAttribute('aria-expanded', 'true');
    await expect(panel).not.toHaveAttribute('inert', '');

    await nav.locator('a.nav-sub[href="/admin#catalog"]').click();
    await expect(page).toHaveURL(/\/admin#catalog$/);
    await expect(nav.locator('a.nav-sub[href="/admin#catalog"]'))
      .toHaveAttribute('aria-current', 'page');
    await expect(nav.getByRole('link', { name: 'Inicio', exact: true }))
      .not.toHaveAttribute('aria-current', 'page');
    const breadcrumb = page.getByRole('navigation', { name: 'Ruta' });
    await expect(breadcrumb).toContainText('Catálogo e inventario');
    await expect(breadcrumb).toContainText('Catálogo');
    await expect(page.locator('.workspace > section#catalog')).toBeVisible();
    await expect(page.locator('.workspace > section#orders')).toHaveCount(0);
    await expect(page.locator('.workspace > section#inventory')).toHaveCount(0);
    await expect(page.locator('.workspace > section.module-directory')).toBeHidden();
    await expect(page.locator('.workspace > section[id]:visible')).toHaveCount(1);

    await nav.getByRole('link', { name: 'Inicio', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
    await expect(page.locator('.workspace > section[id]:visible')).toHaveCount(0);
    await expect(page.locator('.workspace > section#catalog')).toHaveCount(0);
  });

  test('empleados no disponible: evidencias sintéticas a 390 y 1440 px', async ({ page }, testInfo) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);

    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      const nav = page.getByRole('navigation', { name: 'Navegación principal' });
      await expect(nav.locator('a[href="/admin#employees-management"]')).toHaveCount(0);
      const enabled = await page.locator('#condor-admin-root').getAttribute('data-enabled-modules');
      expect((enabled || '').split(',')).not.toContain('employees-management');

      // Baseline ONLY for comparison: simulate the old link in the same shell.
      // No production view or entitlement is asserted by this screenshot.
      await page.evaluate(() => {
        const root = document.querySelector('#condor-admin-root');
        const navigation = document.querySelector('nav[aria-label="Navegación principal"]');
        const sample = navigation?.querySelector('.nav-group a.nav-sub');
        if (!root || !navigation || !sample) throw new Error('Navegación de prueba ausente');
        root.setAttribute('data-enabled-modules',
          `${root.getAttribute('data-enabled-modules') || ''},employees-management`);
        const oldLink = sample.cloneNode(true);
        oldLink.setAttribute('href', '/admin#employees-management');
        oldLink.setAttribute('data-synthetic-before', 'true');
        oldLink.textContent = 'Empleados y asistencia (referencia sintética)';
        navigation.appendChild(oldLink);
      });
      await testInfo.attach(`empleados-antes-sintetico-${width}px`, {
        body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
      });
      await page.evaluate(() => {
        document.querySelector('[data-synthetic-before="true"]')?.remove();
        const root = document.querySelector('#condor-admin-root');
        if (root) root.setAttribute('data-enabled-modules',
          (root.getAttribute('data-enabled-modules') || '').split(',')
            .filter((key) => key !== 'employees-management').join(','));
      });
      await expect(nav.locator('a[href="/admin#employees-management"]')).toHaveCount(0);
      await testInfo.attach(`empleados-despues-${width}px`, {
        body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
      });
    }
  });
});
