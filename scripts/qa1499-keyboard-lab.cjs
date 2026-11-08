'use strict';
// Condor #1877 — synthetic, read-only keyboard observability (not WCAG certification).
const assert = require('node:assert/strict');

const ROUTES = Object.freeze(['/', '/producto', '/admin/login']);
const PROFILES = Object.freeze([
  { name: 'desktop', viewport: { width: 1440, height: 900 }, isMobile: false, hasTouch: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
]);
const MAX_TABS = 24;
const SHA_PATTERN = /^[0-9a-f]{40}$/;

function localBase(raw) {
  if (typeof raw !== 'string' || !raw) throw new Error('QA1499_BASE_URL is required');
  let value;
  try { value = new URL(raw); } catch { throw new Error('Invalid QA1499_BASE_URL'); }
  if (value.protocol !== 'http:' || !['localhost', '127.0.0.1'].includes(value.hostname)
    || !value.port || value.username || value.password || value.search || value.hash
    || value.pathname !== '/') {
    throw new Error('Only credential-free HTTP localhost with explicit port is permitted');
  }
  return value;
}

function preflight(env) {
  const base = localBase(env.QA1499_BASE_URL);
  if (env.QA1499_ACK_SYNTHETIC_TEST_ONLY !== '1') {
    throw new Error('QA1499_ACK_SYNTHETIC_TEST_ONLY=1 is required');
  }
  if (!SHA_PATTERN.test(env.QA1499_HEAD_SHA || '')) {
    throw new Error('40-character exact QA1499_HEAD_SHA is required');
  }
  if (env.QA1499_OUTPUT) {
    throw new Error('Output files are forbidden; redirect sanitized stdout outside the checkout');
  }
  return { base, sha: env.QA1499_HEAD_SHA };
}

function selfTest() {
  const sha = 'a'.repeat(40);
  // Construct loopback-only HTTP URLs explicitly; production hosts are rejected.
  const scheme = 'http:';
  const local = hostAndPath => scheme + '//' + hostAndPath;
  assert.equal(localBase(local('localhost:18771/')).port, '18771');
  assert.equal(localBase(local('127.0.0.1:18771')).hostname, '127.0.0.1');
  for (const url of [
    'https://www.condorapp.com.co', local('condorapp.com.co:18771/'),
    'https://localhost:18771/', local('localhost/'),
    local('localhost:18771/?session=secret'),
    local('localhost:18771/#payload'),
    local('user:secret@localhost:18771/'),
    local('127.0.0.2:18771/'), 'file:///fixture.html',
  ]) assert.throws(() => localBase(url));
  const env = { QA1499_BASE_URL: local('localhost:18771'),
    QA1499_ACK_SYNTHETIC_TEST_ONLY: '1', QA1499_HEAD_SHA: sha };
  assert.equal(preflight(env).sha, sha);
  assert.throws(() => preflight({ ...env, QA1499_ACK_SYNTHETIC_TEST_ONLY: '0' }));
  assert.throws(() => preflight({ ...env, QA1499_HEAD_SHA: 'main' }));
  assert.throws(() => preflight({ ...env, QA1499_OUTPUT: 'reject-output.txt' }));
  assert.equal(ROUTES.length * PROFILES.length * 2, 12);
  console.log('QA1499_KEYBOARD_SELF_TEST_PASS');
}

// Return booleans and indices only: never print element text or input values.
function focusSample() {
  const active = document.activeElement;
  if (!active || active === document.body || active === document.documentElement) {
    return { focused: false, focusVisible: false, named: false, ordinal: -1 };
  }
  const tag = active.tagName.toLowerCase();
  const namePresent = Boolean((active.getAttribute('aria-label') || '').trim()
    || (active.getAttribute('title') || '').trim()
    || (active.getAttribute('aria-labelledby') || '').trim()
    || (tag === 'input' && active.labels && active.labels.length > 0)
    || (['a', 'button', 'summary'].includes(tag) && (active.textContent || '').trim()));
  const all = document.querySelectorAll('a,button,input,select,textarea,summary,[tabindex]');
  let ordinal = -1;
  for (let i = 0; i < all.length; i += 1) {
    if (all[i] === active) { ordinal = i; break; }
  }
  return {
    focused: true,
    focusVisible: active.matches(':focus-visible'),
    named: namePresent,
    ordinal,
  };
}

async function inspect(page, route, engine, profile, base) {
  const target = new URL(route, base);
  const response = await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: 15000 });
  const landed = new URL(page.url());
  if (!response || response.status() !== 200 || landed.origin !== base.origin
      || landed.pathname !== route || landed.search || landed.hash) {
    return { engine, profile: profile.name, route, state: 'NO_VERIFICADO',
      reason: 'unexpected_http_or_redirect' };
  }
  const unique = new Set();
  let focusCount = 0;
  let visibleFocus = 0;
  let namedFocus = 0;
  for (let step = 0; step < MAX_TABS; step += 1) {
    await page.keyboard.press('Tab');
    const sample = await page.evaluate(focusSample);
    if (!sample.focused) continue;
    focusCount += 1;
    if (sample.focusVisible) visibleFocus += 1;
    if (sample.named) namedFocus += 1;
    if (sample.ordinal >= 0) unique.add(sample.ordinal);
  }
  return { engine, profile: profile.name, route,
    state: focusCount > 0 ? 'PARCIAL' : 'NO_VERIFICADO',
    reason: focusCount > 0 ? undefined : 'no_keyboard_focus_observed',
    tabs: MAX_TABS, focused: focusCount, focusVisible: visibleFocus,
    named: namedFocus, uniqueFocusTargets: unique.size,
    // Browser sweep cannot prove 100% keyboard operation or WCAG 2.2 AA.
    completeKeyboardAudit: false };
}

async function run() {
  // Validate before importing or starting browsers.
  const { base, sha } = preflight(process.env);
  const { chromium, webkit } = require('@playwright/test');
  const entries = [['Chrome', chromium, { channel: 'chrome', headless: true }],
    ['Playwright WebKit', webkit, { headless: true }]];
  const results = [];
  for (const [engineName, engine, launch] of entries) {
    const browser = await engine.launch(launch);
    try {
      for (const profile of PROFILES) {
        const context = await browser.newContext({ viewport: profile.viewport,
          isMobile: profile.isMobile, hasTouch: profile.hasTouch, deviceScaleFactor: 1 });
        // Every resource and WebSocket must stay on the exact loopback origin.
        await context.route('**/*', request => {
          try {
            if (new URL(request.request().url()).origin === base.origin) return request.continue();
          } catch { /* reject */ }
          return request.abort('blockedbyclient');
        });
        await context.routeWebSocket('**/*', socket => socket.close());
        try {
          for (const route of ROUTES) {
            const page = await context.newPage();
            try { results.push(await inspect(page, route, engineName, profile, base)); }
            catch { results.push({ engine: engineName, profile: profile.name, route,
              state: 'NO_VERIFICADO', reason: 'browser_probe_error' }); }
            finally { await page.close(); }
          }
        } finally { await context.close(); }
      }
    } finally { await browser.close(); }
  }
  // Neither text fields, DOM text, cookies, requests nor tokens are serialized.
  process.stdout.write(JSON.stringify({ version: 1, sourceShaDeclared: sha,
    methodology: 'exploratory-local-keyboard-only', sampleCount: results.length,
    wcagCertified: false, fieldVitalsCertified: false, results }) + '\n');
  if (results.length !== 12 || results.some(item => item.state === 'NO_VERIFICADO')) process.exitCode = 2;
}

if (process.argv.length === 3 && process.argv[2] === '--self-test') selfTest();
else if (process.argv.length === 2) {
  run().catch(error => { console.error('QA1499_KEYBOARD_BLOCKED:' + (error?.name || 'unknown')); process.exitCode = 1; });
} else { console.error('QA1499_KEYBOARD_BLOCKED:invalid_arguments'); process.exitCode = 1; }
