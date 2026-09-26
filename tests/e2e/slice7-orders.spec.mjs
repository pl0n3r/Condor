import { expect, test } from '@playwright/test';
import { provisionCommerceFixture } from './support/commerce-fixture.mjs';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 7 — pedidos y reservas', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E ejecutándose y usuario propietario del tenant.'
  );

  test('retry idempotente, libera/consume reserva y protege checkout concurrente', async ({
    page,
  }, testInfo) => {
    const suffix = Date.now() + '-' + testInfo.retry;
    const productName = 'Pedidos E2E ' + suffix;
    const sku = 'ORD-E2E-' + suffix;
    const sourceName = 'Bodega pedidos ' + suffix;
    const listName = 'Lista pedidos ' + suffix;

    const fixture = await provisionCommerceFixture(page, {
      email,
      password,
      productName,
      productSlug: 'pedidos-e2e-' + suffix,
      sku,
      sourceName,
      sourceSlug: 'bodega-pedidos-' + suffix,
      listName,
      listSlug: 'lista-pedidos-' + suffix,
      channelName: 'Checkout pedidos ' + suffix,
      channelSlug: 'checkout-pedidos-' + suffix,
      stock: 8,
    });
    const { context, branchId, source, variant } = fixture;

    async function inventorySnapshot() {
      const response = await page.request.get(
        '/api/v1/branches/' + branchId + '/inventory'
      );
      expect(response.ok()).toBeTruthy();
      return response.json();
    }

    async function ordersSnapshot() {
      const response = await page.request.get(
        '/api/v1/branches/' + branchId + '/orders'
      );
      expect(response.ok()).toBeTruthy();
      return response.json();
    }

    let balance = (await inventorySnapshot()).balances.find(
      (candidate) => (
        candidate.source_id === source.id
        && candidate.variant.id === variant.id
      )
    );
    expect(balance.quantity).toBe(8);

    await page.goto('/admin');
    const orders = page.locator('#orders');
    await expect(
      orders.getByRole('heading', { name: 'Pedidos', exact: true })
    ).toBeVisible();
    const orderForm = orders.locator('form.catalog-card').filter({
      has: page.getByRole('heading', { name: 'Crear pedido' }),
    });
    await orderForm.getByLabel('Fuente de inventario').selectOption({
      label: sourceName,
    });
    await orderForm.getByLabel('Lista de precios').selectOption({
      label: listName,
    });
    await orderForm.getByLabel('Variante').selectOption({
      label: productName + ' · ' + sku,
    });
    await orderForm.getByLabel('Cantidad').fill('3');

    const retryKeys = [];
    let loseFirstResponse = true;
    await page.route('**/api/v1/branches/*/orders', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }

      const payload = route.request().postDataJSON();
      retryKeys.push(payload.idempotency_key);
      if (loseFirstResponse) {
        loseFirstResponse = false;
        const upstream = await route.fetch();
        expect(upstream.status()).toBe(201);
        await route.abort('connectionfailed');
        return;
      }

      await route.continue();
    });

    await orderForm.getByRole('button', { name: 'Crear pedido' }).click();
    await expect(
      orderForm.getByRole('button', { name: 'Crear pedido' })
    ).toBeEnabled();
    await orderForm.getByRole('button', { name: 'Crear pedido' }).click();
    await expect(
      orders.getByText('Pedido creado y stock reservado.')
    ).toBeVisible();
    await page.unroute('**/api/v1/branches/*/orders');

    expect(retryKeys).toHaveLength(2);
    expect(retryKeys[1]).toBe(retryKeys[0]);

    let snapshot = await ordersSnapshot();
    const firstMatches = snapshot.orders.filter((order) => (
      order.order_status === 'open'
      && order.fulfillment_status === 'reserved'
      && order.lines.some((line) => line.variant_id === variant.id)
    ));
    expect(firstMatches).toHaveLength(1);
    const firstOrder = firstMatches[0];
    expect(firstOrder.lines[0].reservation_status).toBe('active');

    balance = (await inventorySnapshot()).balances.find(
      (candidate) => (
        candidate.source_id === source.id
        && candidate.variant.id === variant.id
      )
    );
    expect(balance.quantity).toBe(8);

    const firstCard = orders.locator('article.catalog-card').filter({
      has: page.getByRole('heading', {
        name: 'Pedido ' + firstOrder.id.slice(-8),
      }),
    });
    await firstCard.getByRole('button', { name: 'Cancelar' }).click();
    await expect(
      orders.getByText('Pedido cancelado y reserva liberada.')
    ).toBeVisible();

    snapshot = await ordersSnapshot();
    const cancelled = snapshot.orders.find(
      (order) => order.id === firstOrder.id
    );
    expect(cancelled.order_status).toBe('cancelled');
    expect(cancelled.fulfillment_status).toBe('released');
    expect(cancelled.lines[0].reservation_status).toBe('released');
    balance = (await inventorySnapshot()).balances.find(
      (candidate) => (
        candidate.source_id === source.id
        && candidate.variant.id === variant.id
      )
    );
    expect(balance.quantity).toBe(8);

    await orderForm.getByLabel('Variante').selectOption({
      label: productName + ' · ' + sku,
    });
    await orderForm.getByLabel('Cantidad').fill('3');
    await orderForm.getByRole('button', { name: 'Crear pedido' }).click();
    await expect(
      orders.getByText('Pedido creado y stock reservado.')
    ).toBeVisible();

    snapshot = await ordersSnapshot();
    const secondOrder = snapshot.orders.find((order) => (
      order.id !== firstOrder.id
      && order.order_status === 'open'
      && order.fulfillment_status === 'reserved'
      && order.lines.some((line) => line.variant_id === variant.id)
    ));
    expect(secondOrder).toBeTruthy();

    const secondCard = orders.locator('article.catalog-card').filter({
      has: page.getByRole('heading', {
        name: 'Pedido ' + secondOrder.id.slice(-8),
      }),
    });
    await secondCard.getByRole(
      'button',
      { name: 'Consumir inventario' }
    ).click();
    await expect(orders.getByText('Inventario consumido.')).toBeVisible();

    snapshot = await ordersSnapshot();
    const consumed = snapshot.orders.find(
      (order) => order.id === secondOrder.id
    );
    expect(consumed.order_status).toBe('open');
    expect(consumed.fulfillment_status).toBe('consumed');
    expect(consumed.lines[0].reservation_status).toBe('consumed');
    balance = (await inventorySnapshot()).balances.find(
      (candidate) => (
        candidate.source_id === source.id
        && candidate.variant.id === variant.id
      )
    );
    expect(balance.quantity).toBe(5);

    const checkoutPath = '/' + context.tenant.slug + '/checkout';
    const checkoutPayload = {
      idempotency_key: 'checkout-' + suffix,
      items: [{ variant_id: variant.id, quantity: 2 }],
    };
    const firstCheckout = await page.request.post(checkoutPath, {
      data: checkoutPayload,
    });
    expect(firstCheckout.status()).toBe(201);
    const firstCheckoutOrder = (await firstCheckout.json()).order;

    const replayCheckout = await page.request.post(checkoutPath, {
      data: checkoutPayload,
    });
    expect(replayCheckout.status()).toBe(200);
    expect((await replayCheckout.json()).order.id).toBe(firstCheckoutOrder.id);

    balance = (await inventorySnapshot()).balances.find(
      (candidate) => (
        candidate.source_id === source.id
        && candidate.variant.id === variant.id
      )
    );
    expect(balance.quantity).toBe(5);

    const checkout = (key) => page.request.post(checkoutPath, {
      data: {
        idempotency_key: key,
        items: [{ variant_id: variant.id, quantity: 3 }],
      },
    });
    const concurrent = await Promise.all([
      checkout('race-a-' + suffix),
      checkout('race-b-' + suffix),
    ]);
    expect(
      concurrent.filter((response) => response.status() === 201)
    ).toHaveLength(1);
    expect(
      concurrent.filter((response) => [409, 422].includes(response.status()))
    ).toHaveLength(1);

    balance = (await inventorySnapshot()).balances.find(
      (candidate) => (
        candidate.source_id === source.id
        && candidate.variant.id === variant.id
      )
    );
    expect(balance.quantity).toBe(5);
  });
});
