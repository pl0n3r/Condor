import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Propietario — navegación aprobada #1972', () => {
  test.skip(!baseURL || !email || !password,
    'Requiere entorno E2E sintético con propietario autenticado.');

  test('cuatro raíces globales, vínculos únicos y capturas a 390/1440', async ({ page }, info) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);

    for (const size of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
      await page.setViewportSize(size);
      if (size.width === 390) await page.getByRole('button', { name: 'Mostrar menú' }).click();

      const nav = page.getByRole('navigation', { name: 'Navegación de plataforma' });
      await expect(nav).toBeVisible();
      await expect(nav.getByRole('link', { name: 'Escritorio global' }))
        .toHaveAttribute('aria-current', 'page');
      await expect(nav.getByRole('link', { name: 'Empresas', exact: true })).toBeVisible();
      await expect(nav.getByRole('button', { name: 'Negocio SaaS' })).toBeVisible();
      await expect(nav.getByRole('button', { name: 'Administración de plataforma' })).toBeVisible();
      await expect(nav.getByRole('button', { name: 'Módulos de empresa' })).toHaveCount(0);
      await expect(nav.locator('button.nav-group-head')).toHaveCount(2);

      const saas = nav.getByRole('button', { name: 'Negocio SaaS' });
      const platform = nav.getByRole('button', { name: 'Administración de plataforma' });
      await saas.click();
      await expect(saas).toHaveAttribute('aria-expanded', 'true');
      await platform.click();
      await expect(saas).toHaveAttribute('aria-expanded', 'false');
      await expect(platform).toHaveAttribute('aria-expanded', 'true');
      const hrefs = await nav.locator('a[href]').evaluateAll(
        (nodes) => nodes.map((node) => node.getAttribute('href')));
      expect(new Set(hrefs).size).toBe(hrefs.length);
      await page.screenshot({
        path: info.outputPath('global-owner-after-' + size.width + '.png'), fullPage: true,
      });
      if (size.width === 390) await page.getByRole('button', { name: 'Ocultar menú' }).click();
    }

    await page.getByRole('navigation', { name: 'Navegación de plataforma' })
      .getByRole('link', { name: 'Empresas', exact: true }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/empresas$/);
    await expect(page.getByRole('navigation', { name: 'Navegación de plataforma' })
      .getByRole('link', { name: 'Empresas', exact: true }))
      .toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('navigation', { name: 'Navegación empresarial del propietario' }))
      .toHaveCount(0);
  });
});
