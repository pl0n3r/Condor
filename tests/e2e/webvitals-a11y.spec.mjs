import { test, expect } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

// #1982: CI disposable, real Symfony routes, real Chromium/WebKit, no production.
// axe-core is installed at its *pinned* version by webvitals-a11y.yml.
// A missing browser API or unobserved interaction is NEVER turned into 0 ms.
const LIMITS = Object.freeze({ lcp_ms: 4000, cls: 0.25, inp_ms: 500 });
const FLOWS = ['publico', 'tenant', 'plataforma'];
const HEAD = process.env.VITALS_HEAD_SHA;
const BASE = process.env.PLAYWRIGHT_BASE_URL;
const supportedURL = /^http:\/\/(?:127\.0\.0\.1|localhost):8080\/?$/;

function ensureDisposable() {
  if (!supportedURL.test(BASE ?? '') ||
      !/^[0-9a-f]{40}$/.test(HEAD ?? '') ||
      process.env.APP_ENV !== 'test') {
    throw new Error('Web Vitals exige APP_ENV=test, localhost:8080 y VITALS_HEAD_SHA válido');
  }
}

async function setupObservers(page) {
  await page.addInitScript({ path: resolve('node_modules/axe-core/axe.min.js') });
  await page.addInitScript(() => {
    const types = new Set(PerformanceObserver.supportedEntryTypes ?? []);
    const state = {
      lcp_ms: null,
      cls: 0,
      interactions: new Map(),
      supported: {
        lcp: types.has('largest-contentful-paint'),
        cls: types.has('layout-shift'),
        inp: types.has('event'),
      },
    };
    window.__condorWebVitals = state;
    const install = (type, callback, options = {}) => {
      if (!types.has(type)) return;
      const observer = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) callback(entry);
      });
      observer.observe({ type, buffered: true, ...options });
    };
    install('largest-contentful-paint', (entry) => {
      state.lcp_ms = Math.round(entry.startTime);
    });
    install('layout-shift', (entry) => {
      if (!entry.hadRecentInput) state.cls += entry.value;
    });
    install('event', (entry) => {
      if (entry.interactionId > 0) {
        const previous = state.interactions.get(entry.interactionId) ?? 0;
        state.interactions.set(entry.interactionId, Math.max(previous, entry.duration));
      }
    }, { durationThreshold: 16 });
  });
}

async function login(page, email) {
  await page.goto('/admin/login');
  await expect(page.getByRole('heading', { name: 'Ingresar a Condor' })).toBeVisible();
  await page.getByLabel('Correo').fill(email);
  await page.getByLabel('Contraseña').fill(process.env.E2E_PASSWORD);
  await page.getByRole('button', { name: 'Ingresar' }).click();
}

async function visitFlow(page, flow) {
  if (flow === 'publico') {
    await page.goto('/');
    await expect(page.getByRole('heading', {
      name: 'Digitaliza tu negocio, sea del tamaño que sea.',
    })).toBeVisible();
    // The public landing animates .mk-pick from 0 to 1 opacity with GSAP.
    // axe during that transition reports blended foreground/background colors,
    // not the contrast users see when the content is fully rendered.
    // Audit the *real final UI*, without disabling animations or axe rules.
    await expect(page.locator('.mk-pick')).toHaveCSS('opacity', '1', { timeout: 15_000 });
    // Real, local interaction on the synthetic marketing selector (no network
    // or mutation). It permits EventTiming/INP observation when supported.
    // Missing entries are still reported as NO_INTERACTION_ENTRY, never 0 ms.
    await page.locator('label[for="pk2"]').click();
    await expect(page.locator('#pk2')).toBeChecked();
    return;
  }
  await login(page, flow === 'tenant' ? process.env.E2E_EMAIL : process.env.E2E_PLATFORM_EMAIL);
  if (flow === 'tenant') {
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: 'Empresa E2E' })).toBeVisible();
    const navigation = page.getByRole('navigation', { name: 'Navegación principal' });
    const group = navigation.getByRole('button', { name: 'Ventas', exact: true });
    await group.click();
    await expect(group).toHaveAttribute('aria-expanded', 'true');
  } else {
    await expect(page).toHaveURL(/\/adminpl0n3r$/);
    await page.goto('/adminpl0n3r');
    // The #1961 design changes the platform H1 from "Administración global"
    // to the active module title; assert semantics, not retired copy.
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    const navigation = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    const group = navigation.getByRole('button', { name: 'Empresas', exact: true });
    await group.click();
    await expect(group).toHaveAttribute('aria-expanded', 'true');
  }
}

async function collect(page, browser, flow, testInfo) {
  // Let buffered paint and EventTiming observers deliver their last records.
  await page.waitForTimeout(400);
  const performanceData = await page.evaluate(() => {
    const state = window.__condorWebVitals;
    if (!state) throw new Error('No se instaló el observador Web Vitals');
    const durations = Array.from(state.interactions.values()).sort((a, b) => b - a);
    // INP: worst interaction under 50 interactions, ~p98 otherwise.
    const index = Math.max(0, Math.ceil(durations.length / 50) - 1);
    const inp = durations.length ? Math.round(durations[index]) : null;
    return {
      supported: state.supported,
      lcp_ms: state.lcp_ms,
      cls: state.supported.cls ? Number(state.cls.toFixed(4)) : null,
      inp_ms: inp,
      observed_interactions: durations.length,
      caveats: {
        lcp: !state.supported.lcp ? 'API_NOT_SUPPORTED' :
          state.lcp_ms === null ? 'NO_LCP_ENTRY' : null,
        cls: !state.supported.cls ? 'API_NOT_SUPPORTED' : null,
        inp: !state.supported.inp ? 'API_NOT_SUPPORTED' :
          inp === null ? 'NO_INTERACTION_ENTRY' : null,
      },
    };
  });
  const axeResult = await page.evaluate(async () => {
    if (!window.axe?.run) throw new Error('axe-core no fue inyectado');
    const audit = await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    });
    return {
      violations: audit.violations.map((v) => ({
        id: v.id, impact: v.impact, affected_nodes: v.nodes.length,
        // CSS selectors and numeric color values only; never export node HTML,
        // page text, user identity, cookies or interactive form values.
        findings: v.nodes.slice(0, 30).map((node) => ({
          target: node.target.slice(0, 3),
          checks: node.any.map((check) => ({
            id: check.id,
            fg: check.data?.fgColor ?? null,
            bg: check.data?.bgColor ?? null,
            ratio: check.data?.contrastRatio ?? null,
            expected: check.data?.expectedContrastRatio ?? null,
          })),
        })),
      })),
      incomplete_rules: audit.incomplete.map((v) => v.id),
    };
  });
  const blocking = axeResult.violations.filter((v) =>
    v.impact === 'serious' || v.impact === 'critical');
  const exceed = {
    lcp: performanceData.lcp_ms !== null &&
      performanceData.lcp_ms > LIMITS.lcp_ms,
    cls: performanceData.supported.cls && performanceData.cls > LIMITS.cls,
    inp: performanceData.inp_ms !== null &&
      performanceData.inp_ms > LIMITS.inp_ms,
  };
  const report = {
    schema_version: 1,
    head_sha: HEAD,
    scope: 'ci_disposable_only',
    flow,
    browser: testInfo.project.name,
    browser_version: browser.version(),
    route: flow === 'publico' ? '/' : flow === 'tenant' ? '/admin' : '/adminpl0n3r',
    thresholds: LIMITS,
    vitals: performanceData,
    axe: axeResult,
    blocking,
    exceeded: exceed,
    verdict: blocking.length || Object.values(exceed).some(Boolean) ? 'FAIL' : 'PASS',
  };
  // Only synthetic metadata. No HTML, tokens, cookies, tenant records or PII.
  const directory = resolve('test-results/webvitals');
  await mkdir(directory, { recursive: true });
  const name = HEAD + '-' + testInfo.project.name + '-' + flow + '.json';
  const path = resolve(directory, name);
  await writeFile(path, JSON.stringify(report, null, 2) + '\n', 'utf8');
  await testInfo.attach(name, { path, contentType: 'application/json' });
  expect(blocking, 'axe serious/critical: ' + flow).toEqual([]);
  expect(exceed, 'umbrales Web Vitals: ' + flow).toEqual({
    lcp: false, cls: false, inp: false,
  });
  // Chromium must produce a real LCP, rather than an unsupported placeholder.
  if (testInfo.project.name === 'chromium') {
    expect(performanceData.supported.lcp, 'Chromium soporta LCP').toBe(true);
    expect(performanceData.lcp_ms, 'Chromium debe informar LCP real').not.toBeNull();
  }
}

for (const flow of FLOWS) {
  test('Web Vitals y axe · ' + flow, async ({ page, browser }, testInfo) => {
    test.setTimeout(90_000);
    // La CI global ejecuta todos los E2E; esta matriz exige su job aislado,
    // que instala axe-core y declara explícitamente el SHA exacto.
    if (!HEAD) test.skip(true, 'Se valida en el workflow Web Vitals dedicado');
    ensureDisposable();
    if (!process.env.E2E_EMAIL || !process.env.E2E_PLATFORM_EMAIL ||
        !process.env.E2E_PASSWORD) {
      throw new Error('Faltan usuarios sintéticos del stack E2E');
    }
    await setupObservers(page);
    await visitFlow(page, flow);
    await collect(page, browser, flow, testInfo);
  });
}
