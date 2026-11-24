import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Propietario — soporte temporal por empresa', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere entorno E2E sintético con propietario autenticado.',
  );

  test('desde Empresas abre Talento humano, conserva el actor y vuelve a la consola', async ({ page }, testInfo) => {
    const unique = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 7);
    const tenantName = '000 Soporte E2E ' + unique;
    const tenantSlug = 'support-e2e-' + unique;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);

    const csrf = await page.locator('#condor-platform-root').getAttribute('data-tenant-token');
    expect(csrf).toBeTruthy();
    const created = await page.request.post('/adminpl0n3r/api/tenants', {
      headers: { 'X-CSRF-Token': csrf },
      data: {
        name: tenantName,
        slug: tenantSlug,
        legal_name: tenantName + ' SAS',
        branch_name: 'Sede soporte',
        owner_email: 'owner-' + unique + '@example.test',
        owner_name: 'Owner sintético',
      },
    });
    expect(created.status()).toBe(201);

    await page.goto('/adminpl0n3r/empresas');

    // The "before" screenshots show the owner choosing a company, not a
    // fabricated historical design comparison. All data is synthetic.
    for (const size of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
      await page.setViewportSize(size);
      await page.screenshot({
        path: testInfo.outputPath('owner-support-before-' + size.width + '.png'),
        fullPage: true,
      });
    }
    await page.setViewportSize({ width: 1280, height: 720 });

    const table = page.getByRole('table', { name: 'Empresas de esta página' });
    const row = table.getByRole('row').filter({ hasText: tenantName });
    for (let pageNumber = 0; pageNumber < 100 && !(await row.isVisible()); pageNumber++) {
      const pagination = page.getByRole('navigation', { name: 'Paginación de empresas' });
      const next = pagination.getByRole('button', { name: 'Siguiente' });
      if (await next.isDisabled()) break;
      const before = await pagination.locator('span').textContent();
      await next.click();
      await expect(pagination.locator('span')).not.toHaveText(before);
    }
    await expect(row).toBeVisible();
    // The table row is not an interactive target: open its accessible first-cell button.
    await row.getByRole('button').first().click();

    const dialog = page.getByRole('dialog', { name: tenantName });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Ver como propietario' }).click();
    const companyNav = page.getByRole('navigation', { name: 'Navegación empresarial del propietario' });
    await expect(companyNav).toBeVisible();
    // The approved catalogue has nine real roots and only one expanded group.
    for (const root of [
      'Escritorio', 'Ventas', 'Catálogo', 'Inventario', 'Operaciones',
      'Personal', 'Finanzas e informes', 'Sitio web', 'Empresa',
    ]) {
      await expect(companyNav.getByRole('button', { name: root, exact: true })).toHaveCount(1);
    }
    const sales = companyNav.getByRole('button', { name: 'Ventas', exact: true });
    const inventory = companyNav.getByRole('button', { name: 'Inventario', exact: true });
    const people = companyNav.getByRole('button', { name: 'Personal', exact: true });
    await sales.click();
    await expect(sales).toHaveAttribute('aria-expanded', 'true');
    await expect(companyNav.getByRole('button', { name: 'Pedidos', exact: true })).toBeVisible();
    await inventory.click();
    await expect(sales).toHaveAttribute('aria-expanded', 'false');
    await expect(inventory).toHaveAttribute('aria-expanded', 'true');
    await people.click();
    await expect(inventory).toHaveAttribute('aria-expanded', 'false');
    await page.keyboard.press('Escape');
    await expect(people).toHaveAttribute('aria-expanded', 'false');
    await expect(people).toBeFocused();
    await people.click();
    const grant = page.waitForRequest((request) =>
      request.url().endsWith('/adminpl0n3r/api/support') && request.method() === 'POST');
    await companyNav.getByRole('button', { name: 'Talento humano', exact: true }).click();
    const payload = (await grant).postDataJSON();
    expect(payload.module).toBe('human-resources-management');
    expect(typeof payload.tenant_id).toBe('string');
    await expect(page).toHaveURL(/\/admin#human-resources-management$/);
    const supportBanner = page.getByRole('status').filter({ hasText: 'Soporte de propietario' });
    await expect(supportBanner).toContainText(tenantName);
    // The sticky banner occupies normal flow and must fit narrow and wide
    // viewports. Store screenshots as real, reviewable CI artifacts.
    for (const size of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
      await page.setViewportSize(size);
      await expect(supportBanner).toBeVisible();
      expect(await supportBanner.evaluate((element) => getComputedStyle(element).position))
        .toBe('sticky');
      const bounds = await supportBanner.boundingBox();
      expect(bounds).not.toBeNull();
      expect(bounds.x).toBeGreaterThanOrEqual(0);
      expect(bounds.x + bounds.width).toBeLessThanOrEqual(size.width + 1);
      // At 390px the support banner must be visible without scrolling.
      expect(bounds.y).toBeLessThan(size.height);
      const menuToggle = page.getByRole('button', { name: 'Mostrar menú' });
      const mobileNav = page.getByRole('navigation', { name: 'Navegación principal' });
      if (size.width === 390) {
        await expect(menuToggle).toBeVisible();
        await expect(menuToggle).toHaveAttribute('aria-expanded', 'false');
        await expect(mobileNav).toBeHidden();
        await menuToggle.click();
        await expect(page.getByRole('button', { name: 'Ocultar menú' }))
          .toHaveAttribute('aria-expanded', 'true');
        await expect(mobileNav).toBeVisible();
        await page.getByRole('button', { name: 'Ocultar menú' }).click();
        await expect(mobileNav).toBeHidden();
      } else {
        await expect(menuToggle).toBeHidden();
        await expect(mobileNav).toBeVisible();
      }
      await page.screenshot({
        path: testInfo.outputPath('owner-support-after-' + size.width + '.png'),
        fullPage: true,
      });
    }
    await page.setViewportSize({ width: 1280, height: 720 });
    const adminNav = page.getByRole('navigation', { name: 'Navegación principal' });
    await expect(adminNav.getByRole('button', { name: 'Personal', exact: true })).toBeVisible();
    await expect(adminNav.getByRole('button', { name: 'Empresa', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Volver a la consola' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
  });


  test('cambiar de empresa descarta la sede del tenant anterior antes de dar soporte', async ({ page }) => {
    const unique = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 7);
    const tenantA = '000 Soporte A ' + unique;
    const tenantB = '000 Soporte B ' + unique;

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    const csrf = await page.locator('#condor-platform-root').getAttribute('data-tenant-token');
    expect(csrf).toBeTruthy();

    for (const [name, part] of [[tenantA, 'a'], [tenantB, 'b']]) {
      const created = await page.request.post('/adminpl0n3r/api/tenants', {
        headers: { 'X-CSRF-Token': csrf },
        data: {
          name,
          slug: 'support-change-' + part + '-' + unique,
          legal_name: name + ' SAS',
          branch_name: 'Sede de ' + part,
          owner_email: 'owner-' + part + '-' + unique + '@example.test',
          owner_name: 'Propietario sintético',
        },
      });
      expect(created.status()).toBe(201);
    }

    await page.goto('/adminpl0n3r/empresas');
    async function selectCompany(name) {
      const row = page.getByRole('table', { name: 'Empresas de esta página' })
        .getByRole('row').filter({ hasText: name });
      for (let i = 0; i < 100 && !(await row.isVisible()); i++) {
        const pagination = page.getByRole('navigation', { name: 'Paginación de empresas' });
        const next = pagination.getByRole('button', { name: 'Siguiente' });
        if (await next.isDisabled()) break;
        const before = await pagination.locator('span').textContent();
        await next.click();
        await expect(pagination.locator('span')).not.toHaveText(before);
      }
      await expect(row).toBeVisible();
      await row.getByRole('button').first().click();
      await page.getByRole('dialog', { name })
        .getByRole('button', { name: 'Ver como propietario' }).click();
      await expect(page.getByLabel('Sede de soporte')).toBeVisible();
    }

    await selectCompany(tenantA);
    const branchSelector = page.getByLabel('Sede de soporte');
    const branchA = await branchSelector.locator('option').nth(1).getAttribute('value');
    expect(branchA).toBeTruthy();
    await branchSelector.selectOption(branchA);
    await page.getByRole('button', { name: 'Volver al centro global' }).click();
    await expect(page.locator('.platform-context-bar')).toHaveCount(0);

    await selectCompany(tenantB);
    const selectorB = page.getByLabel('Sede de soporte');
    const branchB = await selectorB.locator('option').nth(1).getAttribute('value');
    expect(branchB).toBeTruthy();
    expect(branchB).not.toBe(branchA);
    await expect(selectorB).toHaveValue('');

    const outgoing = page.waitForRequest((request) =>
      request.url().endsWith('/adminpl0n3r/api/support') && request.method() === 'POST',
    );
    await page.locator('.platform-context-bar')
      .getByRole('button', { name: 'Abrir panel de la empresa' }).click();
    const payload = (await outgoing).postDataJSON();
    expect(payload.branch_id).toBe(branchB);
    expect(payload.branch_id).not.toBe(branchA);
    await expect(page).toHaveURL(/\/admin$/);
  });
});
