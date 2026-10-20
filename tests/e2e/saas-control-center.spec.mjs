import { expect, test } from '@playwright/test';

// Browser regressions use the built application, not source-text assertions.
// All responses are synthetic; no login, customer data or external writes.
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL;
const TENANT = '01H' + '0'.repeat(23);
const catalogPlan = {
  plan_version_id: 'pv-v1', key: 'starter', name: 'Inicial',
  version: 1, currency: 'COP', monthly_amount: 120000,
  annual_amount: 1200000, quote_required: false,
};
const subscription = {
  status: 'configured',
  subscription: {
    state: 'active',
    plan: {
      key: 'starter', name: 'Inicial', version: 1,
      currency: 'COP', monthly_amount: 120000,
      annual_amount: 1200000, quote_required: false,
    },
    last_changed_at: '2026-10-20T00:00:00Z',
  },
};

function context(configured = false) {
  return {
    mode: 'tenant',
    actor: { id: 'synthetic-owner', name: 'Propietario E2E', role: 'platform_owner' },
    metrics: {
      tenant_count: 1, user_count: 1, branch_count: 0,
      active_membership_count: 1,
    },
    signals_last_30_days: {},
    commercial_catalog: [catalogPlan],
    commercial_portfolio: [],
    tenants: [{
      id: TENANT, name: 'Empresa sintética', slug: 'empresa-sintetica',
      branch_count: 0, active_membership_count: 1,
    }],
    tenant_pagination: {
      page: 1, per_page: 20, total: 1, has_previous: false, has_next: false,
    },
    selected_tenant: {
      id: TENANT, name: 'Empresa sintética', slug: 'empresa-sintetica',
      branch_count: 0, active_membership_count: 1,
      branches: [], catalog: [], commercial_subscription: configured
        ? subscription : { status: 'not_configured', subscription: null },
      commercial_subscription_changes: [],
    },
    version: '0.1.194',
  };
}

function ready() {
  return {
    state: 'ready', tenant_id: TENANT,
    plan: { key: 'starter', version: '1', currency: 'COP', price_minor: 120000 },
    add_ons: [{ key: 'extra', price_minor: null, active: false }],
    usage: [{ metric: 'storage_mb', used: 2, limit: 100 }],
    entitlements: [{ key: 'uploads', allowed: true }],
  };
}

async function fulfillJson(route, status, body) {
  await route.fulfill({
    status, contentType: 'application/json', body: JSON.stringify(body),
  });
}

async function renderOwner(page, commercialRead, contextRead = () => context()) {
  await page.route('**/adminpl0n3r/api/context*', async (route) => {
    await fulfillJson(route, 200, contextRead());
  });
  await page.route('**/adminpl0n3r/api/tenants/*/saas-control-center', async (route) => {
    const { status, body } = commercialRead();
    await fulfillJson(route, status, body);
  });
  await page.goto('/');
  await page.setContent(
    '<div id="condor-platform-root" data-version="0.1.194" ' +
      'data-logout-token="synthetic-token" data-subscription-token="synthetic-token"></div>' +
      '<script type="module" src="/build/admin.js"></script>',
  );
  return page.locator('.platform-section').filter({
    has: page.getByRole('heading', { name: 'Control SaaS por empresa' }),
  });
}

test.describe('SaaS Control Center — navegación y errores reales en Chromium', () => {
  test.skip(!BASE_URL, 'Requiere el servidor E2E y el bundle compilado de Condor.');

  test('HTTP 403 rechaza el acceso sin mostrar importes ni reintento', async ({ page }) => {
    const panel = await renderOwner(page, () => ({ status: 403, body: {} }));
    await expect(panel.getByRole('alert')).toContainText('no tiene permiso');
    await expect(panel.getByRole('button', { name: 'Reintentar consulta' })).toHaveCount(0);
    await expect(panel).not.toContainText('120000');
  });

  test('HTTP 404 muestra no encontrado sin ofrecer retry', async ({ page }) => {
    const panel = await renderOwner(page, () => ({ status: 404, body: {} }));
    await expect(panel.getByRole('alert')).toContainText('no se encontró');
    await expect(panel.getByRole('button', { name: 'Reintentar consulta' })).toHaveCount(0);
  });

  test('HTTP 5xx no inventa datos y un retry lee de nuevo', async ({ page }) => {
    let reads = 0;
    const panel = await renderOwner(page, () => {
      reads += 1;
      return reads === 1
        ? { status: 503, body: {} }
        : { status: 200, body: ready() };
    });
    await expect(panel.getByRole('alert')).toContainText('UNKNOWN');
    await panel.getByRole('button', { name: 'Reintentar consulta' }).click();
    await expect(panel).toContainText('storage_mb');
    expect(reads).toBe(2);
  });

  test('empty declara UNKNOWN y no simula un plan', async ({ page }) => {
    const panel = await renderOwner(page, () => ({
      status: 200, body: { state: 'empty', tenant_id: TENANT },
    }));
    await expect(panel).toContainText('No hay un plan canónico configurado');
    await expect(panel).toContainText('UNKNOWN');
    await expect(panel.getByText('Permitido')).toHaveCount(0);
  });

  test('ready representa plan, add-on sin precio, uso y derechos', async ({ page }) => {
    const panel = await renderOwner(page, () => ({ status: 200, body: ready() }));
    await expect(panel).toContainText('Plan vigente');
    await expect(panel).toContainText('Add-ons canónicos');
    await expect(panel).toContainText('extra: UNKNOWN');
    await expect(panel).toContainText('storage_mb');
    await expect(panel).toContainText('2 / 100');
    await expect(panel).toContainText('uploads');
    await expect(panel).toContainText('Permitido');
  });

  test('POST comercial exitoso actualiza contexto y vuelve a consultar derechos', async ({ page }) => {
    let configured = false;
    let rightsReads = 0;
    await page.route('**/adminpl0n3r/api/tenants/*/commercial-subscription', async (route) => {
      expect(route.request().method()).toBe('POST');
      configured = true;
      await fulfillJson(route, 200, { status: 'ok' });
    });
    const panel = await renderOwner(
      page,
      () => {
        rightsReads += 1;
        return configured
          ? { status: 200, body: ready() }
          : { status: 200, body: { state: 'empty', tenant_id: TENANT } };
      },
      () => context(configured),
    );
    await expect(panel).toContainText('No hay un plan canónico configurado');
    await page.getByRole('combobox', { name: 'Plan vigente' }).selectOption('pv-v1');
    await page.getByRole('button', { name: 'Crear suscripción', exact: true }).click();
    await expect(panel).toContainText('Permitido');
    await expect.poll(() => rightsReads).toBe(2);
    expect(configured).toBe(true);
  });
});
