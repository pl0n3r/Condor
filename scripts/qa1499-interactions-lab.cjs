'use strict';
// Condor #1830: isolated, read-only browser diagnostics for release parent #1499.
// NO live, external hosts, accounts, secrets, provider calls, or field-p75 claims.
const assert = require('node:assert/strict');

const ROUTES = Object.freeze(['/', '/producto', '/admin/login']);
const PROFILES = Object.freeze([
  { name: 'desktop', viewport: { width: 1440, height: 900 }, isMobile: false, hasTouch: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }
]);
const HEAD_RE = /^[a-f0-9]{40}$/;

function localBase(raw) {
  if (typeof raw !== 'string' || !raw) throw new Error('QA1499_BASE_URL local obligatoria');
  let url;
  try { url = new URL(raw); } catch { throw new Error('URL del laboratorio inválida'); }
  if (url.protocol !== 'http:' || !['localhost', '127.0.0.1'].includes(url.hostname)
    || !url.port || url.username || url.password || url.search || url.hash
    || url.pathname !== '/') throw new Error('Solo HTTP loopback con puerto sin credenciales');
  return url;
}
function preflight(env) {
  const base = localBase(env.QA1499_BASE_URL);
  if (env.QA1499_ACK_SYNTHETIC_TEST_ONLY !== '1') {
    throw new Error('Falta confirmación sintética QA1499_ACK_SYNTHETIC_TEST_ONLY=1');
  }
  if (!HEAD_RE.test(env.QA1499_HEAD_SHA || '')) {
    throw new Error('QA1499_HEAD_SHA exacto obligatorio');
  }
  if (env.QA1499_OUTPUT) {
    throw new Error('Redirigir stdout a un archivo privado; no escribir en rutas arbitrarias');
  }
  return { base, sha: env.QA1499_HEAD_SHA };
}
function selfTest() {
  for (const good of ['http://localhost:18777', 'http://127.0.0.1:18777/']) {
    assert.equal(localBase(good).port, '18777');
  }
  const remote = new URL('http://127.0.0.1:18777/');
  remote.hostname = 'condorapp.com.co'; // negative case, never navigated
  for (const bad of [
    'https://condorapp.com.co', remote.href,
    'https://127.0.0.1:18777', 'http://127.0.0.1',
    'http://127.0.0.1:18777/#x', 'http://127.0.0.1:18777/?token=secret',
    'http://user:pass@localhost:18777', 'http://127.0.0.2:18777',
    'file:///etc/hosts', 'http://[::1]:18777'
  ]) assert.throws(() => localBase(bad), /URL|loopback|obligatoria/);
  const env = { QA1499_BASE_URL: 'http://127.0.0.1:18777',
    QA1499_HEAD_SHA: 'a'.repeat(40), QA1499_ACK_SYNTHETIC_TEST_ONLY: '1' };
  assert.equal(preflight(env).sha.length, 40);
  assert.throws(() => preflight({ ...env, QA1499_ACK_SYNTHETIC_TEST_ONLY: '' }), /confirmación/);
  assert.throws(() => preflight({ ...env, QA1499_HEAD_SHA: 'HEAD' }), /SHA/);
  assert.throws(() => preflight({ ...env, QA1499_OUTPUT: 'reject-output.json' }), /stdout/);
  console.log('QA1499_INTERACTIONS_SELF_TEST_PASS');
}

function installObservers() {
  const state = { lcp: null, cls: 0, eventSamples: [], eventTimingSupported: false };
  Object.defineProperty(window, '__qa1499Metrics', { value: state, configurable: false });
  try {
    new PerformanceObserver(list => {
      for (const entry of list.getEntries()) {
        if (Number.isFinite(entry.startTime)) state.lcp = entry.startTime;
      }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
  } catch { /* LCP unavailable -> null, never invent data */ }
  try {
    new PerformanceObserver(list => {
      for (const entry of list.getEntries()) {
        if (!entry.hadRecentInput && Number.isFinite(entry.value)) state.cls += entry.value;
      }
    }).observe({ type: 'layout-shift', buffered: true });
  } catch { /* CLS unknown if unsupported */ }
  try {
    state.eventTimingSupported = PerformanceObserver.supportedEntryTypes.includes('event');
    if (state.eventTimingSupported) {
      new PerformanceObserver(list => {
        for (const entry of list.getEntries()) {
          if (entry.interactionId > 0 && Number.isFinite(entry.duration)) {
            state.eventSamples.push(Math.round(entry.duration));
          }
        }
      }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
    }
  } catch { state.eventTimingSupported = false; }
}
async function auditPage(page, route) {
  await page.waitForTimeout(850); // allow LCP/CLS observers to flush; not field p75
  const uniqueTags = new Set();
  for (let i = 0; i < 10; i++) {
    await page.keyboard.press('Tab');
    const focus = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body || el === document.documentElement) return null;
      const style = getComputedStyle(el);
      return style.visibility !== 'hidden' && style.display !== 'none'
        && el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0
        ? el.tagName.toLowerCase() : null;
    });
    if (focus) uniqueTags.add(focus);
  }
  await page.waitForTimeout(350);
  const info = await page.evaluate(() => {
    const m = window.__qa1499Metrics;
    return {
      lcp_ms: m && Number.isFinite(m.lcp) ? Math.round(m.lcp) : null,
      cls_observed: m && Number.isFinite(m.cls) ? Math.round(m.cls * 10000) / 10000 : null,
      event_timing_supported: Boolean(m?.eventTimingSupported),
      event_samples_count: Array.isArray(m?.eventSamples) ? m.eventSamples.length : 0,
      // Not the canonical INP algorithm, no p75 and no production data.
      max_observed_event_duration_ms: m?.eventSamples?.length ? Math.max(...m.eventSamples) : null
    };
  });
  return { ...info, keyboard_focus_tag_types: [...uniqueTags].sort((left, right) => left.localeCompare(right)),
    keyboard_steps: 10, keyboard_has_focus: uniqueTags.size > 0,
    interaction_p75_verified: false, route };
}
async function run() {
  const { base, sha } = preflight(process.env); // before browser load
  const { chromium } = require('@playwright/test');
  const browser = await chromium.launch({ channel: 'chrome', headless: true,
    args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
  const matrix = [];
  try {
    for (const profile of PROFILES) {
      const context = await browser.newContext({
        viewport: profile.viewport, isMobile: profile.isMobile, hasTouch: profile.hasTouch,
        deviceScaleFactor: 1, serviceWorkers: 'block'
      });
      await context.route('**/*', request => {
        try {
          if (new URL(request.request().url()).origin === base.origin) {
            return request.continue();
          }
        } catch { /* Invalid URLs abort. */ }
        return request.abort('blockedbyclient');
      });
      try {
        for (const route of ROUTES) {
          const page = await context.newPage();
          await page.addInitScript(installObservers);
          try {
            const response = await page.goto(base.origin + route, {
              waitUntil: 'networkidle', timeout: 25000
            });
            if (!response || response.status() !== 200
                || new URL(page.url()).origin !== base.origin) {
              throw new Error('Navegación/HTTP no autorizados');
            }
            const metrics = await auditPage(page, route);
            if (!metrics.keyboard_has_focus) throw new Error('Teclado sin foco visible detectable');
            matrix.push({ profile: profile.name, viewport: profile.viewport,
              route, http_status: response.status(), ...metrics });
          } catch {
            // Never print arbitrary error message, URL, DOM, PII, or session.
            matrix.push({ profile: profile.name, viewport: profile.viewport, route,
              error: 'LOCAL_NAVIGATION_OR_KEYBOARD_FAILURE' });
          } finally { await page.close(); }
        }
      } finally { await context.close(); }
    }
  } finally { await browser.close(); }
  const results = {
    schema: 1, parent: 1499, release_approved: false,
    source_sha_declared: sha, time_utc: new Date().toISOString(),
    browser: 'Chrome', browser_version: browser.version(),
    evidence_type: 'localhost synthetic exploratory browser diagnostics',
    not_verified: [
      'field p75 for LCP, INP, CLS', 'full INP algorithm',
      'tenant/platform authenticated journeys', 'native Safari', 'screen reader',
      'WCAG 2.2 AA certification', 'owner budget and target-matrix approval', 'go-live'
    ], results: matrix
  };
  const failed = matrix.filter(x => x.error);
  const summary = {
    sha, cases: matrix.length, failed: failed.length,
    keyboard_cases_with_focus: matrix.filter(x => x.keyboard_has_focus).length,
    lcp_samples: matrix.filter(x => x.lcp_ms !== null && x.lcp_ms !== undefined).length,
    event_timing_supported_cases: matrix.filter(x => x.event_timing_supported).length,
    inp_p75_verified: false, field_vitals_verified: false, release_approved: false
  };
  // Single sanitised JSON document; caller chooses an owner-only destination.
  console.log(JSON.stringify({ summary, ...results }, null, 2));
  if (matrix.length !== ROUTES.length * PROFILES.length || failed.length) process.exitCode = 2;
}
if (process.argv.length === 3 && process.argv[2] === '--self-test') {
  selfTest();
} else if (process.argv.length !== 2) {
  console.error('Solo --self-test o laboratorio local con variables explícitas');
  process.exitCode = 2;
} else {
  run().catch(() => { console.error('Preflight o navegador local no disponibles'); process.exitCode = 2; });
}
