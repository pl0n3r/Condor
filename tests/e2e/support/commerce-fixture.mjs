import { expect } from '@playwright/test';

export async function provisionCommerceFixture(page, {
  email,
  password,
  productName,
  productSlug,
  sku,
  sourceName,
  sourceSlug,
  listName,
  listSlug,
  channelName,
  channelSlug,
  stock,
  price = '125000',
}) {
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
  await inventory.getByLabel('Cantidad (+ entrada / - salida)').fill(String(stock));
  await inventory.getByLabel('Motivo').fill('Stock E2E ' + productSlug);
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
  await priceForm.getByLabel('Precio (COP)').fill(price);
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
  const variant = pricing.variants.find(
    (candidate) => candidate.sku === sku
  );
  expect(list).toBeTruthy();
  expect(variant).toBeTruthy();

  await page.goto('/admin/storefront');
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

  return {
    context,
    branchId,
    source,
    list,
    variant,
  };
}
