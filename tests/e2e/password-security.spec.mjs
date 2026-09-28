import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const appVersion = process.env.APP_VERSION;

test.describe('Seguridad de contraseña', () => {
  test.skip(!baseURL, 'Requiere aplicación E2E ejecutándose.');

  test('navega login, solicitud y canje sin exponer el fragmento', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByRole('link', { name: '¿Olvidaste tu contraseña?' }).click();

    await expect(page).toHaveURL(/\/admin\/recuperar-contrasena$/);
    await expect(
      page.getByRole('heading', { name: 'Recuperar contraseña' }),
    ).toBeVisible();

    const token = 'a'.repeat(64);
    await page.goto('/admin/restablecer-contrasena#token=' + token);

    await expect(
      page.getByRole('heading', { name: 'Restablecer contraseña' }),
    ).toBeVisible();
    await expect(page.locator('#password-reset-token')).toHaveValue(token);
    await expect(
      page.getByRole('button', { name: 'Actualizar contraseña' }),
    ).toBeEnabled();
    await expect(page).toHaveURL(/\/admin\/restablecer-contrasena$/);
  });

  test('renderiza el cambio autenticado sin alterar el login existente', async ({ page }) => {
    test.skip(
      !email || !password || !appVersion,
      'Requiere usuario E2E autenticable y versión resuelta.',
    );

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);

    await page.goto('/admin/seguridad/contrasena');
    await expect(
      page.getByRole('heading', { name: 'Cambiar contraseña' }),
    ).toBeVisible();
    await expect(page.locator('.version-line')).toHaveText('V ' + appVersion);
    await expect(page.getByLabel('Contraseña actual')).toBeVisible();
    await expect(page.getByLabel('Nueva contraseña')).toBeVisible();
  });
});
