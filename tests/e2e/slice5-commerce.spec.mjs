import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 5 — clientes y precios', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E ejecutándose y usuario propietario del tenant.'
  );

  test('crea cliente, categoría, lista y precio efectivo desde Admin', async ({
    page,
  }, testInfo) => {
    const suffix = Date.now() + '-' + testInfo.retry;
    const productName = 'Comercial E2E ' + suffix;
    const productSlug = 'comercial-e2e-' + suffix;
    const sku = 'COM-E2E-' + suffix;
    const categoryName = 'Mayorista ' + suffix;
    const categorySlug = 'mayorista-' + suffix;
    const customerName = 'Cliente ' + suffix;
    const customerEmail = 'cliente-' + suffix + '@example.test';
    const listName = 'Lista ' + suffix;
    const listSlug = 'lista-' + suffix;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);

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

    const commerce = page.locator('#commerce');
    await expect(
      commerce.getByRole('heading', { name: 'Comercial', exact: true })
    ).toBeVisible();

    await commerce.getByLabel('Nombre de categoría').fill(categoryName);
    await commerce.getByLabel('Slug de categoría').fill(categorySlug);
    await commerce.getByRole('button', { name: 'Crear categoría' }).click();
    await expect(commerce.getByText('Categoría creada.')).toBeVisible();
    await expect(
      commerce.getByRole('strong', { name: categoryName })
    ).toBeVisible();

    await commerce.getByLabel('Nombre del cliente').fill(customerName);
    await commerce.getByLabel('Correo del cliente').fill(customerEmail);
    await commerce.getByLabel('Categoría', { exact: true })
      .selectOption({ label: categoryName });
    await commerce.getByRole('button', { name: 'Crear cliente' }).click();
    await expect(commerce.getByText('Cliente creado.')).toBeVisible();
    await expect(commerce.getByText(customerName)).toBeVisible();

    await commerce.getByLabel('Nombre de lista').fill(listName);
    await commerce.getByLabel('Slug de lista').fill(listSlug);
    await commerce.getByRole('button', { name: 'Crear lista' }).click();
    await expect(commerce.getByText('Lista creada.')).toBeVisible();

    await commerce.getByLabel('Lista preferida de ' + categoryName)
      .selectOption({ label: listName });
    await expect(
      commerce.getByText('Lista preferida actualizada.')
    ).toBeVisible();

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
    await expect(commerce.getByText(/125[.\s]?000/)).toBeVisible();

    const contextResponse = await page.request.get('/api/v1/context');
    expect(contextResponse.ok()).toBeTruthy();
    const context = await contextResponse.json();

    const customerResponse = await page.request.get(
      '/api/v1/branches/' + context.active_branch.id + '/customers'
    );
    expect(customerResponse.ok()).toBeTruthy();
    const customerPayload = await customerResponse.json();
    const customer = customerPayload.customers.find(
      (candidate) => candidate.name === customerName
    );
    const category = customerPayload.categories.find(
      (candidate) => candidate.name === categoryName
    );
    expect(customer).toBeTruthy();
    expect(category).toBeTruthy();

    const pricingResponse = await page.request.get(
      '/api/v1/branches/' + context.active_branch.id + '/pricing'
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

    const effectiveResponse = await page.request.get(
      '/api/v1/branches/' + context.active_branch.id +
      '/pricing/effective?variant=' + variant.id +
      '&customer=' + customer.id
    );
    expect(effectiveResponse.ok()).toBeTruthy();
    const effective = (await effectiveResponse.json()).effective_price;
    expect(effective.amount_minor).toBe(12500000);
    expect(effective.base_amount_minor).toBe(12500000);
    expect(effective.currency).toBe('COP');
    expect(effective.price_list_id).toBe(list.id);
    expect(effective.rule_id).toBeNull();

    await page.reload();
    const reloadedCommerce = page.locator('#commerce');
    await expect(reloadedCommerce.getByText(customerName)).toBeVisible();
    await expect(reloadedCommerce.getByText(categoryName)).toBeVisible();
    await expect(reloadedCommerce.getByText(listName)).toBeVisible();
    await expect(reloadedCommerce.getByText(/125[.\s]?000/)).toBeVisible();
  });
});
