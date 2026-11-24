import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

const DIRECT = [
  { href: '/adminpl0n3r', name: 'Escritorio global' },
  { href: '/adminpl0n3r/empresas', name: 'Empresas' },
];
const GROUPS = [
  { name: 'Negocio SaaS', links: [
    '/adminpl0n3r/catalogo?vista=planes',
    '/adminpl0n3r/suscripciones',
    '/adminpl0n3r/catalogo',
    '/adminpl0n3r/catalogo?vista=ingresos',
  ] },
  { name: 'Administración de plataforma', links: [
    '/adminpl0n3r/staff',
    '/adminpl0n3r/integraciones',
    '/adminpl0n3r/diagnosticos',
  ] },
];
const ALL_LINKS = [...DIRECT.map(item => item.href), ...GROUPS.flatMap(group => group.links)];

test.describe('Propietario — solo destinos reales y cuatro raíces', () => {
  test.skip(!baseURL || !email || !password,
    'Requiere entorno E2E sintético con propietario.');

  test('enlaces reales, grupo exclusivo, foco y breadcrumb', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    const nav = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await expect(nav.locator('button.nav-group-head')).toHaveCount(2);
    for (const { href, name } of DIRECT) {
      await expect(nav.getByRole('link', { name, exact: true })).toHaveAttribute('href', href);
    }
    await expect(nav.getByRole('link', { name: 'Escritorio global' }))
      .toHaveAttribute('aria-current', 'page');
    for (const { name, links } of GROUPS) {
      const group = nav.locator('.nav-group').filter({
        has: page.getByRole('button', { name, exact: true }),
      });
      await expect(group).toHaveCount(1);
      const hrefs = await group.locator('a.nav-sub').evaluateAll(
        (nodes) => nodes.map(node => node.getAttribute('href')));
      expect(hrefs).toEqual(links);
    }
    const all = await nav.locator('a[href]').evaluateAll(
      nodes => nodes.map(node => node.getAttribute('href')));
    expect(all).toEqual(ALL_LINKS);
    expect(new Set(all).size).toBe(all.length);
    await expect(nav.getByRole('button', { name: 'Módulos de empresa' })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Auditoría' })).toHaveCount(0);

    const saas = nav.getByRole('button', { name: 'Negocio SaaS' });
    const platform = nav.getByRole('button', { name: 'Administración de plataforma' });
    await saas.click();
    await expect(saas).toHaveAttribute('aria-expanded', 'true');
    await platform.click();
    await expect(saas).toHaveAttribute('aria-expanded', 'false');
    await expect(platform).toHaveAttribute('aria-expanded', 'true');
    await expect(saas.locator('..').locator('.t-acc-panel')).toHaveAttribute('inert', '');

    for (const href of ALL_LINKS) {
      const response = await page.request.get(href, { maxRedirects: 0 });
      expect(response.status(), href).toBe(200);
      const finalURL = new URL(response.url());
      expect(finalURL.pathname + finalURL.search, href).toBe(href);
    }
    await page.goto('/adminpl0n3r/catalogo?vista=ingresos');
    await expect(page.getByRole('heading', { name: 'Ingresos SaaS', exact: true })).toBeVisible();
    const revenueNav = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await expect(revenueNav.getByRole('button', { name: 'Negocio SaaS' }))
      .toHaveAttribute('aria-expanded', 'true');
    await expect(revenueNav.locator('a[href="/adminpl0n3r/catalogo?vista=ingresos"]'))
      .toHaveAttribute('aria-current', 'page');
    await page.goto('/adminpl0n3r/suscripciones');
    const crumbs = page.getByRole('navigation', { name: 'Ruta' });
    await expect(crumbs).toContainText('Negocio SaaS');
    await expect(crumbs).toContainText('Suscripciones');
    await expect(crumbs.getByRole('link', { name: 'Inicio' }))
      .toHaveAttribute('href', '/adminpl0n3r');
  });
});
