import { expect, test } from '@playwright/test';

// Datos inventados, sesión sintética y mutaciones interceptadas: cero escrituras live.
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL;
const plans = [
  { plan_version_id: 'pv-2', key: 'starter', name: 'Inicial', version: 2, currency: 'COP',
    monthly_amount: null, annual_amount: null, quote_required: false,
    limits: { users: 3 }, capabilities: ['catalog.view'] },
  { plan_version_id: 'pv-3', key: 'gratis', name: 'Gratuito', version: 3, currency: 'COP',
    monthly_amount: 0, annual_amount: 0, quote_required: false,
    limits: { users: 1 }, capabilities: [] },
  { plan_version_id: 'pv-4', key: 'empresa', name: 'Personalizado', version: 4, currency: 'COP',
    monthly_amount: null, annual_amount: null, quote_required: true,
    limits: {}, capabilities: [] },
];

const context = {
  mode: 'global',
  actor: { id: 'fictional-actor', name: 'Propietario de prueba', role: 'platform_owner' },
  metrics: { tenant_count: 0, user_count: 1, branch_count: 0, active_membership_count: 0 },
  signals_last_30_days: {},
  commercial_catalog: plans,
  commercial_portfolio: [],
  tenants: [],
  tenant_pagination: { page: 1, per_page: 20, total: 0, has_previous: false, has_next: false },
  selected_tenant: null,
  version: '0.1.198',
};

async function renderPanel(page, onPrice) {
  await page.route('**/adminpl0n3r/api/context*', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(context) });
  });
  await page.route('**/adminpl0n3r/planes', async (route) => {
    await route.fulfill({ status: 200, contentType: 'text/html',
      body: '<form><input type="hidden" name="_csrf_token" value="synthetic-csrf"></form>' });
  });
  await page.route('**/adminpl0n3r/api/auditoria*', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      items: [{ id: 'event-1', actor: 'actor-de-prueba', action: 'commercial.plan.prices.scheduled',
        created_at: '2026-11-06T00:00:00Z', before: { plan_key: 'starter', version: 1 },
        after: { plan_key: 'starter', version: 2, monthly_amount: null, annual_amount: null } }],
      page: 1, total: 1, has_next: false,
    }) });
  });
  await page.route('**/adminpl0n3r/api/plans/*/prices', async (route) => {
    await onPrice(route);
  });
  await page.goto('/');
  await page.evaluate(() => history.replaceState(null, '', '/adminpl0n3r/catalogo?vista=planes'));
  await page.setContent(
    '<div id="condor-platform-root" data-version="0.1.198" data-section="catalogo"' +
    ' data-logout-token="synthetic-logout" data-subscription-token="synthetic-subscription"></div>' +
    '<script type="module" src="/build/admin.js"></script>',
  );
  return page.getByRole('region', { name: 'Planes y precios' });
}

test.describe('Planes y precios · owner sintético', () => {
  test.skip(!BASE_URL, 'Requiere servidor de fixtures y build admin sobre este HEAD.');

  test('distingue null, cero y Cotizar en tabla de planes', async ({ page }) => {
    const panel = await renderPanel(page, (route) => route.fulfill({ status: 200, body: '{}' }));
    await expect(panel.getByRole('heading', { name: 'Planes y precios' })).toBeVisible();
    const table = panel.getByRole('table', { name: 'Planes y precios vigentes' });
    await expect(table.getByRole('row').filter({ hasText: 'Inicial' })).toContainText('Sin tarifa');
    await expect(table.getByRole('row').filter({ hasText: 'Gratuito' })).toContainText('0');
    await expect(table.getByRole('row').filter({ hasText: 'Personalizado' })).toContainText('Cotizar');
    await panel.getByPlaceholder('Buscar por nombre o clave').fill('Gratuito');
    await expect(table.getByRole('row')).toHaveCount(2); // encabezado + resultado
  });

  test('crea versión de precios con preview, 0 y null distintos y CSRF owner-only', async ({ page }) => {
    let recorded;
    const panel = await renderPanel(page, async (route) => {
      recorded = { body: route.request().postDataJSON(),
        csrf: route.request().headers()['x-csrf-token'], method: route.request().method() };
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });
    await panel.getByRole('button', { name: 'Inicial' }).click();
    const details = page.getByRole('dialog', { name: /Inicial/ });
    await expect(details).toBeVisible();
    await details.getByLabel('Precio mensual').fill('0');
    await details.getByLabel('Precio anual').fill('');
    await details.getByRole('button', { name: 'Revisar versión' }).click();
    await expect(details).toContainText('Vista previa de v3');
    await details.getByRole('button', { name: 'Confirmar versión' }).click();
    await expect(details).toContainText('Versión de tarifas registrada');
    expect(recorded.csrf).toBe('synthetic-csrf');
    expect(recorded.method).toBe('POST');
    expect(recorded.body).toMatchObject({
      expected_version: 2, currency: 'COP', monthly_amount: 0, annual_amount: null,
      quote_required: false, effective_from: null, addons: [], verticals: [],
    });
  });

  test('refleja conflicto 422 sin afirmar guardado e historial auditado con actor', async ({ page }) => {
    const panel = await renderPanel(page, (route) => route.fulfill({
      status: 422, contentType: 'application/json', body: '{"error":"conflict"}',
    }));
    await panel.getByRole('button', { name: 'Inicial' }).click();
    const details = page.getByRole('dialog', { name: /Inicial/ });
    await details.getByRole('button', { name: 'Revisar versión' }).click();
    await details.getByRole('button', { name: 'Confirmar versión' }).click();
    await expect(details.getByRole('alert')).toContainText('versión');
    await details.getByRole('tab', { name: 'Historial' }).click();
    await expect(details).toContainText('actor-de-prueba');
    await expect(details).toContainText('v2');
  });
});
