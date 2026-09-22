import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 3 — catálogo de productos y variantes', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E ejecutándose y usuario propietario del tenant.'
  );

  test('crea producto y variante desde el Admin y los conserva', async ({
    page,
  }, testInfo) => {
    const suffix = Date.now() + '-' + testInfo.retry;
    const productName = 'Producto E2E ' + suffix;
    const slug = 'producto-e2e-' + suffix;
    const sku = 'E2E-' + suffix;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(
      page.getByRole('heading', { name: 'Catálogo' })
    ).toBeVisible();

    await page.getByRole('button', { name: 'Nuevo producto' }).click();
    await page.getByLabel('Nombre', { exact: true }).fill(productName);
    await page.getByLabel('Slug').fill(slug);
    await page.getByLabel('Descripción').fill(
      'Producto creado por la regresión E2E del Slice 3.'
    );
    await page.getByRole('button', { name: 'Crear producto' }).click();

    await expect(page.getByText('Producto creado.')).toBeVisible();

    let card = page.locator('.catalog-card').filter({
      has: page.getByRole('heading', { name: productName }),
    });
    await expect(card).toBeVisible();

    await card.getByRole('button', { name: 'Añadir variante' }).click();
    await card.getByLabel('SKU').fill(sku);
    await card.getByLabel('Nombre de variante').fill('Variante E2E');
    await card.getByRole('button', { name: 'Crear variante' }).click();

    await expect(page.getByText('Variante creada.')).toBeVisible();
    const variantList = card.locator('.catalog-variant-list');
    await expect(variantList.getByText(sku)).toBeVisible();
    await expect(variantList.getByText('Variante E2E')).toBeVisible();

    await page.reload();

    card = page.locator('.catalog-card').filter({
      has: page.getByRole('heading', { name: productName }),
    });
    await expect(card).toBeVisible();
    const reloadedVariantList = card.locator('.catalog-variant-list');
    await expect(reloadedVariantList.getByText(sku)).toBeVisible();
    await expect(reloadedVariantList.getByText('Variante E2E')).toBeVisible();
  });
});
