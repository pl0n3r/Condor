import { expect, test } from '@playwright/test';

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
    const productSlug = 'web-e2e-' + suffix;
    const sku = 'WEB-E2E-' + suffix;
    const sourceName = 'Bodega web ' + suffix;
    const sourceSlug = 'bodega-web-' + suffix;
    const listName = 'Lista web ' + suffix;
    const listSlug = 'lista-web-' + suffix;
    const channelName = 'Tienda web ' + suffix;
    const channelSlug = 'tienda-web-' + suffix;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);

    const contextResponse = await page.request.get('/api/v1/context');
    expect(contextResponse.ok()).toBeTruthy();
    const context = await contextResponse.json();
    const branchId = context.active_branch.id;

    const catalog = page.locator('#catalog');
    await catalog.getByRole('button', { name: 'Nuevo producto' }).click();
    await catalog.getByLabel('Nombre', { exact: true }).fill(productName);
    await catalog.getByLabel('Slug').fill(productSlug);
    await catalog.getByRole('button', { name: 'Crear producto' }).click();
    await expect(catalog.getByText('Producto creado.')).toBeVisible();

    const productCard = catalog.locator('.catalog-card').filter({
      has: page.getByRole('heading', { name: productName }),
    });
    await productCard.getByRole('button', { name: 'Añadir variante' }).click();
    await productCard.getByLabel('SKU').fill(sku);
    await productCard.getByLabel('Nombre de variante').fill('Única');
    await productCard.getByRole('button', { name: 'Crear variante' }).click();
    await expect(catalog.getByText('Variante creada.')).toBeVisible();

    const inventory = page.locator('#inventory');
    await inventory.getByLabel('Nombre', { exact: true }).fill(sourceName);
    await inventory.getByLabel('Slug').fill(sourceSlug);
    await inventory.getByLabel('Tipo').selectOption('logical');
    await inventory.getByRole('button', { name: 'Crear fuente' }).click();
    await expect(inventory.getByText('Fuente creada.')).toBeVisible();

    await inventory.getByLabel('Fuente').selectOption({ label: sourceName });
    await inventory.getByLabel('Variante').first().selectOption({
      label: productName + ' · ' + sku,
    });
    await inventory.getByLabel('Cantidad (+ entrada / - salida)').fill('5');
    await inventory.getByLabel('Motivo').fill('Stock e-commerce E2E');
    await inventory.getByRole('button', { name: 'Aplicar ajuste' }).click();
    await expect(inventory.getByText('Ajuste aplicado.')).toBeVisible();

    const commerce = page.locator('#commerce');
    await commerce.getByLabel('Nombre de lista').fill(listName);
    await commerce.getByLabel('Slug de lista').fill(listSlug);
    await commerce.getByRole('button', { name: 'Crear lista' }).click();
    await expect(commerce.getByText('Lista creada.')).toBeVisible();

    const priceForm = commerce.locator('form.catalog-card').filter({
      has: page.getByRole('heading', { name: 'Precio por variante' }),
    });
    await priceForm.getByLabel('Lista').selectOption({ label: listName });
    await priceForm.getByLabel('Variante').selectOption({
      label: productName + ' · ' + sku,
    });
    await priceForm.getByLabel('Precio (COP)').fill('125000');
    await priceForm.getByRole('button', { name: 'Guardar precio' }).click();
    await expect(commerce.getByText('Precio guardado.')).toBeVisible();

    const inventoryResponse = await page.request.get(
      '/api/v1/branches/' + branchId + '/inventory'
    );
    expect(inventoryResponse.ok()).toBeTruthy();
    const inventoryPayload = await inventoryResponse.json();
    const source = inventoryPayload.sources.find(
      (candidate) => candidate.name === sourceName
    );
    expect(source).toBeTruthy();

    const pricingResponse = await page.request.get(
      '/api/v1/branches/' + branchId + '/pricing'
    );
    expect(pricingResponse.ok()).toBeTruthy();
    const pricing = await pricingResponse.json();
    const list = pricing.price_lists.find(
      (candidate) => candidate.name === listName
    );
    expect(list).toBeTruthy();

    await page.goto('/admin/storefront');
    await expect(
      page.getByRole('heading', { name: 'E-commerce' })
    ).toBeVisible();

    const channelForm = page.locator('form.channel-admin-form');
    await expect(channelForm).toBeVisible();
    await channelForm.getByLabel('Nombre del canal').fill(channelName);
    await channelForm.getByLabel('Identificador').fill(channelSlug);
    for (const [label, name, value] of [
      ['Fuente de inventario', 'inventory_source_id', source.id],
      ['Lista de precios', 'price_list_id', list.id],
    ]) {
      const select = channelForm.locator(`select[name="${name}"]`);
      if (await select.count()) {
        await channelForm.getByLabel(label).selectOption(value);
      } else {
        await expect(
          channelForm.locator(`input[type="hidden"][name="${name}"]`)
        ).toHaveValue(value);
      }
    }
    await channelForm.getByLabel('Canal activo').check();
    await channelForm.getByRole('button').click();

    await expect(page).toHaveURL(/\/admin\/storefront\?saved=channel$/);
    await expect(
      page.getByText('La configuración del canal fue guardada.')
    ).toBeVisible();
    await expect(page.getByText('Publicable')).toBeVisible();

    await page.goto('/' + context.tenant.slug);

    const publicCatalog = page.locator('.storefront-catalog');
    await expect(
      publicCatalog.getByRole('heading', { name: channelName })
    ).toBeVisible();
    await expect(publicCatalog.getByText(productName)).toBeVisible();
    await expect(publicCatalog.getByText('COP 125.000')).toBeVisible();
    await expect(publicCatalog.getByText('Disponible')).toBeVisible();
  });
});
