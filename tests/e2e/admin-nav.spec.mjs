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
  '/admin#employees-management', '/admin#workflows', '/admin#roles',
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
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
    await expect(page.locator('.module-directory-group')).toHaveCount(GROUPS.length);
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
});
