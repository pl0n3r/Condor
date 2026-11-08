'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const SHA_RE = /^[a-f0-9]{40}$/;
const ROLES = Object.freeze([
  { key: 'tenant_admin', file: 'tenant-admin.json', route: '/admin',
    selector: '#condor-admin-root', label: 'Administrador de Condor' },
  { key: 'platform_owner', file: 'platform-owner.json', route: '/adminpl0n3r',
    selector: '#condor-platform-root', label: 'Centro de control de Condor' }
]);
const BROWSERS = Object.freeze(['chromium', 'webkit']);
const VIEWPORTS = Object.freeze([
  { key: 'desktop', width: 1440, height: 900, isMobile: false, hasTouch: false },
  { key: 'mobile', width: 390, height: 844, isMobile: true, hasTouch: true }
]);

function localBase(raw) {
  if (typeof raw !== 'string' || !raw) throw new Error('QA1499_BASE_URL_REQUIRED');
  let url;
  try { url = new URL(raw); } catch { throw new Error('QA1499_BASE_URL_INVALID'); }
  if (url.protocol !== 'http:' || !['localhost', '127.0.0.1'].includes(url.hostname)
      || !url.port || url.username || url.password || url.search || url.hash
      || url.pathname !== '/') throw new Error('QA1499_LOCALHOST_ONLY');
  return url;
}
function isInside(parent, candidate) {
  const rel = path.relative(parent, candidate);
  return rel === '' || (!rel.startsWith('..' + path.sep) && rel !== '..' && !path.isAbsolute(rel));
}
function gitDirectory(root) {
  const dotGit = path.join(root, '.git');
  const stat = fs.lstatSync(dotGit);
  if (stat.isDirectory() && !stat.isSymbolicLink()) return fs.realpathSync(dotGit);
  if (!stat.isFile()) throw new Error('QA1499_GIT_METADATA_INVALID');
  const match = fs.readFileSync(dotGit, 'utf8').trim().match(/^gitdir:\s*(.+)$/);
  if (!match) throw new Error('QA1499_GIT_METADATA_INVALID');
  return path.resolve(root, match[1]);
}
function packedRef(commonDir, ref) {
  const packedFile = path.join(commonDir, 'packed-refs');
  if (!fs.existsSync(packedFile)) return null;
  const entry = fs.readFileSync(packedFile, 'utf8').split(/\r?\n/)
    .find(line => line.endsWith(' ' + ref) && /^[a-f0-9]{40} /.test(line));
  return entry ? entry.slice(0, 40) : null;
}
function readGitHead(root) {
  const gitDir = gitDirectory(root);
  const commonFile = path.join(gitDir, 'commondir');
  const commonDir = fs.existsSync(commonFile)
    ? path.resolve(gitDir, fs.readFileSync(commonFile, 'utf8').trim())
    : gitDir;
  const head = fs.readFileSync(path.join(gitDir, 'HEAD'), 'utf8').trim();
  if (SHA_RE.test(head)) return head;
  const match = head.match(/^ref: (refs\/(?:heads|remotes|tags)\/[A-Za-z0-9._/-]+)$/);
  if (!match || match[1].includes('..')) throw new Error('QA1499_GIT_HEAD_INVALID');
  for (const dir of [gitDir, commonDir]) {
    const refPath = path.resolve(dir, match[1]);
    if (!isInside(dir, refPath)) throw new Error('QA1499_GIT_REF_INVALID');
    if (fs.existsSync(refPath)) {
      const value = fs.readFileSync(refPath, 'utf8').trim();
      if (SHA_RE.test(value)) return value;
      throw new Error('QA1499_GIT_REF_INVALID');
    }
  }
  const value = packedRef(commonDir, match[1]);
  if (value && SHA_RE.test(value)) return value;
  throw new Error('QA1499_GIT_REF_MISSING');
}
function readFixture(dir, role, sha, origin) {
  const file = path.join(dir, role.file);
  const stat = fs.lstatSync(file);
  if (!stat.isFile() || stat.isSymbolicLink() || (stat.mode & 0o077) !== 0) {
    throw new Error('QA1499_FIXTURE_PERMISSIONS_INVALID');
  }
  const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
  const state = parsed.storage_state;
  if (parsed.synthetic_only !== true || parsed.role !== role.key || parsed.source_sha !== sha
      || !state || !Array.isArray(state.cookies) || !Array.isArray(state.origins)) {
    throw new Error('QA1499_FIXTURE_SCHEMA_INVALID');
  }
  if (state.cookies.length + state.origins.length === 0) {
    throw new Error('QA1499_SYNTHETIC_SESSION_REQUIRED');
  }
  for (const cookie of state.cookies) {
    const domain = String(cookie.domain || '').replace(/^\./, '');
    if (!['localhost', '127.0.0.1'].includes(domain)) {
      throw new Error('QA1499_FIXTURE_COOKIE_ORIGIN_INVALID');
    }
  }
  for (const entry of state.origins) {
    if (entry.origin !== origin || !Array.isArray(entry.localStorage)) {
      throw new Error('QA1499_FIXTURE_STORAGE_ORIGIN_INVALID');
    }
  }
  return state;
}
function preflight(env) {
  const base = localBase(env.QA1499_BASE_URL);
  if (env.QA1499_ACK_SYNTHETIC_TEST_ONLY !== '1') throw new Error('QA1499_SYNTHETIC_ACK_REQUIRED');
  const sha = env.QA1499_HEAD_SHA || '';
  if (!SHA_RE.test(sha)) throw new Error('QA1499_EXACT_SHA_REQUIRED');
  if (readGitHead(ROOT) !== sha) throw new Error('QA1499_CHECKOUT_SHA_MISMATCH');
  const rawDir = env.QA1499_SESSION_DIR || '';
  if (!rawDir || !path.isAbsolute(rawDir)) throw new Error('QA1499_SESSION_DIR_REQUIRED');
  const link = fs.lstatSync(rawDir);
  if (!link.isDirectory() || link.isSymbolicLink()) throw new Error('QA1499_SESSION_DIR_INVALID');
  const dir = fs.realpathSync(rawDir);
  if (isInside(ROOT, dir)) throw new Error('QA1499_FIXTURES_MUST_BE_OUTSIDE_REPO');
  if ((fs.statSync(dir).mode & 0o077) !== 0) throw new Error('QA1499_SESSION_DIR_PERMISSIONS_INVALID');
  const fixtures = Object.fromEntries(ROLES.map(role => [
    role.key, readFixture(dir, role, sha, base.origin)
  ]));
  return { base, sha, fixtures };
}
function selfTest() {
  const url = (host, suffix = '/') => ['ht', 'tp:', '/', '/', host, suffix].join('');
  for (const valid of [url('localhost:18891'), url('127.0.0.1:18891')]) {
    assert.equal(localBase(valid).port, '18891');
  }
  const remoteHttp = url('condorapp.com.co:18891');
  const remoteHttps = ['https:', '/', '/', 'condorapp.com.co/'].join('');
  for (const invalid of [
    remoteHttps, remoteHttp, url('localhost'), url('localhost:18891', '?token=x'),
    url('localhost:18891', '#fragment'), url('user:secret@localhost:18891'),
    'file:///tmp/session.json', url('127.0.0.2:18891'), url('[::1]:18891')
  ]) assert.throws(() => localBase(invalid));
  assert.equal(ROLES.length * BROWSERS.length * VIEWPORTS.length, 8);
  assert.deepEqual(ROLES.map(role => role.route), ['/admin', '/adminpl0n3r']);
  console.log('QA1499_PRIVATE_BROWSER_SELF_TEST_PASS');
}
async function healthProof(base, sha) {
  const response = await fetch(new URL('/health', base), {
    method: 'GET', redirect: 'manual', signal: AbortSignal.timeout(8000),
    headers: { accept: 'application/json' }
  });
  if (response.status !== 200 || response.headers.get('location')) {
    throw new Error('QA1499_HEALTH_HTTP_INVALID');
  }
  const body = await response.json();
  if (!body || body.release_sha !== sha) throw new Error('QA1499_HEALTH_SHA_MISMATCH');
  return { release_sha_matches: true };
}
async function inspectScenario(browser, role, viewport, fixture, base, browserKey) {
  let context;
  try {
    context = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      isMobile: viewport.isMobile, hasTouch: viewport.hasTouch,
      deviceScaleFactor: 1, serviceWorkers: 'block', storageState: fixture
    });
    if (typeof context.routeWebSocket !== 'function') throw new Error('websocket_guard_unavailable');
    await context.routeWebSocket('**/*', socket => socket.close());
    await context.route('**/*', async route => {
      try {
        const request = route.request();
        if (new URL(request.url()).origin !== base.origin || request.method() !== 'GET') {
          return route.abort('blockedbyclient');
        }
        const response = await route.fetch({ maxRedirects: 0, timeout: 12000 });
        if (response.status() >= 300 && response.status() < 400) {
          return route.abort('blockedbyclient');
        }
        return route.fulfill({ response });
      } catch { return route.abort('blockedbyclient'); }
    });
    const page = await context.newPage();
    const response = await page.goto(new URL(role.route, base).href, {
      waitUntil: 'domcontentloaded', timeout: 15000
    });
    const landed = new URL(page.url());
    if (!response || response.status() !== 200 || landed.origin !== base.origin
        || landed.pathname !== role.route || landed.search || landed.hash) {
      throw new Error('private_route_not_reached');
    }
    const ui = await page.evaluate(({ selector, label }) => {
      const root = document.querySelector(selector);
      if (!root) return { present: false, accessible_shell: false, heading_present: false };
      const shell = root.querySelector('section.admin-shell[aria-label]');
      const heading = root.querySelector('.workspace h1');
      return {
        present: true,
        accessible_shell: Boolean(shell && shell.getAttribute('aria-label') === label),
        heading_present: Boolean(heading && (heading.textContent || '').trim())
      };
    }, { selector: role.selector, label: role.label });
    if (!ui.present || !ui.accessible_shell || !ui.heading_present) {
      throw new Error('private_ui_identity_not_verified');
    }
    return {
      role: role.key, browser: browserKey, browser_version: browser.version(),
      viewport: viewport.key, width: viewport.width, height: viewport.height,
      state: 'PARCIAL', http_status: 200, private_ui_identity: true,
      websocket_external_access: 'blocked', methods: 'GET-only'
    };
  } catch (error) {
    return {
      role: role.key, browser: browserKey, viewport: viewport.key,
      state: 'NO_VERIFICADO',
      reason: ['websocket_guard_unavailable', 'private_route_not_reached',
        'private_ui_identity_not_verified'].includes(error?.message)
        ? error.message : 'local_browser_probe_failed'
    };
  } finally {
    if (context) await context.close().catch(() => {});
  }
}
function unavailable(browserKey) {
  return ROLES.flatMap(role => VIEWPORTS.map(viewport => ({
    role: role.key, browser: browserKey, viewport: viewport.key,
    state: 'NO_VERIFICADO', reason: 'browser_engine_unavailable'
  })));
}
async function runBrowser(browserKey, engine, fixtures, base, proxyPort) {
  let browser;
  const scheme = ['ht', 'tp:'].join('');
  try {
    const proxyServer = new URL([scheme, '/', '/', '127.0.0.1:', proxyPort].join('')).href;
    browser = await engine.launch({
      headless: true, proxy: { server: proxyServer, bypass: base.hostname },
      ...(browserKey === 'chromium' ? { channel: 'chrome' } : {}),
      args: ['--disable-background-networking', '--disable-component-update', '--no-first-run']
    });
  } catch { return unavailable(browserKey); }
  try {
    const tasks = ROLES.flatMap(role => VIEWPORTS.map(viewport =>
      inspectScenario(browser, role, viewport, fixtures[role.key], base, browserKey)
    ));
    return await Promise.all(tasks);
  } finally { await browser.close(); }
}
async function run() {
  const { base, sha, fixtures } = preflight(process.env);
  const health = await healthProof(base, sha);
  const playwright = require('@playwright/test');
  const engines = { chromium: playwright.chromium, webkit: playwright.webkit };
  const proxy = net.createServer(socket => socket.destroy());
  await new Promise((resolve, reject) => {
    proxy.once('error', reject);
    proxy.listen(0, '127.0.0.1', resolve);
  });
  let groups;
  try {
    groups = await Promise.all(BROWSERS.map(browserKey =>
      runBrowser(browserKey, engines[browserKey], fixtures, base, proxy.address().port)
    ));
  } finally {
    await new Promise(resolve => proxy.close(resolve));
  }
  const results = groups.flat();
  const failed = results.filter(item => item.state !== 'PARCIAL');
  const receipt = {
    schema: 1, parent_issue: 1499, source_sha: sha,
    health_sha_matches: health.release_sha_matches,
    scope: 'synthetic localhost private admin UI only; GET; no external network or writes',
    generated_at: new Date().toISOString(), expected_cases: 8, observed_cases: results.length,
    status: results.length === 8 && failed.length === 0 ? 'PARCIAL' : 'NO_VERIFICADO',
    wcag_certified: false, field_web_vitals_verified: false, release_approved: false,
    not_verified: ['real users or sessions', 'production', 'field p75 or representative Web Vitals',
      'native Safari', 'full WCAG', 'go-live or release readiness'],
    results
  };
  process.stdout.write(JSON.stringify(receipt, null, 2) + '\n');
  if (receipt.status !== 'PARCIAL') process.exitCode = 2;
}
if (process.argv.length === 3 && process.argv[2] === '--self-test') selfTest();
else if (process.argv.length !== 2) {
  console.error('QA1499_PRIVATE_BROWSER_INVALID_ARGUMENTS');
  process.exitCode = 2;
} else {
  run().catch(error => {
    const allowed = /^QA1499_[A-Z0-9_]+$/.test(error.message);
    console.error(allowed ? error.message : 'QA1499_PRIVATE_BROWSER_BLOCKED');
    process.exitCode = 2;
  });
}
