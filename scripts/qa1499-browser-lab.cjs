'use strict';
/* Condor #1819: manual browser lab only. Never use a public or authenticated endpoint. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const ROUTES = ['/', '/producto', '/admin/login'];
const PROFILES = [
  { name: 'desktop', viewport: { width: 1440, height: 900 }, isMobile: false, hasTouch: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }
];
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

function localBase(raw) {
  if (typeof raw !== 'string' || !raw) throw new Error('QA1499_BASE_URL local obligatoria');
  let parsed;
  try { parsed = new URL(raw); } catch { throw new Error('QA1499_BASE_URL inválida'); }
  if (parsed.protocol !== 'http:' ||
      !['127.0.0.1', 'localhost'].includes(parsed.hostname) ||
      !parsed.port ||
      parsed.username || parsed.password || parsed.search || parsed.hash ||
      parsed.pathname !== '/') {
    throw new Error('Solo se permite HTTP loopback con puerto, sin credenciales/query/fragmento');
  }
  return parsed;
}

function selfTest() {
  for (const good of ['http://localhost:18772', 'http://127.0.0.1:18772/']) {
    assert.ok(localBase(good).origin.startsWith('http://'));
  }
  for (const bad of [
    'https://condorapp.com.co', 'http://condorapp.com.co:18772',
    'https://localhost:18772', 'http://localhost',
    'http://localhost:18772/?token=abc', 'http://localhost:18772/#x',
    'http://admin:pass@localhost:18772/', 'file:///etc/hosts',
    'http://127.0.0.2:18772/', 'http://[::1]:18772/'
  ]) {
    assert.throws(() => localBase(bad), /Solo se permite|obligatoria|inválida/);
  }
  console.log('QA1499_SELF_TEST_PASS');
}

async function run() {
  // Validate all process inputs before importing or starting any browser.
  const base = localBase(process.env.QA1499_BASE_URL);
  if (process.env.QA1499_ACK_SYNTHETIC_TEST_ONLY !== '1') {
    throw new Error('Falta confirmación de entorno sintético QA1499_ACK_SYNTHETIC_TEST_ONLY=1');
  }
  const sha = process.env.QA1499_HEAD_SHA || '';
  if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error('QA1499_HEAD_SHA exacto de 40 hex obligatorio');
  const axeFile = process.env.QA1499_AXE_SCRIPT || '';
  if (!axeFile || !fs.statSync(axeFile, { throwIfNoEntry: false })?.isFile()) {
    throw new Error('QA1499_AXE_SCRIPT local de axe-core 4.10.3 obligatorio');
  }
  const { chromium, webkit } = require('@playwright/test');
  const matrix = [];
  for (const [engineName, engine, launch] of [
    ['Chrome', chromium, { channel: 'chrome', headless: true }],
    ['WebKit', webkit, { headless: true }]
  ]) {
    const browser = await engine.launch(launch);
    try {
      for (const profile of PROFILES) {
        const context = await browser.newContext({
          viewport: profile.viewport,
          isMobile: profile.isMobile,
          hasTouch: profile.hasTouch,
          deviceScaleFactor: 1,
          bypassCSP: true // QA-only script injection, never change the product's CSP
        });
        await context.route('**/*', route => {
          try {
            if (new URL(route.request().url()).origin === base.origin) return route.continue();
          } catch { /* fail closed */ }
          return route.abort('blockedbyclient');
        });
        try {
          for (const route of ROUTES) {
            const page = await context.newPage();
            try {
              const response = await page.goto(base.origin + route, {
                waitUntil: 'networkidle', timeout: 25000
              });
              // CSS reveal animation is triggered on scroll. Never exclude nodes or
              // accept contrast readings taken mid-transition as final evidence.
              // The section heading also fades in when scrolling. Reveal it first
              // so axe never measures mid-animation color contrast on mobile WebKit.
              if (await page.locator('#negocio > .mk-head').count()) {
                await page.locator('#negocio > .mk-head').scrollIntoViewIfNeeded();
                await page.waitForFunction(() => {
                  const el = document.querySelector('#negocio > .mk-head');
                  const style = getComputedStyle(el);
                  return style.visibility === 'visible' && Number(style.opacity) > 0.995;
                }, null, { timeout: 6000 });
              }
              if (await page.locator('.mk-pick').count()) {
                await page.locator('.mk-pick').scrollIntoViewIfNeeded();
                await page.waitForFunction(() => {
                  const el = document.querySelector('.mk-pick');
                  if (!el) return false;
                  const style = getComputedStyle(el);
                  return style.visibility === 'visible' && Number(style.opacity) > 0.995;
                }, null, { timeout: 6000 });
                await page.waitForTimeout(750); // finish descendants' reveal transitions
              }
              await page.addScriptTag({ path: axeFile });
              const audit = await page.evaluate(async tags => {
                if (typeof axe === 'undefined' || axe.version !== '4.10.3') {
                  throw new Error('Versión axe no esperada');
                }
                const outcome = await axe.run(document, {
                  runOnly: { type: 'tag', values: tags }, resultTypes: ['violations']
                });
                const cssLoaded = [...document.styleSheets].some(s =>
                  (s.href || '').endsWith('/marketing.css'));
                const selectors = ['.mk-tablist', '#modulo-catalogo > .mk-bd'];
                const regions = selectors.map(selector => {
                  const el = document.querySelector(selector);
                  if (!el) return { selector, present: false };
                  el.focus();
                  return {
                    selector, present: true, focused: document.activeElement === el,
                    tabindex: el.getAttribute('tabindex'),
                    role: el.getAttribute('role'), named: Boolean(el.getAttribute('aria-label'))
                  };
                });
                return {
                  axeVersion: axe.version, cssLoaded,
                  violations: outcome.violations.map(v => ({
                    id: v.id, impact: v.impact, count: v.nodes.length,
                    targets: v.nodes.slice(0, 3).map(node => node.target)
                  })),
                  regions
                };
              }, TAGS);
              if (response.status() !== 200 ||
                  (route !== '/admin/login' && !audit.cssLoaded) ||
                  (route === '/producto' &&
                   !audit.regions.every(x => x.present && x.focused && x.named &&
                     x.tabindex === '0' && ['group', 'region'].includes(x.role)))) {
                throw new Error('HTTP/CSS/foco del laboratorio no acreditados');
              }
              matrix.push({
                engine: engineName, browserVersion: browser.version(), profile: profile.name,
                viewport: profile.viewport, route, http: response.status(), ...audit
              });
            } catch (e) {
              matrix.push({
                engine: engineName, browserVersion: browser.version(),
                profile: profile.name, route, error: String(e.message || e).slice(0,240)
              });
            } finally { await page.close(); }
          }
        } finally { await context.close(); }
      }
    } finally { await browser.close(); }
  }
  const report = {
    version: 1, source_sha_reported: sha, utc: new Date().toISOString(),
    environment: 'isolated localhost APP_ENV=test and synthetic fixtures only',
    scope: 'Chrome + Playwright WebKit, desktop/mobile, 3 public/login routes, selected axe 4.10.3 rules',
    not_verified: [
      'native Safari application', 'full WCAG 2.2 AA or screen reader',
      'tenant/platform authenticated journeys', 'field p75 LCP, INP and CLS',
      'production readiness, owner budget approval or go-live'
    ],
    results: matrix
  };
  const output = process.env.QA1499_OUTPUT;
  if (output) fs.writeFileSync(path.resolve(output), JSON.stringify(report, null, 2));
  const bad = matrix.filter(x => x.error || x.violations?.length);
  console.log(JSON.stringify({
    sha, utc: report.utc, cases: matrix.length,
    failed: bad.length, violations: matrix.reduce((n, x) => n + (x.violations?.length || 0), 0),
    report_written: Boolean(output), release_approved: false
  }));
  if (matrix.length !== 12 || bad.length) process.exitCode = 2;
}

if (process.argv.length === 3 && process.argv[2] === '--self-test') {
  selfTest();
} else if (process.argv.length !== 2) {
  console.error('Solo se admite --self-test o ejecución de laboratorio con variables explícitas');
  process.exitCode = 2;
} else {
  run().catch(e => {
    console.error(String(e.message || e).slice(0, 320));
    process.exitCode = 2;
  });
}
