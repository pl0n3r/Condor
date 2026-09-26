import { expect, test } from '@playwright/test';
import { provisionCommerceFixture } from './support/commerce-fixture.mjs';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 6 — e-commerce público', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E ejecutándose y usuario propietario del tenant.'
  );

  test('configura canal y publica precio y disponibilidad efectiva', async ({
    page,
  }, testInfo) => {
    const suffix = Date.now() + '-' + testInfo.retry;
    const productName = 'Web E2E ' + suffix;
    const sku = 'WEB-E2E-' + suffix;
    const channelName = 'Tienda web ' + suffix;

    const fixture = await provisionCommerceFixture(page, {
      email,
      password,
      productName,
      productSlug: 'web-e2e-' + suffix,
      sku,
      sourceName: 'Bodega web ' + suffix,
      sourceSlug: 'bodega-web-' + suffix,
      listName: 'Lista web ' + suffix,
      listSlug: 'lista-web-' + suffix,
      channelName,
      channelSlug: 'tienda-web-' + suffix,
      stock: 5,
    });

    await expect(page.getByText('Publicable')).toBeVisible();
    await page.goto('/' + fixture.context.tenant.slug);

    const publicCatalog = page.locator('.storefront-catalog');
    await expect(
      publicCatalog.getByRole('heading', { name: channelName })
    ).toBeVisible();
    await expect(publicCatalog.getByText(productName)).toBeVisible();
    await expect(publicCatalog.getByText('COP 125.000')).toBeVisible();
    await expect(publicCatalog.getByText('Disponible')).toBeVisible();
  });
});
