import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const ORDER = [
  'Escritorio', 'Ventas', 'Catálogo', 'Inventario', 'Operaciones',
  'Personal', 'Finanzas e informes', 'Sitio web', 'Empresa',
];
const REQUIRED_LINKS = [
  '/admin#commerce', '/admin#orders', '/admin#sales-reports',
  '/admin#reports-downloads', '/admin#catalog', '/admin#inventory',
  '/admin#stock-alerts', '/admin#stock-transfers', '/admin#purchasing',
  '/admin/sitio', '/admin#seo-settings', '/admin#workflows', '/admin#roles',
];

test.describe('Administrador — menú agrupado y módulos aislados', () => {
  test.skip(!baseURL || !email || !password,
    'Requiere instancia E2E y credenciales de tenant.');

  test('raíces aprobadas, enlaces únicos y rutas reales, a 390 y 1440', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      if (width === 390) {
        // Drawer nativo, el mismo navLabel en móvil y escritorio.
        await page.getByRole('button', { name: 'Mostrar menú' }).click();
      }
      const nav = page.locator(width === 390
        ? 'dialog.admin-nav-drawer nav[aria-label="Navegación principal"]'
        : 'aside.sidebar nav[aria-label="Navegación principal"]');
      const home = nav.getByRole('link', { name: 'Escritorio', exact: true });
      await expect(home).toBeVisible();
      await expect(home).toHaveAttribute('aria-current', 'page');
      const labels = await nav.locator(':scope > a, :scope > .nav-group > button')
        .allTextContents();
      const normalized = labels.map(label => label.trim());
      expect(new Set(normalized).size).toBe(normalized.length);
      expect(normalized.every(label => ORDER.includes(label))).toBe(true);
      expect(normalized).toEqual([...normalized].sort((a, b) =>
        ORDER.indexOf(a) - ORDER.indexOf(b)));
      const hrefs = await nav.locator('a[href]').evaluateAll(links =>
        links.map(link => link.getAttribute('href')));
      expect(new Set(hrefs).size).toBe(hrefs.length);
      for (const link of REQUIRED_LINKS) expect(hrefs).toContain(link);
      await expect(nav.getByRole('link', { name: 'Catálogo', exact: true })).toBeVisible();
      if (width === 390) {
        await page.keyboard.press('Escape');
      }
    }
    const desktopNav = page.getByRole('navigation', { name: 'Navegación principal' });
    await desktopNav.getByRole('link', { name: 'Catálogo', exact: true }).click();
    await expect(page).toHaveURL(/\/admin#catalog$/);
    await expect(desktopNav.getByRole('link', { name: 'Catálogo', exact: true }))
      .toHaveAttribute('aria-current', 'page');
    await expect(desktopNav.getByRole('link', { name: 'Escritorio', exact: true }))
      .not.toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.workspace > section#catalog')).toBeVisible();
    await expect(page.locator('.workspace > section#orders')).toHaveCount(0);
    await expect(page.locator('.workspace > section.module-directory')).toBeHidden();
    await desktopNav.getByRole('link', { name: 'Escritorio', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
  });

  test('empleados no disponible: evidencias sintéticas a 390 y 1440 px', async ({ page }, testInfo) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);

    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      const nav = page.getByRole('navigation', { name: 'Navegación principal' });
      await expect(nav.locator('a[href="/admin#employees-management"]')).toHaveCount(0);
      const enabled = await page.locator('#condor-admin-root').getAttribute('data-enabled-modules');
      expect((enabled || '').split(',')).not.toContain('employees-management');

      // Baseline ONLY for comparison: simulate the old link in the same shell.
      // No production view or entitlement is asserted by this screenshot.
      await page.evaluate(() => {
        const root = document.querySelector('#condor-admin-root');
        const navigation = document.querySelector('nav[aria-label="Navegación principal"]');
        const sample = navigation?.querySelector('.nav-group a.nav-sub');
        if (!root || !navigation || !sample) throw new Error('Navegación de prueba ausente');
        root.setAttribute('data-enabled-modules',
          `${root.getAttribute('data-enabled-modules') || ''},employees-management`);
        const oldLink = sample.cloneNode(true);
        oldLink.setAttribute('href', '/admin#employees-management');
        oldLink.setAttribute('data-synthetic-before', 'true');
        oldLink.textContent = 'Empleados y asistencia (referencia sintética)';
        navigation.appendChild(oldLink);
      });
      const beforePath = testInfo.outputPath(`employees-nav-before-${width}px.png`);
      await page.screenshot({ path: beforePath, fullPage: true });
      await testInfo.attach(`empleados-antes-sintetico-${width}px`, {
        path: beforePath, contentType: 'image/png',
      });
      await page.evaluate(() => {
        document.querySelector('[data-synthetic-before="true"]')?.remove();
        const root = document.querySelector('#condor-admin-root');
        if (root) root.setAttribute('data-enabled-modules',
          (root.getAttribute('data-enabled-modules') || '').split(',')
            .filter((key) => key !== 'employees-management').join(','));
      });
      await expect(nav.locator('a[href="/admin#employees-management"]')).toHaveCount(0);
      const afterPath = testInfo.outputPath(`employees-nav-after-${width}px.png`);
      await page.screenshot({ path: afterPath, fullPage: true });
      await testInfo.attach(`empleados-despues-${width}px`, {
        path: afterPath, contentType: 'image/png',
      });
    }
  });
  test('capturas navegador: taxonomía anterior sintética frente al menú de nueve áreas', async ({ page }, testInfo) => {
    // Una sola autenticación: /admin/login redirige a /admin si la sesión existe.
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
      await page.goto('/admin');
      await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
      if (width === 390) await page.getByRole('button', { name: 'Mostrar menú' }).click();
      const nav = page.locator(width === 390
        ? 'dialog.admin-nav-drawer nav[aria-label="Navegación principal"]'
        : 'aside.sidebar nav[aria-label="Navegación principal"]');
      await expect(nav).toBeVisible();

      // Reconstrucción explícitamente SINTÉTICA de los títulos anteriores de main@63d8b43.
      // Se conserva el DOM React original: sólo se oculta durante una captura.
      await nav.evaluate((element) => {
        for (const child of element.children) {
          child.setAttribute('data-real-nav-hidden', '');
          child.style.display = 'none';
        }
        const replica = document.createElement('div');
        replica.dataset.syntheticOldMenu = 'true';
        const notice = document.createElement('p');
        notice.textContent = 'REFERENCIA SINTÉTICA · menú anterior';
        notice.setAttribute('aria-label', 'Reconstrucción sintética de títulos anteriores');
        replica.append(notice);
        const home = document.createElement('a');
        home.href = '/admin';
        home.textContent = 'Inicio';
        home.setAttribute('aria-current', 'page');
        replica.append(home);
        for (const title of [
          'Ventas','Catálogo e inventario','Sitio web','Personas',
          'Finanzas y cumplimiento','Operación','Administración',
        ]) {
          const group = document.createElement('div');
          group.className = 'nav-group t-acc';
          const heading = document.createElement('button');
          heading.type = 'button';
          heading.className = 'nav-group-head t-acc-head';
          heading.textContent = title;
          group.append(heading);
          replica.append(group);
        }
        element.prepend(replica);
      });
      const before = testInfo.outputPath('tenant-nav-before-' + width + '.png');
      await page.screenshot({ path: before, fullPage: true });
      await testInfo.attach('menu-antes-sintetico-' + width, { path: before, contentType: 'image/png' });
      await nav.evaluate((element) => {
        element.querySelector('[data-synthetic-old-menu]')?.remove();
        for (const child of element.querySelectorAll('[data-real-nav-hidden]')) {
          child.removeAttribute('data-real-nav-hidden');
          child.style.removeProperty('display');
        }
      });
      const after = testInfo.outputPath('tenant-nav-after-' + width + '.png');
      await expect(nav.getByRole('link', { name: 'Escritorio', exact: true })).toBeVisible();
      await page.screenshot({ path: after, fullPage: true });
      await testInfo.attach('menu-despues-real-' + width, { path: after, contentType: 'image/png' });
      expect(await page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(width + 1);
      if (width === 390) await page.keyboard.press('Escape');
    }
  });

  test('deep-link, recarga, Back y Forward conservan el módulo de catálogo', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin#catalog');
    await expect(page.locator('.workspace > section#catalog')).toBeVisible();
    await page.reload();
    await expect(page.locator('.workspace > section#catalog')).toBeVisible();
    await page.locator('aside.sidebar nav a[href="/admin"]').click();
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
    await page.goBack();
    await expect(page.locator('.workspace > section#catalog')).toBeVisible();
    await page.goForward();
    await expect(page.locator('.workspace > section.module-directory')).toBeVisible();
  });
});
