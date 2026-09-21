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
});
