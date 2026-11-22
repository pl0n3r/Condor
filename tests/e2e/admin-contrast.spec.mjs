import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

// CSS real del producto, no estilos copiados en un mock. Solo los datos son sintéticos.
const adminCss = await readFile(new URL('../../frontend/admin/admin.css', import.meta.url), 'utf8');
const adminUiCss = await readFile(new URL('../../frontend/admin/admin-ui.css', import.meta.url), 'utf8');

function contrast(foreground, background) {
  const parse = (value) => {
    const channels = value.match(/[\d.]+/g)?.slice(0, 3).map(Number);
    if (!channels || channels.length !== 3) throw new Error('Color CSS desconocido: ' + value);
    return channels.map((v) => {
      const n = v / 255;
      return n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4;
    });
  };
  const luminance = (value) => {
    const [r, g, b] = parse(value);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const a = luminance(foreground), b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

async function colors(locator) {
  return locator.evaluate((element) => {
    const style = getComputedStyle(element);
    return { foreground: style.color, background: style.backgroundColor,
      opacity: Number(style.opacity), outlineStyle: style.outlineStyle,
      outlineWidth: style.outlineWidth, outlineColor: style.outlineColor,
      outlineOffset: style.outlineOffset };
  });
}

async function fixture(page, screen) {
  const labels = {
    Pedidos: 'Crear pedido', Empresas: 'Crear empresa',
    Integraciones: 'Guardar integración', Inventario: 'Guardar inventario',
  };
  if (!labels[screen]) throw new Error('Pantalla de prueba desconocida');
  const tenant = screen !== 'Empresas' ? ' admin-shell-tenant' : '';
  const submit = screen === 'Integraciones' ? ' type="submit"' : '';
  await page.setContent('<!doctype html><html lang="es"><head><meta charset="utf-8"></head>' +
    '<body style="margin:0"><div class="admin-shell' + tenant + '" data-screen="' + screen + '">' +
    '<main class="workspace"><h1>' + screen + '</h1>' +
    '<div class="ui-card" style="padding:24px;display:grid;gap:16px">' +
    '<div class="catalog-actions"><button id="primary" class="button"' + submit + '>' +
    labels[screen] + '</button> ' +
    '<button id="disabled" class="button" disabled>Acción no disponible</button></div>' +
    '<p class="alert catalog-error" id="inventory-error" role="alert">No fue posible cargar el inventario</p>' +
    '<p class="alert alert-error" id="error" role="alert">Revisa los datos</p>' +
    '<p class="alert alert-warning" id="warning" role="status">Verifica el estado</p>' +
    '<p class="alert alert-success" id="success" role="status">Guardado</p>' +
    '</div></main></div></body></html>');
  await page.addStyleTag({ content: adminCss });
  await page.addStyleTag({ content: adminUiCss });
}

for (const scheme of ['dark', 'light']) {
  test('contraste AA del CTA en Pedidos, Empresas e Integraciones (' + scheme + ')', async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme, reducedMotion: 'reduce' });
    for (const screen of ['Pedidos', 'Empresas', 'Integraciones']) {
      await fixture(page, screen);
      // El mouse permanece sobre el CTA tras setContent entre escenarios: medir normal sin hover residual.
      await page.mouse.move(0, 0);
      const primary = page.locator('#primary');
      const normal = await colors(primary);
      expect(contrast(normal.foreground, normal.background), screen + ': CTA normal').toBeGreaterThanOrEqual(4.5);
      await primary.hover();
      const hover = await colors(primary);
      expect(contrast(hover.foreground, hover.background), screen + ': CTA hover').toBeGreaterThanOrEqual(4.5);
      expect(hover.background, screen + ': hover diferenciado').not.toEqual(normal.background);

      await page.keyboard.press('Tab');
      await expect(primary).toBeFocused();
      const focus = await colors(primary);
      expect(focus.outlineStyle, screen + ': foco visible').toBe('solid');
      expect(parseFloat(focus.outlineWidth)).toBeGreaterThanOrEqual(2);
      expect(parseFloat(focus.outlineOffset), screen + ': separación visual del foco').toBeGreaterThanOrEqual(2);
      expect(contrast(focus.outlineColor, (await colors(page.locator('.workspace'))).background), screen + ': foco legible sobre superficie').toBeGreaterThanOrEqual(3);
      const disabled = await colors(page.locator('#disabled'));
      expect(disabled.opacity, screen + ': disabled distinguible').toBeLessThanOrEqual(0.65);
    }
  });

  test('mensajes de error, aviso y éxito legibles en Inventario (' + scheme + ')', async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme, reducedMotion: 'reduce' });
    await fixture(page, 'Inventario');
    for (const id of ['inventory-error', 'error', 'warning', 'success']) {
      const { foreground, background } = await colors(page.locator('#' + id));
      expect(contrast(foreground, background), id + ': contraste real').toBeGreaterThanOrEqual(4.5);
    }
  });
}

test('capturas de reconstrucción antes/después a 390 y 1440 px', async ({ page }, info) => {
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await fixture(page, 'Inventario');
    // Reconstrucción explícita del CSS defectuoso (no captura histórica de producción).
    const previous = await page.addStyleTag({ content:
      '.admin-shell .workspace .button { background: var(--z3) !important; color: #fff !important; }' +
      '.admin-shell .workspace .catalog-error { background: var(--z7) !important; color: #8c1d14 !important; }'
    });
    const before = info.outputPath('owner-support-before-condor1959-' + width + '.png');
    await page.screenshot({ path: before, fullPage: true });
    await info.attach('antes-' + width + '-reconstruccion', { path: before, contentType: 'image/png' });
    await previous.evaluate((element) => element.remove());
    const after = info.outputPath('owner-support-after-condor1959-' + width + '.png');
    await page.screenshot({ path: after, fullPage: true });
    await info.attach('despues-' + width, { path: after, contentType: 'image/png' });
    const { foreground, background } = await colors(page.locator('#inventory-error'));
    expect(contrast(foreground, background)).toBeGreaterThanOrEqual(4.5);
  }
});
