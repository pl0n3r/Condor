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
