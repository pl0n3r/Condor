import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Propietario — soporte temporal por empresa', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere entorno E2E sintético con propietario autenticado.',
  );

  test('desde Empresas abre Talento humano, conserva el actor y vuelve a la consola', async ({ page }) => {
    const unique = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 7);
    const tenantName = '000 Soporte E2E ' + unique;
    const tenantSlug = 'support-e2e-' + unique;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);

    const csrf = await page.locator('#condor-platform-root').getAttribute('data-tenant-token');
    expect(csrf).toBeTruthy();
    const created = await page.request.post('/adminpl0n3r/api/tenants', {
      headers: { 'X-CSRF-Token': csrf },
      data: {
        name: tenantName,
        slug: tenantSlug,
        legal_name: tenantName + ' SAS',
        branch_name: 'Sede soporte',
        owner_email: 'owner-' + unique + '@example.test',
        owner_name: 'Owner sintético',
      },
    });
    expect(created.status()).toBe(201);

    const nav = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await nav.getByRole('button', { name: 'Módulos de empresa', exact: true }).click();
    await nav.getByRole('link', { name: 'Talento humano', exact: true }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/empresas\?modulo=human-resources-management$/);

    const table = page.getByRole('table', { name: 'Empresas de esta página' });
    const row = table.getByRole('row').filter({ hasText: tenantName });
    for (let pageNumber = 0; pageNumber < 100 && !(await row.isVisible()); pageNumber++) {
      const pagination = page.getByRole('navigation', { name: 'Paginación de empresas' });
      const next = pagination.getByRole('button', { name: 'Siguiente' });
      if (await next.isDisabled()) break;
      const before = await pagination.locator('span').textContent();
      await next.click();
      await expect(pagination.locator('span')).not.toHaveText(before);
    }
    await expect(row).toBeVisible();
    // The table row is not an interactive target: open its accessible first-cell button.
    await row.getByRole('button').first().click();

    const dialog = page.getByRole('dialog', { name: tenantName });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Abrir panel de la empresa' }).click();
    await expect(page).toHaveURL(/\/admin#human-resources-management$/);
    await expect(page.getByRole('status').filter({ hasText: 'Soporte de propietario' })).toContainText(tenantName);
    const adminNav = page.getByRole('navigation', { name: 'Navegación principal' });
    await expect(adminNav.getByRole('button', { name: 'Personas', exact: true })).toBeVisible();
    await expect(adminNav.getByRole('button', { name: 'Administración', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Volver a la consola' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
  });
});
