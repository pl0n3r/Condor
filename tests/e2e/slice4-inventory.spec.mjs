import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 4 — inventario', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E ejecutándose y usuario propietario del tenant.'
  );

  test('ajusta y transfiere stock con trazabilidad desde el Admin', async ({
    page,
  }, testInfo) => {
    const suffix = Date.now() + '-' + testInfo.retry;
    const productName = 'Inventario E2E ' + suffix;
    const slug = 'inventario-e2e-' + suffix;
    const sku = 'INV-E2E-' + suffix;
    const sourceA = 'Origen ' + suffix;
    const sourceB = 'Destino ' + suffix;
    const sourceSlugA = 'origen-' + suffix;
    const sourceSlugB = 'destino-' + suffix;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/admin$/);

    const catalog = page.locator('#catalog');
    await catalog.getByRole('button', { name: 'Nuevo producto' }).click();
    await catalog.getByLabel('Nombre', { exact: true }).fill(productName);
    await catalog.getByLabel('Slug').fill(slug);
    await catalog.getByRole('button', { name: 'Crear producto' }).click();
    await expect(catalog.getByText('Producto creado.')).toBeVisible();

    let productCard = catalog.locator('.catalog-card').filter({
      has: page.getByRole('heading', { name: productName }),
    });
    await productCard.getByRole('button', { name: 'Añadir variante' }).click();
    await productCard.getByLabel('SKU').fill(sku);
    await productCard.getByLabel('Nombre de variante').fill('Única');
    await productCard.getByRole('button', { name: 'Crear variante' }).click();
    await expect(catalog.getByText('Variante creada.')).toBeVisible();

    const inventory = page.locator('#inventory');
    await expect(
      inventory.getByRole('heading', { name: 'Inventario', exact: true })
    ).toBeVisible();

    async function createLogicalSource(name, sourceSlug) {
      await inventory.getByLabel('Nombre', { exact: true }).fill(name);
      await inventory.getByLabel('Slug').fill(sourceSlug);
      await inventory.getByLabel('Tipo').selectOption('logical');
      await inventory.getByRole('button', { name: 'Crear fuente' }).click();
      await expect(inventory.getByText('Fuente creada.')).toBeVisible();
      await expect(
        inventory.locator('.catalog-card').filter({ hasText: name }).first()
      ).toBeVisible();
    }

    await createLogicalSource(sourceA, sourceSlugA);
    await createLogicalSource(sourceB, sourceSlugB);

    await inventory.getByLabel('Fuente').selectOption({ label: sourceA });
    await inventory.getByLabel('Variante').first().selectOption({ label: productName + ' · ' + sku });
    await inventory.getByLabel('Cantidad (+ entrada / - salida)').fill('10');
    await inventory.getByLabel('Motivo').fill('Carga E2E');

    const adjustmentKeys = [];
    let loseAdjustmentResponse = true;
    await page.route('**/inventory/adjustments', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }

      const payload = route.request().postDataJSON();
      adjustmentKeys.push(payload.idempotency_key);
      if (loseAdjustmentResponse) {
        loseAdjustmentResponse = false;
        const upstream = await route.fetch();
        expect(upstream.ok()).toBeTruthy();
        await route.abort('connectionfailed');
        return;
      }

      await route.continue();
    });

    await inventory.getByRole('button', { name: 'Aplicar ajuste' }).click();
    await expect(
      inventory.getByRole('button', { name: 'Aplicar ajuste' })
    ).toBeEnabled();
    await inventory.getByRole('button', { name: 'Aplicar ajuste' }).click();
    await expect(inventory.getByText('Ajuste aplicado.')).toBeVisible();
    await page.unroute('**/inventory/adjustments');

    expect(adjustmentKeys).toHaveLength(2);
    expect(adjustmentKeys[1]).toBe(adjustmentKeys[0]);

    const transferForm = inventory.locator('form.catalog-card').filter({
      has: page.getByRole('heading', { name: 'Transferencia' }),
    });
    await expect(transferForm).toBeVisible();

    const transferSelects = transferForm.locator('select');
    await transferSelects.nth(0).selectOption({ label: sourceA });
    await expect(
      transferSelects.nth(0).locator('option:checked')
    ).toHaveText(sourceA);
    await transferSelects.nth(1).selectOption({ label: sourceB });
    await transferSelects.nth(2).selectOption({
      label: productName + ' · ' + sku,
    });
    await transferForm.getByLabel('Cantidad', { exact: true }).fill('4');

    const transferKeys = [];
    let loseTransferResponse = true;
    await page.route('**/inventory/transfers', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }

      const payload = route.request().postDataJSON();
      transferKeys.push(payload.idempotency_key);
      if (loseTransferResponse) {
        loseTransferResponse = false;
        const upstream = await route.fetch();
        expect(upstream.ok()).toBeTruthy();
        await route.abort('connectionfailed');
        return;
      }

      await route.continue();
    });

    await transferForm.getByRole('button', { name: 'Transferir' }).click();
    await expect(
      transferForm.getByRole('button', { name: 'Transferir' })
    ).toBeEnabled();
    await transferForm.getByRole('button', { name: 'Transferir' }).click();
    await expect(inventory.getByText('Transferencia aplicada.')).toBeVisible();
    await page.unroute('**/inventory/transfers');

    expect(transferKeys).toHaveLength(2);
    expect(transferKeys[1]).toBe(transferKeys[0]);

    const originBalance = inventory.locator('.catalog-card').filter({
      hasText: sourceA,
    }).filter({ hasText: productName });
    const destinationBalance = inventory.locator('.catalog-card').filter({
      hasText: sourceB,
    }).filter({ hasText: productName });

    await expect(originBalance.getByText('6 und.')).toBeVisible();
    await expect(destinationBalance.getByText('4 und.')).toBeVisible();
    const movementRows = inventory.locator('.catalog-variant-row');
    await expect(
      movementRows
        .filter({ hasText: sourceA })
        .filter({ hasText: 'Ajuste de entrada' })
        .filter({ hasText: 'Carga E2E' })
    ).toBeVisible();
    await expect(
      movementRows
        .filter({ hasText: sourceA })
        .filter({ hasText: 'Transferencia de salida' })
    ).toBeVisible();
    await expect(
      movementRows
        .filter({ hasText: sourceB })
        .filter({ hasText: 'Transferencia de entrada' })
    ).toBeVisible();

    await page.reload();
    productCard = page.locator('#catalog').locator('.catalog-card').filter({
      has: page.getByRole('heading', { name: productName }),
    });
    await expect(productCard).toBeVisible();

    const reloadedInventory = page.locator('#inventory');
    await expect(
      reloadedInventory.locator('.catalog-card')
        .filter({ hasText: sourceA })
        .filter({ hasText: productName })
        .getByText('6 und.')
    ).toBeVisible();
    await expect(
      reloadedInventory.locator('.catalog-card')
        .filter({ hasText: sourceB })
        .filter({ hasText: productName })
        .getByText('4 und.')
    ).toBeVisible();
  });
});
