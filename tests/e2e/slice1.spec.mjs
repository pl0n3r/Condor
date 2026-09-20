import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 1 — fundación y onboarding', () => {
  test.skip(!baseURL || !email || !password, 'Requiere aplicación E2E ejecutándose.');

  test('muestra versión, autentica y expone el contexto tenant', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Tu empresa avanza cuando todo trabaja en conjunto.' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Condor App, inicio' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Menos sistemas aislados. Más claridad para operar.' })).toBeVisible();
    await expect(page.locator('.site-footer').getByText('V 0.1.0')).toBeVisible();

    await page.goto('/admin/login');
    await expect(page.locator('.version-line')).toHaveText('V 0.1.0');

    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('heading', { name: 'Empresa E2E' })).toBeVisible();

    const response = await page.request.get('/api/v1/context');
    expect(response.ok()).toBeTruthy();

    const payload = await response.json();
    expect(payload.tenant.name).toBe('Empresa E2E');
    expect(payload.tenant.slug).toBe('empresa-e2e');
    expect(payload.version).toBe('0.1.0');
  });

  test('el login conserva usabilidad en viewport móvil', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/login');

    await expect(page.getByRole('heading', { name: 'Ingresar a Condor' })).toBeVisible();
    await expect(page.getByLabel('Correo')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ingresar' })).toBeVisible();
  });

  test('el home conserva jerarquía y versión visible desde 360 px', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');

    await expect(page.getByRole('heading', { name: 'Tu empresa avanza cuando todo trabaja en conjunto.' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Entrar al administrador' })).toBeVisible();
    await expect(page.locator('.site-footer').getByText('V 0.1.0')).toBeVisible();
  });
});
