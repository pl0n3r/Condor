import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { seedWeirdData, assertDisposableBaseUrl } from './fixtures/weird-data-seed.mjs';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const password = process.env.E2E_PASSWORD;
const known = JSON.parse(readFileSync(new URL('./admin-all-pages-known.json', import.meta.url), 'utf8'));
if (known.version !== 1 || !Array.isArray(known.findings)) {
  throw new Error('Línea base #1958 inválida: se requiere version=1 y findings=[]');
}
const baseline = new Set();
for (const finding of known.findings) {
  if (typeof finding.key !== 'string' || !finding.key
    || !Number.isInteger(finding.issue) || finding.issue < 1 || baseline.has(finding.key)) {
    throw new Error('Cada hallazgo conocido debe tener clave única e issue real');
  }
  baseline.add(finding.key);
}

const ROLES = [
  { key: 'platform', email: process.env.E2E_PLATFORM_EMAIL,
    home: '/adminpl0n3r', navigation: 'Navegación de plataforma' },
  { key: 'tenant', email: process.env.E2E_EMAIL,
    home: '/admin', navigation: 'Navegación principal' },
];

export async function discoverMenuLinks(page, ariaLabel, home) {
  const nav = page.getByRole('navigation', { name: ariaLabel });
  await expect(nav).toHaveCount(1);
  const hrefs = await nav.locator('a[href]').evaluateAll((nodes) =>
    nodes.map((a) => a.getAttribute('href')).filter(Boolean));
  // The navigation DOM is the source of truth: no enumerated menu routes.
  const urls = [home];
  for (const href of hrefs) {
    const parsed = new URL(href, 'http://127.0.0.1');
    if (parsed.protocol !== 'http:' || parsed.origin !== 'http://127.0.0.1') continue;
    const path = parsed.pathname + parsed.search + parsed.hash;
    if (parsed.pathname !== home && !parsed.pathname.startsWith(home + '/')) continue;
    if (path.endsWith('/logout') || path.endsWith('/login')) continue;
    urls.push(path);
  }
  return [...new Set(urls)];
}

async function authenticate(page, role) {
  await page.goto('/admin/login');
  await page.getByLabel('Correo').fill(role.email);
  await page.getByLabel('Contraseña').fill(password);
  await page.getByRole('button', { name: 'Ingresar' }).click();
  await expect(page).toHaveURL(new RegExp(role.home.replaceAll('/', '\\/') + '$'));
}

async function inspectPage(page) {
  return page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const scrollWidth = Math.max(document.documentElement.scrollWidth, document.body.scrollWidth);
    const payloadExecuted = window.__condorRobustnessXss === true;
    const markupInjected = [...document.querySelectorAll('script, img[onerror]')]
      .some(el => el.outerHTML.includes('window.__condorRobustnessXss = true'));
    const textContent = document.body.innerText?.trim() ?? '';
    const messages = [...document.querySelectorAll('[role="alert"],[role="status"]')]
      .filter(el => el.getClientRects().length > 0)
      .map(el => ({ empty: !(el.textContent ?? '').trim(), tag: el.tagName }));
    const overflowingText = [...document.querySelectorAll('main *, .workspace *, .platform-section *')]
      .filter(el => el.children.length === 0 && el.getClientRects().length > 0)
      .filter(el => (el.textContent ?? '').length >= 256)
      .filter(el => {
        const style = getComputedStyle(el);
        // Long content must wrap or be deliberately clipped with ellipsis.
        const clipped = ['hidden', 'clip'].includes(style.overflowX)
          && style.textOverflow === 'ellipsis';
        const wrapped = ['anywhere', 'break-word'].includes(style.overflowWrap)
          || style.wordBreak === 'break-all';
        return el.scrollWidth > el.clientWidth + 2 && !clipped && !wrapped;
      }).slice(0, 5).map(el => ({ tag: el.tagName, className: String(el.className).slice(0,90) }));
    const color = (input) => {
      const numbers = input.match(/[\d.]+/g)?.map(Number) ?? [];
      if (numbers.length < 3) return null;
      const rgb = numbers.slice(0, 3).map(v => {
        const x = v / 255;
        return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
    };
    const opaqueBackground = (node) => {
      for (let el = node; el; el = el.parentElement) {
        const background = getComputedStyle(el).backgroundColor;
        if (background.startsWith('rgb(') || /rgba\([^)]*,\s*1(?:\.0+)?\)$/.test(background)) return background;
      }
      return 'rgb(255, 255, 255)';
    };
    const lowContrast = [...document.querySelectorAll(
      'button[type="submit"], button.btn-primary, button.primary, .ui-btn--primary, [data-variant="primary"]'
    )].filter(el => el.getClientRects().length > 0 && !el.disabled && !el.closest('[hidden], [inert]'))
      .map(el => {
        const fg = color(getComputedStyle(el).color);
        const bg = color(opaqueBackground(el));
        if (fg === null || bg === null) return null;
        return { name: (el.innerText || el.getAttribute('aria-label') || '').slice(0, 60),
          contrast: (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05) };
      }).filter(Boolean).filter(x => x.contrast < 4.5);
    return { width, scrollWidth, payloadExecuted, markupInjected, emptyBody: textContent.length === 0,
      blankMessages: messages.filter(x => x.empty), overflowingText, lowContrast };
  });
}

function checkFindings(findings) {
  const unexpected = findings.filter(f => !baseline.has(f.key));
  if (unexpected.length) {
    throw new Error('Hallazgos nuevos de robustez #1958 (cada uno debe corregirse o tener issue): '
      + JSON.stringify(unexpected.slice(0, 25), null, 2));
  }
}

test('descubrimiento dinámico cubre un enlace añadido sin editar la prueba', async ({ page }) => {
  await page.setContent('<nav aria-label="Navegación principal"><a href="/admin">Inicio</a>'
    + '<a href="/admin#catalog">Catálogo</a></nav>');
  const before = await discoverMenuLinks(page, 'Navegación principal', '/admin');
  expect(before).toContain('/admin#catalog');
  await page.locator('nav').evaluate(nav => {
    const a = document.createElement('a');
    a.href = '/admin#modulo-agregado-sin-editar-test';
    a.textContent = 'Módulo nuevo';
    nav.append(a);
  });
  for (const href of [
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'javascript:alert(1)',
    'https://evil.example/admin',
  ]) {
    await page.locator('nav').evaluate((nav, value) => {
      const a = document.createElement('a'); a.href = value; a.textContent = 'No permitido'; nav.append(a);
    }, href);
  }
  const after = await discoverMenuLinks(page, 'Navegación principal', '/admin');
  expect(after).toContain('/admin#modulo-agregado-sin-editar-test');
  expect(after).not.toContain('data:text/html,<script>alert(1)</script>');
  expect(after).not.toContain('vbscript:msgbox(1)');
  expect(after).not.toContain('javascript:alert(1)');
  expect(after).not.toContain('https://evil.example/admin');
  expect(after.length).toBe(before.length + 1);
});

for (const role of ROLES) {
  test('barrido autenticado ' + role.key + ' · menú dinámico, 1440/390 y capturas', async ({ page }, testInfo) => {
    test.setTimeout(15 * 60_000);
    // Missing configuration must not turn the production-level acceptance into
    // an incorrectly green, skipped test in GitHub Actions.
    expect(baseURL, 'Instancia E2E obligatoria').toBeTruthy();
    expect(password, 'Password E2E obligatorio').toBeTruthy();
    expect(role.email, 'Cuenta E2E obligatoria: ' + role.key).toBeTruthy();
    assertDisposableBaseUrl(baseURL);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.addInitScript(() => { window.__condorRobustnessXss = false; });
    await authenticate(page, role);
    const seed = await seedWeirdData(page, role.key, baseURL);
    expect(seed.length).toBeGreaterThan(0);
    const links = await discoverMenuLinks(page, role.navigation, role.home);
    expect(links.length, 'No se descubrieron enlaces de menú del panel').toBeGreaterThan(1);

    const findings = [];
    for (const viewport of [1440, 390]) {
      await page.setViewportSize({ width: viewport, height: 900 });
      for (const [index, path] of links.entries()) {
        const events = [];
        const networkErrors = new Set();
        const error = message => events.push({ kind: 'console', detail: 'console: ' + message.text().slice(0, 300) });
        const uncaught = exception => events.push({ kind: 'javascript', detail: 'javascript: ' + exception.message.slice(0, 300) });
        const responseError = response => {
          const status = response.status();
          const responseUrl = new URL(response.url());
          if (status < 400 || responseUrl.origin !== new URL(baseURL).origin) return;
          const pathSegments = responseUrl.pathname.split('/');
          const branchSegment = pathSegments.indexOf('branches');
          if (branchSegment >= 0 && pathSegments[branchSegment + 1]) {
            pathSegments[branchSegment + 1] = '{branchId}';
          }
          networkErrors.add('http: ' + status + ' ' + pathSegments.join('/'));
        };
        page.on('console', message => { if (message.type() === 'error') error(message); });
        page.on('pageerror', uncaught);
        page.on('response', responseError);
        // Store a stable screenshot name accepted by the existing always-upload
        // owner-support-visual CI artifact, not only the on-failure reporter.
        const screenshot = testInfo.outputPath('owner-support-before-condor1958-'
          + role.key + '-' + viewport + '-' + index + '.png');
        try {
          const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
          await expect(page.locator('body')).toBeVisible();
          if (/\/admin\/login(?:$|\?)/.test(new URL(page.url()).pathname)) {
            events.push({ kind: 'auth', detail: 'auth: redirigió a login en ' + path });
          }
          if (response && response.status() >= 500) events.push('http: documento ' + response.status());
          // React panels render after DOMContentLoaded: observe settling without
          // relying on a fixed sleep or silently suppressing console failures.
          await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => null);
          const metrics = await inspectPage(page);
          const eventGroups = new Map();
          for (const event of events) {
            const status = event.detail.match(/Failed to load resource: the server responded with a status of ([0-9]{3})/)?.[1];
            const kind = status ? 'console-http-' + status : event.kind;
            if (!eventGroups.has(kind)) eventGroups.set(kind, []);
            eventGroups.get(kind).push(event.detail);
          }
          const checks = [
            ...eventGroups.entries(),
            ['xss', metrics.payloadExecuted ? ['payload ejecutado'] : []],
            ['unsafe-dom', metrics.markupInjected ? ['payload HTML no escapado en el DOM'] : []],
            ['horizontal', metrics.scrollWidth > viewport + 1
              ? ['scrollWidth=' + metrics.scrollWidth + ', viewport=' + viewport] : []],
            ['content', metrics.emptyBody ? ['documento vacío'] : []],
            ['messages', metrics.blankMessages],
            ['longtext', metrics.overflowingText],
            ['contrast', metrics.lowContrast],
          ];
          for (const [kind, observed] of checks) {
            if (observed.length) findings.push({ key: role.key + '|' + viewport + '|' + path + '|' + kind,
              detail: observed.slice(0, 5) });
          }
          for (const detail of networkErrors) {
            const match = /^http: (\d{3}) (.+)$/.exec(detail);
            if (!match) throw new Error('Hallazgo HTTP sin status/path: ' + detail);
            const key = role.key + '|' + viewport + '|http-' + match[1] + '|' + match[2];
            if (!findings.some(finding => finding.key === key)) findings.push({ key, detail: [detail] });
          }
        } finally {
          await page.screenshot({ path: screenshot, fullPage: true });
          page.removeAllListeners('console');
          page.removeListener('pageerror', uncaught);
          page.removeListener('response', responseError);
        }
      }
    }
    await testInfo.attach('barrido-' + role.key + '.json', {
      body: JSON.stringify({ role: role.key, links, seed, findings }, null, 2),
      contentType: 'application/json',
    });
    checkFindings(findings);
  });
}

test('estados vacío y error se distinguen con mensajes visibles, sin datos reales', async ({ page }) => {
  expect(baseURL).toBeTruthy();
  expect(password).toBeTruthy();
  expect(process.env.E2E_PLATFORM_EMAIL).toBeTruthy();
  assertDisposableBaseUrl(baseURL);
  await authenticate(page, ROLES[0]);
  const empty = { staff: [], catalog: [], tenant_options: [], tenant_options_truncated: false };
  await page.route('**/adminpl0n3r/api/staff', route => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify(empty),
  }));
  await page.goto('/adminpl0n3r/staff');
  await expect(page.getByText('Aún no hay staff de plataforma.')).toBeVisible();
  await page.unroute('**/adminpl0n3r/api/staff');
  await page.route('**/adminpl0n3r/api/staff', route => route.fulfill({
    status: 503, contentType: 'application/json', body: '{"error":"simulated"}',
  }));
  await page.reload();
  await expect(page.getByRole('alert')).toContainText('No fue posible cargar');
});
