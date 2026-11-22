import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const platformEmail = process.env.E2E_PLATFORM_EMAIL;
const platformPassword = process.env.E2E_PASSWORD;

async function login(page, email, password) {
  await page.goto('/admin/login');
  await page.getByLabel('Correo').fill(email);
  await page.getByLabel('Contraseña').fill(password);
  await page.getByRole('button', { name: 'Ingresar' }).click();
}

test.describe('Módulos por empresa y usuario — 1943', () => {
  test.skip(!baseURL || !platformEmail || !platformPassword,
    'Requiere propietario de plataforma y base de datos E2E sintética.');

  test('módulo inactivo devuelve 403, desaparece del menú y permite restaura acceso', async ({ page, browser }, testInfo) => {
    // Own isolated synthetic tenant/user. NEVER disable a shared E2E user's
    // inventory: a failure or retry would break subsequent legacy suites.
    const unique = Date.now().toString(36) + '-' + randomUUID().replaceAll('-', '').slice(0, 16);
    const tenantName = '000 Modulos E2E ' + unique;
    const tenantSlug = 'modulos-e2e-' + unique;
    const tenantEmail = 'modulos-owner-' + unique + '@example.test';
    const tenantPassword = 'Condor-E2e-Modules-' + unique + '-Aa!2';

    await login(page, platformEmail, platformPassword);
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    const csrf = await page.locator('#condor-platform-root').getAttribute('data-tenant-token');
    expect(csrf).toBeTruthy();

    const created = await page.request.post('/adminpl0n3r/api/tenants', {
      headers: { 'X-CSRF-Token': csrf },
      data: {
        name: tenantName,
        slug: tenantSlug,
        legal_name: tenantName + ' SAS',
        branch_name: 'Sede E2E',
        owner_email: tenantEmail,
        owner_name: 'Responsable E2E',
      },
    });
    expect(created.status()).toBe(201);
    const createdPayload = await created.json();
    const tenantId = createdPayload.tenant.id;
    const branchId = createdPayload.branch.id;
    const activationPath = createdPayload.invitation.activation_path_once;
    expect(activationPath).toMatch(/^\/activar-cuenta\/[a-f0-9]{64}$/);

    const member = await browser.newPage();
    try {
      await member.goto(activationPath);
      await member.getByLabel('Contraseña nueva').fill(tenantPassword);
      await member.getByLabel('Confirma la contraseña').fill(tenantPassword);
      await member.getByRole('button', { name: 'Activar cuenta' }).click();
      await login(member, tenantEmail, tenantPassword);
      await expect(member).toHaveURL(/\/admin$/);

      const api = '/adminpl0n3r/api/tenants/' + encodeURIComponent(tenantId) + '/modules';
      const inventoryEndpoint = '/api/v1/branches/' + encodeURIComponent(branchId) + '/inventory';
      const initial = await page.request.get(api);
      expect(initial.status()).toBe(200);
      const snapshot = await initial.json();
      const user = snapshot.users.find((entry) => entry.email === tenantEmail);
      expect(user).toBeTruthy();
      expect(snapshot.modules.some((entry) => entry.key === 'inventory')).toBe(true);

      await page.goto('/adminpl0n3r/empresas');
      const table = page.getByRole('table', { name: 'Empresas de esta página' });
      const row = table.getByRole('row').filter({ hasText: tenantName });
      for (let i = 0; i < 100 && !(await row.isVisible()); i++) {
        const nav = page.getByRole('navigation', { name: 'Paginación de empresas' });
        const next = nav.getByRole('button', { name: 'Siguiente' });
        if (await next.isDisabled()) break;
        const before = await nav.locator('span').textContent();
        await next.click();
        await expect(nav.locator('span')).not.toHaveText(before);
      }
      await expect(row).toBeVisible();
      await row.getByRole('button').first().click();
      await page.getByRole('dialog', { name: tenantName })
        .getByRole('button', { name: 'Ver como propietario' }).click();

      const modules = page.getByRole('region', { name: 'Módulos de la empresa' });
      await expect(modules.getByRole('heading', { name: 'Módulos', exact: true })).toBeVisible();
      const inventory = modules.getByRole('switch', { name: 'Activar Inventario para la empresa' });
      await expect(inventory).toBeChecked();

      for (const viewport of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
        await page.setViewportSize(viewport);
        await expect(inventory).toBeVisible();
        await page.screenshot({
          path: testInfo.outputPath('owner-support-before-' + viewport.width + '.png'),
          fullPage: true,
        });
      }

      await inventory.uncheck();
      await modules.getByRole('button', { name: 'Guardar cambios de empresa' }).click();
      await expect(modules.getByRole('status').filter({ hasText: 'Módulos de la empresa guardados' })).toBeVisible();
      await expect(inventory).not.toBeChecked();
      expect((await member.request.get(inventoryEndpoint)).status()).toBe(403);

      // Going from /admin to /admin#inventory changes ONLY the hash.
      // Reload is required to re-evaluate server-authoritative SSR access.
      await member.goto('/admin#inventory');
      await member.reload();
      await expect(member.getByRole('alert', { name: 'Módulo no habilitado' })).toBeVisible();
      const nav = member.getByRole('navigation', { name: 'Navegación principal' });
      await expect(nav.getByRole('link', { name: 'Inventario', exact: true })).toHaveCount(0);

      const search = modules.getByRole('searchbox', { name: 'Buscar usuario' });
      await search.fill(tenantEmail);
      await expect(modules.getByRole('table', { name: 'Permisos de módulos por usuario' })
        .getByRole('row').filter({ hasText: tenantEmail })).toBeVisible();

      for (const viewport of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
        await page.setViewportSize(viewport);
        const dimensions = await page.evaluate(() => ({
          scroll: document.documentElement.scrollWidth,
          width: document.documentElement.clientWidth,
        }));
        expect(dimensions.scroll, 'Sin scroll lateral del documento').toBeLessThanOrEqual(dimensions.width + 1);
        await page.screenshot({
          path: testInfo.outputPath('owner-support-after-' + viewport.width + '.png'),
          fullPage: true,
        });
      }

      await inventory.check();
      await modules.getByRole('button', { name: 'Guardar cambios de empresa' }).click();
      await expect(inventory).toBeChecked();

      const userCheckbox = modules.getByRole('checkbox', { name: 'Inventario para ' + user.name });
      await userCheckbox.uncheck();
      await modules.getByRole('button', { name: 'Guardar cambios de usuarios' }).click();
      await expect(userCheckbox).not.toBeChecked();
      expect((await member.request.get(inventoryEndpoint)).status()).toBe(403);
      await member.reload();
      await expect(member.getByRole('alert', { name: 'Módulo no habilitado' })).toBeVisible();

      await userCheckbox.check();
      await modules.getByRole('button', { name: 'Guardar cambios de usuarios' }).click();
      await expect(userCheckbox).toBeChecked();
      expect((await member.request.get(inventoryEndpoint)).status()).toBe(200);
      await member.reload();
      await expect(member.getByRole('alert', { name: 'Módulo no habilitado' })).toHaveCount(0);
      await expect(member.locator('#inventory').getByRole('heading', { name: 'Inventario', exact: true })).toBeVisible();
    } finally {
      await member.close();
    }
  });
});
