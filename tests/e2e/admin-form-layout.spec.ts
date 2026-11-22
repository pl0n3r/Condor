
import { expect, test, type Page, type TestInfo } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

const settings = {
  'mail.provider': { configured: true, value: 'resend', last4: null, updated_at: '2026-11-21 12:00' },
  'mail.from': { configured: true, value: 'equipo@example.test', last4: null, updated_at: '2026-11-21 12:00' },
  'mail.api_key': { configured: false, last4: null, updated_at: null },
  'sentry.dsn': { configured: false, last4: null, updated_at: null },
};
async function login(page: Page) {
  await page.goto('/admin/login');
  await page.getByLabel('Correo').fill(email!);
  await page.getByLabel('Contraseña').fill(password!);
  await page.getByRole('button', { name: 'Ingresar' }).click();
  await expect(page).toHaveURL(/\/adminpl0n3r/);
}

async function checkViewport(page: Page, width: number) {
  const dimensions = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    viewport: document.documentElement.clientWidth,
  }));
  expect(dimensions.scroll, 'Sin desplazamiento horizontal a ' + width).toBeLessThanOrEqual(dimensions.viewport + 1);
}

/** Contraste WCAG de texto; opacity=1 evita diluir el botón deshabilitado. */
async function disabledButtonContrast(page: Page) {
  return page.getByRole('button', { name: 'Guardar clave' }).evaluate(button => {
    const style = getComputedStyle(button);
    function rgb(value: string) {
      const parts = value.match(/[\d.]+/g)?.slice(0, 3).map(Number) ?? [];
      if (parts.length !== 3) throw new Error('Color no RGB: ' + value);
      return parts.map(component => {
        const channel = component / 255;
        return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
      });
    }
    function luminance(value: string) {
      const [r, g, b] = rgb(value);
      return r * .2126 + g * .7152 + b * .0722;
    }
    const fg = luminance(style.color);
    const bg = luminance(style.backgroundColor);
    return { ratio: (Math.max(fg, bg) + .05) / (Math.min(fg, bg) + .05), opacity: style.opacity };
  });
}

test.describe('1930: formularios compactos, tarjetas y accesibilidad', () => {
  test.skip(!baseURL || !email || !password, 'Solo propietario sintético en E2E privado');

  test('Integraciones: 1440 y 390 px, grupos, estado, foco y contraste', async ({ page }, testInfo: TestInfo) => {
    await page.route('**/adminpl0n3r/api/integrations', route => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({
        settings, csrf_token: 'csrf-sintetico', secret_write_enabled: false, test_email_enabled: false,
      }),
    }));
    await login(page);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/adminpl0n3r/integraciones');
      await expect(page.getByRole('heading', { name: 'Integraciones', exact: true })).toBeVisible();
      const cards = page.locator('.ui-integrations-grid > .ui-card');
      await expect(cards).toHaveCount(4);
      await expect(page.locator('form.ui-integration-form')).toHaveCount(5);
      const sender = page.getByLabel('Remitente', { exact: true });
      const btn = page.getByRole('button', { name: 'Guardar remitente' });
      await expect(sender).toBeVisible();
      await expect(btn).toBeVisible();
      const inputWidth = await sender.evaluate(element => element.getBoundingClientRect().width);
      const buttonWidth = await btn.evaluate(element => element.getBoundingClientRect().width);
      const cardWidth = await cards.first().evaluate(element => element.getBoundingClientRect().width);
      expect(inputWidth).toBeLessThanOrEqual(48 * 16 + 1);
      if (width === 1440) {
        expect(buttonWidth).toBeLessThan(cardWidth * .75);
        expect(inputWidth).toBeLessThan(cardWidth);
      } else {
        await checkViewport(page, width);
        expect(inputWidth).toBeGreaterThan(cardWidth * .48);
        expect(buttonWidth).toBeLessThanOrEqual(cardWidth);
      }
      const status = page.locator('form[aria-label="Remitente de correo"] .ui-integration-status');
      await expect(status.getByText('Configurado')).toBeVisible();
      await expect(status).toContainText('Actualizado:');
      const statusRect = await status.evaluate(element => {
        const a = element.querySelector('.ui-pill')!.getBoundingClientRect();
        const b = Array.from(element.querySelectorAll('span')).find(node => node.textContent?.startsWith('Actualizado'))!.getBoundingClientRect();
        return { topDifference: Math.abs(a.top - b.top) };
      });
      if (width === 1440) expect(statusRect.topDifference).toBeLessThan(8);
      if (width === 1440) {
        // Reconstrucción CSS sintética del diseño anterior; no es captura
        // de un entorno real ni se usa como evidencia de despliegue.
        // CSSOM constructable solo para la captura sintética: nunca inyectar
        // <style> inline ni modificar la CSP restrictiva de la aplicación.
        const baseline = `
          .admin-shell .ui-integrations-grid { display: block !important; }
          .admin-shell .ui-integration-content { padding: 0 !important; display: block !important; }
          .admin-shell .ui-integration-form { border: 0 !important; padding: 0 !important; background: transparent !important; }
          .admin-shell .ui-integration-form .ui-form-actions > button { width: 100% !important; }
        `;
        await page.evaluate(css => {
          const sheet = new CSSStyleSheet();
          sheet.replaceSync(css);
          document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
        }, baseline);
        await testInfo.attach('integraciones-reconstruccion-anterior-1440', {
          body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
        });
        await page.evaluate(() => {
          document.adoptedStyleSheets = document.adoptedStyleSheets.slice(0, -1);
        });
      }
      await testInfo.attach('integraciones-nuevo-diseno-' + width, {
        body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
      });
    }
    const secret = page.getByRole('button', { name: 'Guardar clave' });
    await expect(secret).toBeDisabled();
    const contrast = await disabledButtonContrast(page);
    expect(contrast.opacity).toBe('1');
    expect(contrast.ratio).toBeGreaterThanOrEqual(4.5);
    // La interfaz usa outline de 3px (no box-shadow) para foco visible.
    await page.keyboard.press('Tab');
    const sender = page.getByLabel('Remitente', { exact: true });
    await sender.focus();
    await expect(sender).toBeFocused();
    const focus = await sender.evaluate(element => {
      const style = getComputedStyle(element);
      return { outlineWidth: parseFloat(style.outlineWidth), outlineStyle: style.outlineStyle };
    });
    expect(focus.outlineStyle).not.toBe('none');
    expect(focus.outlineWidth).toBeGreaterThanOrEqual(2);
  });

  test('Otras pantallas: Empresas y Catálogo conservan campos y botones legibles', async ({ page }, testInfo: TestInfo) => {
    await login(page);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      for (const [url, title] of [
        ['/adminpl0n3r/empresas', 'Empresas'],
        ['/adminpl0n3r/catalogo', 'Catálogo comercial'],
      ]) {
        await page.goto(url);
        await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
        const panel = page.locator('.admin-form-layout-page').first();
        await expect(panel).toBeVisible();
        const search = panel.locator('.ui-search input').first();
        await expect(search).toBeVisible();
        expect(await search.evaluate(input => input.getBoundingClientRect().width)).toBeLessThanOrEqual(48 * 16 + 1);
        const action = panel.locator('.ui-page-actions .button').first();
        await expect(action).toBeVisible();
        if (width === 1440) {
          const card = await panel.evaluate(element => element.getBoundingClientRect().width);
          expect(await action.evaluate(element => element.getBoundingClientRect().width)).toBeLessThan(card * .8);
        } else await checkViewport(page, width);
        await testInfo.attach(title.toLowerCase().replaceAll(' ', '-') + '-' + width, {
          body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
        });
      }
    }
  });
});
