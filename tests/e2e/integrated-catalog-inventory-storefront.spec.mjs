import { expect, test } from '@playwright/test';
import { provisionCommerceFixture } from './support/commerce-fixture.mjs';

const config = {
  baseURL: process.env.PLAYWRIGHT_BASE_URL,
  ownerEmail: process.env.E2E_EMAIL,
  ownerPassword: process.env.E2E_PASSWORD,
  otherEmail: process.env.E2E_RECOVERY_EMAIL,
  otherPassword: process.env.E2E_RECOVERY_PASSWORD,
};

test('catálogo → inventario → storefront conserva SKU, precio y stock sin cruzar tenants', async ({ page, browser }, testInfo) => {
  test.setTimeout(120_000);

  // This is a required MariaDB-backed CI test, not an optional browser smoke.
  // Missing tenant B credentials must FAIL, never silently skip AC-03.
  for (const [name, value] of Object.entries(config)) {
    expect(value, `Falta ${name}: el harness CI debe provisionar ambos tenants sintéticos.`).toBeTruthy();
  }

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const productName = `Integrado E2E ${suffix}`;
  const productSlug = `integrado-e2e-${suffix}`;
  const sku = `INT-E2E-${suffix}`;
  const sourceName = `Origen integrado ${suffix}`;
  const listName = `Lista integrada ${suffix}`;
  const channelName = `Canal integrado ${suffix}`;

  const fixture = await provisionCommerceFixture(page, {
    email: config.ownerEmail,
    password: config.ownerPassword,
    productName,
    productSlug,
    sku,
    sourceName,
    sourceSlug: `origen-integrado-${suffix}`,
    listName,
    listSlug: `lista-integrada-${suffix}`,
    channelName,
    channelSlug: `canal-integrado-${suffix}`,
    stock: 5,
    price: '125000',
  });
  const { context, branchId, source, list, variant } = fixture;
  const publicPath = '/' + context.tenant.slug;
  const apiPath = '/api/v1/branches/' + branchId;

  async function snapshot(endpoint) {
    const response = await page.request.get(apiPath + endpoint);
    expect(response.status(), `GET ${endpoint} de A`).toBe(200);
    return response.json();
  }

  async function assertSourceQuantity(quantity) {
    const inventory = await snapshot('/inventory');
    expect(inventory.branch.id).toBe(branchId);
    expect(inventory.sources.filter((item) => item.id === source.id)).toHaveLength(1);
    const balances = inventory.balances.filter((item) => (
      item.source_id === source.id && item.variant.id === variant.id
    ));
    expect(balances).toHaveLength(1);
    expect(balances[0].quantity).toBe(quantity);
    return inventory;
  }

  async function assertPublicAvailability(availability) {
    await page.goto(publicPath);
    const catalog = page.locator('.storefront-catalog');
    await expect(catalog.getByRole('heading', { name: channelName })).toBeVisible();
    const product = catalog.locator('.storefront-product[data-product-slug="' + productSlug + '"]');
    await expect(product).toHaveCount(1);
    await expect(product.getByRole('heading', { name: productName })).toBeVisible();
    const publicVariant = product.locator('.storefront-variant[data-sku="' + sku + '"]');
    await expect(publicVariant).toHaveCount(1);
    await expect(publicVariant).toContainText('COP 125.000');
    await expect(publicVariant).toContainText(availability);
  }

  // Same actual entity across the admin catalog, stock source, price list and SSR.
  const catalog = await snapshot('/catalog/products');
  const products = catalog.products.filter((item) => item.slug === productSlug);
  expect(products).toHaveLength(1);
  expect(products[0].variants.filter((item) => item.id === variant.id && item.sku === sku)).toHaveLength(1);

  const pricing = await snapshot('/pricing');
  expect(pricing.price_lists.filter((item) => item.id === list.id)).toHaveLength(1);
  expect(pricing.variants.filter((item) => item.id === variant.id && item.sku === sku)).toHaveLength(1);

  await assertSourceQuantity(5);
  await assertPublicAvailability('Disponible');
  await page.reload();
  await assertPublicAvailability('Disponible'); // Public SSR is DB-backed, not a stale UI snapshot.

  // A second, separately authenticated tenant comes from the CI onboarding
  // fixture. Never emulate tenancy by replacing client-side JSON.
  const tenantBContext = await browser.newContext({ baseURL: config.baseURL });
  try {
    const tenantBPage = await tenantBContext.newPage();
    await tenantBPage.goto('/admin/login');
    await tenantBPage.getByLabel('Correo').fill(config.otherEmail);
    await tenantBPage.getByLabel('Contraseña').fill(config.otherPassword);
    await tenantBPage.getByRole('button', { name: 'Ingresar' }).click();
    await expect(tenantBPage).toHaveURL(/\/admin$/);
    const bContextResponse = await tenantBPage.request.get('/api/v1/context');
    expect(bContextResponse.status()).toBe(200);
    const bContext = await bContextResponse.json();
    expect(bContext.tenant.id).not.toBe(context.tenant.id);
    expect(bContext.active_branch.id).not.toBe(branchId);

    for (const endpoint of ['/catalog/products', '/inventory', '/pricing']) {
      const crossTenant = await tenantBPage.request.get(apiPath + endpoint);
      expect(crossTenant.status(), `B no debe leer ${endpoint} de A`).toBe(404);
    }

    const bApiPath = '/api/v1/branches/' + bContext.active_branch.id;
    const bCatalog = await tenantBPage.request.get(bApiPath + '/catalog/products');
    const bInventory = await tenantBPage.request.get(bApiPath + '/inventory');
    const bPricing = await tenantBPage.request.get(bApiPath + '/pricing');
    for (const response of [bCatalog, bInventory, bPricing]) {
      expect(response.status()).toBe(200);
    }
    expect((await bCatalog.json()).products.some((p) => p.slug === productSlug)).toBe(false);
    const bInventoryPayload = await bInventory.json();
    expect(bInventoryPayload.sources.some((item) => item.id === source.id)).toBe(false);
    expect(bInventoryPayload.variants.some((item) => item.id === variant.id)).toBe(false);
    expect((await bPricing.json()).variants.some((item) => item.sku === sku)).toBe(false);

    const csrf = await tenantBPage.locator('#condor-admin-root').getAttribute('data-access-token');
    expect(csrf).toBeTruthy();
    // Real authenticated, CSRF-valid server-side mutation checks, not 403 due
    // to missing CSRF. A cannot be edited through B's scoped branch either.
    const headers = { 'X-CSRF-Token': csrf, Accept: 'application/json' };
    const foreignEdit = await tenantBPage.request.patch(
      apiPath + '/inventory/sources/' + source.id,
      { headers, data: { name: sourceName + ' alterado', slug: source.slug } },
    );
    expect(foreignEdit.status()).toBe(404);

    const foreignSource = await tenantBPage.request.post(
      bApiPath + '/inventory/adjustments',
      {
        headers,
        data: {
          source_id: source.id,
          variant_id: variant.id,
          delta: 1,
          reason: 'Intento cruzado E2E denegado',
          idempotency_key: 'blocked-cross-tenant-' + suffix,
        },
      },
    );
    expect(foreignSource.status()).toBe(404);

    await tenantBPage.goto('/' + bContext.tenant.slug);
    await expect(
      tenantBPage.locator('.storefront-variant[data-sku="' + sku + '"]'),
    ).toHaveCount(0);
    await expect(tenantBPage.getByText(productName)).toHaveCount(0);
  } finally {
    await tenantBContext.close();
  }
  await assertSourceQuantity(5); // Neither negative attempt changed A.

  async function adjustStock(delta, reason) {
    await page.goto('/admin#inventory');
    const inventory = page.locator('#inventory');
    await expect(inventory.getByRole('heading', { name: 'Inventario', exact: true })).toBeVisible();
    await inventory.locator('#inventory-adjust-source').selectOption({ label: sourceName });
    await inventory.getByLabel('Variante').first().selectOption({ label: productName + ' · ' + sku });
    await inventory.getByLabel('Cantidad (+ entrada / - salida)').fill(String(delta));
    await inventory.getByLabel('Motivo').fill(reason);
    await inventory.getByRole('button', { name: 'Aplicar ajuste' }).click();
    await expect(inventory.getByText('Ajuste aplicado.')).toBeVisible();
  }

  await adjustStock(-5, 'Agotamiento integrado E2E');
  await assertSourceQuantity(0);
  await assertPublicAvailability('Agotado');
  await page.reload();
  await assertPublicAvailability('Agotado');

  // Out of stock must be rejected, not converted to an implicit stock source,
  // reservation, or oversell.
  const noOversell = await page.request.post(publicPath + '/checkout', {
    data: {
      idempotency_key: 'out-of-stock-' + suffix,
      items: [{ variant_id: variant.id, quantity: 1 }],
    },
  });
  expect([409, 422], 'Checkout sin stock debe fallar en el servidor.').toContain(noOversell.status());
  await assertSourceQuantity(0);

  await adjustStock(5, 'Reposición integrada E2E');
  await assertSourceQuantity(5);
  await assertPublicAvailability('Disponible');
  await page.reload();
  await assertPublicAvailability('Disponible');
  const reloadedCatalog = await snapshot('/catalog/products');
  expect(reloadedCatalog.products.filter((item) => item.slug === productSlug)).toHaveLength(1);
});
