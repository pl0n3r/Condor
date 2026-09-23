import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Slice 2 — roles y permisos por sede', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E ejecutándose y usuario propietario del tenant.'
  );

  test('crea un rol con permisos desde el backoffice y lo conserva', async ({ page }, testInfo) => {
    const roleName = `Operador catálogo E2E ${Date.now()}-${testInfo.retry}`;
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(
      page.getByRole('heading', { name: 'Roles y permisos' })
    ).toBeVisible();

    await page.getByLabel('Nombre del rol').fill(roleName);

    const catalog = page.getByRole('group', { name: 'Catálogo' });
    await catalog.getByRole('checkbox', { name: 'Ver' }).check();
    await catalog.getByRole('checkbox', { name: 'Editar' }).check();

    await page.getByRole('button', { name: 'Crear rol' }).click();

    await expect(page.getByText('Rol creado.')).toBeVisible();
    await expect(
      page.locator('.role-card').filter({ hasText: roleName })
    ).toBeVisible();

    await page.reload();
    await expect(
      page.locator('.role-card').filter({ hasText: roleName })
    ).toBeVisible();

    const context = await page.request.get('/api/v1/context');
    expect(context.ok()).toBeTruthy();
    const contextPayload = await context.json();

    const roles = await page.request.get(
      '/api/v1/branches/' + contextPayload.active_branch.id + '/roles'
    );
    expect(roles.ok()).toBeTruthy();
    const rolePayload = await roles.json();
    const role = rolePayload.roles.find(
      (candidate) => candidate.name === roleName
    );

    expect(role).toBeTruthy();
    expect(role.permissions).toEqual([
      'catalog.update',
      'catalog.view',
    ]);
  });

  test('cambia razón social y acota las sedes visibles', async ({ page }) => {
    const branchA = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    const branchB = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
    const legalA = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
    const legalB = '01ARZ3NDEKTSV4RRFFQ69G5FAY';

    await page.route('**/api/v1/context**', async (route) => {
      const url = new URL(route.request().url());
      const activeId = url.searchParams.get('branch') === branchB
        ? branchB
        : branchA;
      const activeLegalId = activeId === branchB ? legalB : legalA;
      const branches = [
        {
          id: branchA,
          name: 'Tienda',
          slug: 'tienda',
          is_default: true,
          legal_entity: { id: legalA, name: 'Comercial SAS' },
        },
        {
          id: branchB,
          name: 'Fábrica',
          slug: 'fabrica',
          is_default: false,
          legal_entity: { id: legalB, name: 'Industrial SAS' },
        },
      ];

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          tenant: {
            id: '01ARZ3NDEKTSV4RRFFQ69G5FAZ',
            name: 'Empresa E2E',
            slug: 'empresa-e2e',
          },
          legal_entities: [
            { id: legalA, name: 'Comercial SAS' },
            { id: legalB, name: 'Industrial SAS' },
          ],
          active_legal_entity: activeLegalId === legalA
            ? { id: legalA, name: 'Comercial SAS' }
            : { id: legalB, name: 'Industrial SAS' },
          branches,
          active_branch: branches.find(({ id }) => id === activeId),
          permissions: [],
          version: 'V 0.1.22',
        }),
      });
    });

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByLabel('Razón social activa')).toBeVisible();
    await expect(page.getByLabel('Sede activa')).toHaveValue(branchA);
    await expect(
      page.getByLabel('Sede activa').getByRole('option'),
    ).toHaveCount(1);

    await page.getByLabel('Razón social activa').selectOption(legalB);

    await expect(page.getByLabel('Sede activa')).toHaveValue(branchB);
    await expect(
      page.getByLabel('Sede activa').getByRole('option'),
    ).toHaveCount(1);
    await expect(
      page.getByLabel('Sede activa').getByRole('option'),
    ).toHaveText('Fábrica');
  });

  test('oculta selector jurídico cuando solo hay una razón social', async ({ page }) => {
    const branchId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    const legalId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

    await page.route('**/api/v1/context**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          tenant: {
            id: '01ARZ3NDEKTSV4RRFFQ69G5FAZ',
            name: 'Empresa E2E',
            slug: 'empresa-e2e',
          },
          legal_entities: [{ id: legalId, name: 'Comercial SAS' }],
          active_legal_entity: { id: legalId, name: 'Comercial SAS' },
          branches: [{
            id: branchId,
            name: 'Tienda',
            slug: 'tienda',
            is_default: true,
            legal_entity: { id: legalId, name: 'Comercial SAS' },
          }],
          active_branch: {
            id: branchId,
            name: 'Tienda',
            slug: 'tienda',
            is_default: true,
            legal_entity: { id: legalId, name: 'Comercial SAS' },
          },
          permissions: [],
          version: 'V 0.1.22',
        }),
      });
    });

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByLabel('Razón social activa')).toHaveCount(0);
    await expect(page.getByLabel('Sede activa')).toHaveValue(branchId);
  });
});
