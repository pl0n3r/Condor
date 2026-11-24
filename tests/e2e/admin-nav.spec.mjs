import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const ORDER = [
  'Escritorio', 'Ventas', 'Catálogo', 'Inventario', 'Operaciones',
  'Personal', 'Finanzas e informes', 'Sitio web', 'Empresa',
];
const REQUIRED_LINKS = [
  '/admin#commerce', '/admin#orders', '/admin#sales-reports',
  '/admin#reports-downloads', '/admin#catalog', '/admin#inventory',
  '/admin#stock-alerts', '/admin#stock-transfers', '/admin#purchasing',
  '/admin/sitio', '/admin#seo-settings', '/admin#workflows', '/admin#roles',
];

test.describe('Administrador — menú agrupado y módulos aislados', () => {
  test.skip(!baseURL || !email || !password,
    'Requiere instancia E2E y credenciales de tenant.');

  test('raíces aprobadas, enlaces únicos y rutas reales, a 390 y 1440', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      const nav = page.getByRole('navigation', { name: width === 390
        ? 'Navegación principal móvil' : 'Navegación principal' });
      if (width === 390) {
        // El shell móvil tiene drawer nativo; el test de #1960 cubre foco/Escape.
        await page.locator('.mobile-menu-trigger').first().click();
      }
      const home = nav.getByRole('link', { name: 'Escritorio', exact: true });
      await expect(home).toBeVisible();
      await expect(home).toHaveAttribute('aria-current', 'page');
      const labels = await nav.locator(':scope > a, :scope > .nav-group > button')
        .allTextContents();
      const normalized = labels.map(label => label.trim());
      expect(new Set(normalized).size).toBe(normalized.length);
      expect(normalized.every(label => ORDER.includes(label))).toBe(true);
      expect(normalized).toEqual([...normalized].sort((a, b) =>
        ORDER.indexOf(a) - ORDER.indexOf(b)));
      const hrefs = await nav.locator('a[href]').evaluateAll(links =>
        links.map(link => link.getAttribute('href')));
      expect(new Set(hrefs).size).toBe(hrefs.length);
      for (const link of REQUIRED_LINKS) expect(hrefs).toContain(link);
      await expect(nav.getByRole('link', { name: 'Catálogo', exact: true })).toBeVisible();
      if (width === 390) {
        await page.keyboard.press('Escape');
      }
    }
    const desktopNav = page.getByRole('navigation', { name: 'Navegación principal' });
    await desktopNav.getByRole('link', { name: 'Catálogo', exact: true }).click();
    await expect(page).toHaveURL(/\/admin#catalog$/);
    await expect(desktopNav.getByRole('link', { name: 'Catálogo', exact: true }))
      .toHaveAttribute('aria-current', 'page');
    await expect(desktopNav.getByRole('link', { name: 'Escritorio', exact: true }))
      .not.toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.workspace > section#catalog')).toBeVisible();
    await expect(page.locator('.workspace > section#orders')).toHaveCount(0);
    await expect(page.locator('.workspace > section.module-directory')).toBeHidden();
    await desktopNav.getByRole('link', { name: 'Escritorio', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
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
      const beforePath = testInfo.outputPath(`employees-nav-before-${width}px.png`);
      await page.screenshot({ path: beforePath, fullPage: true });
      await testInfo.attach(`empleados-antes-sintetico-${width}px`, {
        path: beforePath, contentType: 'image/png',
      });
      await page.evaluate(() => {
        document.querySelector('[data-synthetic-before="true"]')?.remove();
        const root = document.querySelector('#condor-admin-root');
        if (root) root.setAttribute('data-enabled-modules',
          (root.getAttribute('data-enabled-modules') || '').split(',')
            .filter((key) => key !== 'employees-management').join(','));
      });
      await expect(nav.locator('a[href="/admin#employees-management"]')).toHaveCount(0);
      const afterPath = testInfo.outputPath(`employees-nav-after-${width}px.png`);
      await page.screenshot({ path: afterPath, fullPage: true });
      await testInfo.attach(`empleados-despues-${width}px`, {
        path: afterPath, contentType: 'image/png',
      });
    }
  });
});
