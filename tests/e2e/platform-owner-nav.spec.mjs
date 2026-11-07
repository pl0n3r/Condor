import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

const GROUPS = [
  {
    name: 'Resumen',
    links: ['/adminpl0n3r'],
  },
  {
    name: 'Empresas',
    links: ['/adminpl0n3r/empresas'],
  },
  {
    name: 'Comercial',
    links: [
      '/adminpl0n3r/planes',
      '/adminpl0n3r/suscripciones',
      '/adminpl0n3r/catalogo',
    ],
  },
  {
    name: 'Plataforma',
    links: [
      '/adminpl0n3r/staff',
      '/adminpl0n3r/diagnosticos',
    ],
  },
];

const EXPECTED_LINKS = GROUPS.flatMap(({ links }) => links);

test.describe('Propietario — navegación agrupada sin enlaces inexistentes', () => {
  test.skip(
    !baseURL || !email || !password,
    'Requiere aplicación E2E y credenciales de propietario.',
  );

  test('acordeones accesibles, siete enlaces funcionales y sección activa', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/\/adminpl0n3r$/);

    const nav = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await expect(nav.locator('button.nav-group-head')).toHaveCount(GROUPS.length);

    for (const { name, links } of GROUPS) {
      const group = nav.locator('.nav-group').filter({
        has: page.getByRole('button', { name, exact: true }),
      });
      await expect(group).toHaveCount(1);
      const button = group.getByRole('button', { name, exact: true });
      await expect(button).toBeVisible();
      const hrefs = await group.locator('a.nav-sub').evaluateAll(
        (anchors) => anchors.map((anchor) => anchor.getAttribute('href')),
      );
      expect(hrefs).toEqual(links);
    }

    const allHrefs = await nav.locator('a.nav-sub').evaluateAll(
      (anchors) => anchors.map((anchor) => anchor.getAttribute('href')),
    );
    expect(allHrefs).toEqual(EXPECTED_LINKS);
    expect(new Set(allHrefs).size).toBe(allHrefs.length);

    // No crear grupos o rutas de propietario para módulos sin pantalla.
    for (const absent of ['Cumplimiento', 'Soporte', 'Ingresos', 'Auditoría', 'Estado del sistema']) {
      await expect(nav.getByRole('button', { name: absent, exact: true })).toHaveCount(0);
    }

    const summary = nav.getByRole('button', { name: 'Resumen', exact: true });
    await expect(summary).toHaveAttribute('aria-expanded', 'true');
    await expect(nav.locator('a.nav-sub[href="/adminpl0n3r"]'))
      .toHaveAttribute('aria-current', 'page');

    const commercial = nav.getByRole('button', { name: 'Comercial', exact: true });
    const panel = commercial.locator('..').locator('.t-acc-panel');
    await expect(commercial).toHaveAttribute('aria-expanded', 'false');
    await expect(panel).toHaveAttribute('inert', '');
    await commercial.click();
    await expect(commercial).toHaveAttribute('aria-expanded', 'true');
    await expect(panel).not.toHaveAttribute('inert', '');

    // Comprobar destinos HTTP con la misma sesión del propietario:
    // no aceptar redirecciones a login como falso enlace funcional.
    for (const href of EXPECTED_LINKS) {
      const response = await page.request.get(href, { maxRedirects: 0 });
      expect(response.status(), href).toBe(200);
      expect(new URL(response.url()).pathname, href).toBe(href);
    }

    await nav.locator('a.nav-sub[href="/adminpl0n3r/suscripciones"]').click();
    await expect(page).toHaveURL(/\/adminpl0n3r\/suscripciones$/);
    const subscriptions = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await expect(subscriptions.getByRole('button', { name: 'Comercial' }))
      .toHaveAttribute('aria-expanded', 'true');
    await expect(subscriptions.locator('a.nav-sub[href="/adminpl0n3r/suscripciones"]'))
      .toHaveAttribute('aria-current', 'page');
    const breadcrumb = page.getByRole('navigation', { name: 'Ruta' });
    await expect(breadcrumb).toContainText('Comercial');
    await expect(breadcrumb).toContainText('Plan y suscripciones');
    await expect(breadcrumb.getByRole('link', { name: 'Inicio' }))
      .toHaveAttribute('href', '/adminpl0n3r');
  });
});
