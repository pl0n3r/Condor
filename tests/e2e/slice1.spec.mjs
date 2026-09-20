import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 1 — fundación y onboarding', () => {
  test.skip(!baseURL || !email || !password, 'Requiere aplicación E2E ejecutándose.');

  test('muestra versión, autentica y expone el contexto tenant', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('V 0.1.0')).toBeVisible();

    await page.goto('/admin/login');
    await expect(page.getByText('V 0.1.0')).toBeVisible();

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
});
