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

    await page.getByRole('link', { name: 'Empresas' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/empresas$/);

    await page.getByLabel('Nombre comercial').fill(tenantName);
    await page.getByLabel('Slug').fill(tenantSlug);
    await page.getByLabel('Razón social').fill(tenantName + ' SAS');
    await page.getByLabel('Nombre del administrador')
      .fill('Administradora E2E');
    await page.getByLabel('Correo del administrador')
      .fill(tenantOwnerEmail);

    await page.getByRole('button', {
      name: 'Crear empresa e invitar admin',
    }).click();

    await expect(
      page.getByText(
        tenantName +
          ' fue creada. El administrador quedó pendiente de activación.',
      ),
    ).toBeVisible();
    await expect(
      page.locator('.activation-once').filter({
        hasText: 'Enlace de activación',
      }).first(),
    ).toBeVisible();

    await expect(
      page.locator('.tenant-card').filter({ hasText: tenantName }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Staff' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/staff$/);

    const staffSection = page.locator('.platform-section').filter({
      has: page.getByRole('heading', { name: 'Staff de plataforma' }),
    });

    await staffSection.getByLabel('Nombre', { exact: true })
      .fill('Staff E2E');
    await staffSection.getByLabel('Correo', { exact: true })
      .fill(staffEmail);
    await staffSection.getByLabel('Cliente').selectOption({
      label: tenantName,
    });
    await staffSection.getByLabel('Módulo').selectOption('catalog');

    const crud = staffSection.getByRole('group', {
      name: 'CRUD permitido',
    });
    await crud.getByRole('checkbox', { name: 'Ver' }).check();
    await crud.getByRole('checkbox', { name: 'Editar' }).check();

    await staffSection.getByRole('button', {
      name: 'Añadir permiso',
    }).click();

    await expect(
      staffSection.getByText('Catálogo · update, view'),
    ).toBeVisible();

    await staffSection.getByRole('button', {
      name: 'Crear staff e invitar',
    }).click();

    await expect(
      staffSection.getByText('Staff creado y pendiente de activación.'),
    ).toBeVisible();
    await expect(
      staffSection.locator('.staff-card').filter({ hasText: staffEmail }),
    ).toBeVisible();
    await expect(
      staffSection.locator('.staff-card').filter({ hasText: tenantName }),
    ).toContainText('Catálogo');
  });
});
