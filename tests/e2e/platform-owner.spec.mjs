import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Super Admin — clientes y staff de plataforma', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E y propietario de plataforma.',
  );

  test('crea un cliente e invita staff con permisos CRUD reales', async ({ page }) => {
    const suffix = Date.now().toString();
    const tenantName = 'Cliente E2E ' + suffix;
    const tenantSlug = 'cliente-e2e-' + suffix;
    const tenantOwnerEmail = 'cliente-owner-' + suffix + '@example.test';
    const staffEmail = 'staff-' + suffix + '@example.test';

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    await expect(
      page.getByRole('heading', { name: 'Administración global' }),
    ).toBeVisible();

    const platformNav = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await platformNav.getByRole('button', { name: 'Empresas', exact: true }).click();
    await platformNav.getByRole('link', { name: 'Empresas', exact: true }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/empresas$/);

    // El alta no queda expuesta en la página: abrir su panel lateral.
    await expect(page.getByRole('button', { name: 'Crear empresa e invitar admin' })).toBeHidden();
    await page.getByRole('button', { name: 'Crear cliente', exact: true }).click();
    const creation = page.getByRole('dialog', { name: 'Crear cliente' });
    await expect(creation).toBeVisible();
    await creation.getByLabel('Nombre comercial').fill(tenantName);
    await creation.getByLabel('Slug').fill(tenantSlug);
    await creation.getByLabel('Razón social').fill(tenantName + ' SAS');
    await creation.getByLabel('Nombre del administrador')
      .fill('Administradora E2E');
    await creation.getByLabel('Correo del administrador')
      .fill(tenantOwnerEmail);

    const creationResponse = page.waitForResponse((response) =>
      response.url().includes('/adminpl0n3r/api/tenants')
      && response.request().method() === 'POST');
    await creation.getByRole('button', {
      name: 'Crear empresa e invitar admin',
    }).click();
    const activationPayload = await (await creationResponse).json();
    const activationPath = activationPayload.invitation.activation_path_once;
    expect(activationPath).toMatch(/^\/activar-cuenta\/[a-f0-9]{64}$/);

    // La invitación de una sola visualización debe sobrevivir al refresco
    // de la lista; el propietario decide cuándo cerrar el panel.
    await expect(
      creation.getByText(
        tenantName +
          ' fue creada. El administrador quedó pendiente de activación.',
      ),
    ).toBeVisible();
    await expect(
      creation.locator('.activation-once').filter({
        hasText: 'Enlace de activación',
      }),
    ).toBeVisible();
    await expect(creation.locator('.activation-once code')).toHaveText(activationPath);

    await creation.getByRole('button', { name: 'Cerrar' }).click();
    // Un enlace de uso único debe desaparecer antes de volver a crear otra empresa.
    await page.getByRole('button', { name: 'Crear cliente', exact: true }).click();
    await expect(creation.locator('.activation-once')).toHaveCount(0);
    await creation.getByRole('button', { name: 'Cerrar' }).click();

    const tenantsTable = page.getByRole('table', { name: 'Empresas de esta página' });
    const tenantRow = tenantsTable.getByRole('row').filter({ hasText: tenantName });
    const pagination = page.getByRole('navigation', { name: 'Paginación de empresas' });
    // El backend pagina empresas; la nueva no siempre se muestra en la primera página.
    for (let scanned = 0; scanned < 100 && !(await tenantRow.isVisible()); scanned++) {
      const next = pagination.getByRole('button', { name: 'Siguiente' });
      if (await next.isDisabled()) break;
      const pageBefore = await pagination.locator('span').textContent();
      await next.click();
      await expect(pagination.locator('span')).not.toHaveText(pageBefore);
    }
    await expect(tenantRow).toBeVisible();

    await platformNav.getByRole('button', { name: 'Comercial', exact: true }).click();
    await platformNav.getByRole('link', { name: 'Plan y suscripciones' }).click();
    await expect(page.getByRole('heading', { name: 'Plan y suscripciones' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Suscripciones de esta página' })).toBeVisible();
    await platformNav.getByRole('link', { name: 'Catálogo comercial' }).click();
    await expect(page.getByRole('heading', { name: 'Catálogo comercial' })).toBeVisible();

    await platformNav.getByRole('button', { name: 'Plataforma', exact: true }).click();
    await platformNav.getByRole('link', { name: 'Staff y permisos', exact: true }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/staff$/);

    const staffSection = page.locator('.platform-section').filter({
      has: page.getByRole('heading', { name: 'Staff de plataforma' }),
    });

    // La creación empieza cerrada y solo se abre con la acción de cabecera.
    await expect(page.getByRole('dialog', { name: 'Invitar staff' })).toHaveCount(0);
    await staffSection.getByRole('button', { name: 'Invitar staff' }).click();
    const staffPanel = page.getByRole('dialog', { name: 'Invitar staff' });
    await expect(staffPanel).toBeVisible();
    await staffPanel.getByLabel('Nombre', { exact: true })
      .fill('Staff E2E');
    await staffPanel.getByLabel('Correo', { exact: true })
      .fill(staffEmail);
    await staffPanel.getByLabel('Cliente').selectOption({
      label: tenantName,
    });
    await staffPanel.getByLabel('Módulo').selectOption('catalog');

    const crud = staffPanel.getByRole('group', {
      name: 'CRUD permitido',
    });
    await crud.getByRole('checkbox', { name: 'Ver' }).check();
    await crud.getByRole('checkbox', { name: 'Editar' }).check();

    await staffPanel.getByRole('button', {
      name: 'Añadir permiso',
    }).click();

    await expect(
      staffPanel.getByText('Catálogo · update, view'),
    ).toBeVisible();

    await staffPanel.getByRole('button', {
      name: 'Crear staff e invitar',
    }).click();

    await expect(
      staffPanel.getByText('Staff creado y pendiente de activación.'),
    ).toBeVisible();
    const staffTable = staffSection.getByRole('table', { name: 'Staff registrado' });
    const staffRow = staffTable.getByRole('row').filter({ hasText: staffEmail });
    await expect(staffRow).toBeVisible();
    await expect(staffRow).toContainText('Catálogo');
    await staffPanel.getByRole('button', { name: 'Cancelar' }).click();
    await expect(page.getByRole('dialog', { name: 'Invitar staff' })).toHaveCount(0);
  });
  async function openStaffWithFixture(page, responseBody, status = 200) {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    await page.route('**/adminpl0n3r/api/staff', route => route.fulfill({
      status, contentType: 'application/json', body: JSON.stringify(responseBody),
    }));
    await page.goto('/adminpl0n3r/staff');
  }

  const syntheticCatalog = [{ key: 'catalog', label: 'Catálogo', permissions: [
    { key: 'catalog.view', action: 'view', label: 'Ver' },
    { key: 'catalog.update', action: 'update', label: 'Editar' },
  ] }];
  const syntheticStaff = {
    id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', name: 'Persona de prueba',
    email: 'staff@example.test', active: false,
    invitation: { id: 'inv-synthetic', state: 'pending', expires_at: '2026-12-01T00:00:00Z' },
    grants: [{ tenant_id: null, module: 'catalog', actions: ['view'] }],
  };
  const syntheticPayload = {
    staff: [syntheticStaff], catalog: syntheticCatalog,
    tenant_options: [], tenant_options_truncated: false,
  };

  test('Staff deniega el acceso sin exponer registros cuando GET responde 403', async ({ page }) => {
    await openStaffWithFixture(page, { error: 'denied' }, 403);
    await expect(page.getByRole('alert')).toContainText('No tienes permiso');
    await expect(page.getByRole('table', { name: 'Staff registrado' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Invitar staff' })).toBeDisabled();
  });

  test('Staff distingue lista vacía, búsqueda sin resultados y fallo de red', async ({ page }) => {
    await openStaffWithFixture(page, { ...syntheticPayload, staff: [] });
    await expect(page.getByText('Aún no hay staff de plataforma.')).toBeVisible();
    await page.unroute('**/adminpl0n3r/api/staff');
    await page.route('**/adminpl0n3r/api/staff', route => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify(syntheticPayload),
    }));
    await page.reload();
    await expect(page.getByRole('table', { name: 'Staff registrado' })).toBeVisible();
    await page.getByPlaceholder('Nombre, correo o cliente').fill('no-existe');
    await expect(page.getByText('No hay resultados para estos filtros.')).toBeVisible();
    await page.unroute('**/adminpl0n3r/api/staff');
    await page.route('**/adminpl0n3r/api/staff', route => route.fulfill({
      status: 503, contentType: 'application/json', body: '{}',
    }));
    await page.reload();
    await expect(page.getByRole('alert')).toContainText('No fue posible cargar');
    await expect(page.getByRole('table', { name: 'Staff registrado' })).toHaveCount(0);
  });

  test('Staff cancela confirmación sensible sin POST ni DELETE', async ({ page }) => {
    await openStaffWithFixture(page, syntheticPayload);
    const mutationRequests = [];
    page.on('request', request => {
      if (['POST', 'DELETE'].includes(request.method())
        && request.url().includes('/adminpl0n3r/api/staff')) mutationRequests.push(request);
    });
    await page.getByRole('button', { name: 'Reemitir invitación' }).click();
    const confirm = page.getByRole('dialog', { name: 'Reemitir invitación' });
    await expect(confirm).toBeVisible();
    await confirm.getByRole('button', { name: 'Cancelar' }).click();
    await expect(confirm).toHaveCount(0);
    await page.getByRole('button', { name: 'Revocar invitación' }).click();
    const revoke = page.getByRole('dialog', { name: 'Revocar invitación' });
    await expect(revoke).toBeVisible();
    await revoke.getByRole('button', { name: 'Cancelar' }).click();
    expect(mutationRequests).toHaveLength(0);
  });

});


// #1693: navegación y pantalla completas con datos inventados; no login ni red de producción.
const syntheticRevenueContext = {
  mode: 'global',
  actor: { id: 'owner-fixture', name: 'Propietario de prueba', role: 'platform_owner' },
  metrics: { tenant_count: 1, user_count: 1, branch_count: 0, active_membership_count: 1 },
  signals_last_30_days: {},
  commercial_catalog: [],
  commercial_portfolio: [],
  tenants: [],
  tenant_pagination: { page: 1, per_page: 20, total: 0, has_previous: false, has_next: false },
  selected_tenant: null,
  version: '0.1.198',
};

function syntheticRevenueResponse(url) {
  const from = url.searchParams.get('from');
  const to = url.searchParams.get('to');
  const plan = url.searchParams.get('plan') || '';
  const amounts = { new: 120000, expansion: 0, contraction: 0, churn: 0, mrr_delta: 120000 };
  const zero = { new: 0, expansion: 0, contraction: 0, churn: 0, mrr_delta: 0 };
  const allMovements = [
    { tenant_id: 'tenant-ficticio', company: 'Empresa ficticia', plan_key: 'starter',
      plan_name: 'Inicial', type: 'new', recognition: 'effective',
      occurred_at: new Date().toISOString().slice(0, 10) + 'T12:00:00.000000Z',
      amount: 120000, mrr_delta: 120000 },
  ];
  const filtered = allMovements.filter(m => (!plan || m.plan_key === plan)
    && m.occurred_at.slice(0, 10) >= from && m.occurred_at.slice(0, 10) <= to);
  return {
    status: 'ready', currency: 'COP', period: { from, to },
    totals: filtered.length ? amounts : zero,
    plans: filtered.length ? [{ key: 'starter', name: 'Inicial', ...amounts }] : [],
    companies: filtered.length ? [{ tenant_id: 'tenant-ficticio', company: 'Empresa ficticia', ...amounts }] : [],
    movements: filtered,
  };
}

async function openRevenueFixture(page, revenueHandler) {
  await page.route('**/adminpl0n3r/api/context*', route => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify(syntheticRevenueContext),
  }));
  await page.route('**/adminpl0n3r/api/ingresos*', async route => {
    await revenueHandler(route);
  });
  await page.goto('/');
  await page.evaluate(() => history.replaceState(null, '', '/adminpl0n3r/catalogo?vista=ingresos'));
  await page.setContent(
    '<link rel="stylesheet" href="/build/admin.css">' +
    '<div id="condor-platform-root" data-version="0.1.198" data-section="catalogo"' +
    ' data-logout-token="synthetic-logout" data-subscription-token="synthetic-subscription"></div>' +
    '<script type="module" src="/build/admin.js"></script>',
  );
  return page.getByRole('region', { name: 'Ingresos SaaS del propietario' });
}

test.describe('Ingresos SaaS de propietario · sintético (#1693)', () => {
  test.skip(!process.env.PLAYWRIGHT_BASE_URL, 'Requiere servidor de fixtures y bundle compilado.');

  test('abre la pantalla desde Comercial, detalla ingresos y no confunde cifras verificadas', async ({ page }) => {
    const requests = [];
    page.on('request', request => {
      if (request.url().includes('/api/ingresos')) requests.push(request.method());
    });
    const panel = await openRevenueFixture(page, route => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify(syntheticRevenueResponse(new URL(route.request().url()))),
    }));
    await expect(panel.getByRole('heading', { name: 'Ingresos SaaS' })).toBeVisible();
    const navigation = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await navigation.getByRole('button', { name: 'Comercial' }).click();
    await expect(navigation.getByRole('link', { name: 'Ingresos', exact: true }))
      .toHaveAttribute('href', '/adminpl0n3r/catalogo?vista=ingresos');
    await expect(panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' }))
      .toContainText('Empresa ficticia');
    await expect(panel.getByText('120.000', { exact: false }).first()).toBeVisible();
    await expect(panel.getByRole('table', { name: 'Totales de MRR por plan' })).toContainText('Inicial');
    await expect(panel.getByRole('table', { name: 'Totales de MRR por empresa' }))
      .toContainText('Empresa ficticia');
    if (process.env.CAPTURE_PLATFORM_1693_SCREENSHOT === 'true') {
      await page.screenshot({ path: 'docs/evidencia/plataforma-1693-ingresos.png', fullPage: true });
    }
    const movementRow = panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' })
      .getByRole('row').filter({ hasText: 'Empresa ficticia' });
    await movementRow.getByRole('button').click();
    await expect(page.getByRole('dialog', { name: 'Detalle del movimiento' })).toContainText('Inicial');
    expect(requests.length).toBeGreaterThan(0);
    expect(requests.every(method => method === 'GET')).toBe(true);
    await expect(page.locator('body')).not.toContainText('@example.test');
  });

  test('filtra por plan y muestra vacío verificable sin inventar movimientos', async ({ page }) => {
    const panel = await openRevenueFixture(page, route => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify(syntheticRevenueResponse(new URL(route.request().url()))),
    }));
    await expect(panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' }))
      .toContainText('Empresa ficticia');
    await panel.getByLabel('Plan').selectOption('starter');
    await expect(panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' }))
      .toContainText('Empresa ficticia');
    await panel.getByLabel('Plan').selectOption('');
    await panel.getByLabel('Desde').fill('2026-01-01');
    await panel.getByLabel('Hasta').fill('2026-01-31');
    await expect(panel.getByText('Sin movimientos para este periodo.')).toBeVisible();
    await expect(panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' })).toHaveCount(0);
    await expect(panel.getByText('$ 0', { exact: true }).first()).toBeVisible();
  });

  test('denegación 403 y evidencia incompleta nunca se presentan como ingresos cero', async ({ page }) => {
    let status = 403;
    const panel = await openRevenueFixture(page, route => route.fulfill({
      status, contentType: 'application/json',
      body: status === 403 ? '{}' : '{"status":"unavailable","reason":"incomplete_commercial_evidence"}',
    }));
    await expect(panel.getByRole('alert')).toContainText('No tienes permiso');
    await expect(panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' })).toHaveCount(0);
    status = 200;
    await panel.getByLabel('Desde').fill('2026-01-01');
    await expect(panel.getByRole('alert')).toContainText('evidencia comercial incompleta');
    await expect(panel.getByRole('table', { name: 'Movimientos de ingresos SaaS' })).toHaveCount(0);
  });
});
