import { expect, test } from '@playwright/test';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

// Solo datos sintéticos. Las respuestas se interceptan en el navegador:
// no hay cuentas, peticiones a producción ni datos personales.
// Conserva el contrato del simulador M15 hasta fusionar la UI real de #1665.
const REAL_GOVERNANCE = readFileSync(
  new URL('../../frontend/admin/GovernanceManagement.tsx', import.meta.url), 'utf8',
).includes('export async function governanceRequest(');
const PROPS = {
  enabled: true, // Solo fixture local: nunca configura el flag del servidor.
  tenantId: 'fixture-tenant-a',
  companyId: 'fixture-company-a',
  branchId: 'fixture-branch-a',
  csrfToken: 'fixture-csrf',
  permissions: [
    'inventory.view', 'inventory.create', 'inventory.update',
    'settings.view', 'settings.create', 'settings.update',
  ],
};
let vite, origin, cacheDir;

function fixturePlugin() {
  return {
    name: 'finance-compliance-sidepanel-browser-fixture',
    resolveId(id) {
      if (id === '/__sidepanel_entry__.js') return '\0sidepanel:entry';
    },
    load(id) {
      if (id !== '\0sidepanel:entry') return;
      return [
        "import React from 'react';",
        "import { createRoot } from 'react-dom/client';",
        "import { ComplianceManagement } from '/frontend/admin/ComplianceManagement.tsx';",
        "import { FinanceManagement } from '/frontend/admin/FinanceManagement.tsx';",
        "import { GovernanceManagement } from '/frontend/admin/GovernanceManagement.tsx';",
        "import { QualityManagement } from '/frontend/admin/QualityManagement.tsx';",
        "import { RiskManagement } from '/frontend/admin/RiskManagement.tsx';",
        "const modules = { compliance: ComplianceManagement, finance: FinanceManagement, governance: GovernanceManagement, quality: QualityManagement, risk: RiskManagement };",
        "const root = createRoot(document.getElementById('root'));",
        "window.renderFinanceCompliance = (name, props) => root.render(React.createElement(modules[name], props));",
      ].join('\n');
    },
  };
}

function mockHttp(page, getResponse = () => ({ status: 200, body: {} }), mutateResponse = () => ({ status: 409, body: { error: 'Conflicto sintético' } })) {
  return page.route('**/api/v1/branches/**', async route => {
    const method = route.request().method();
    const response = method === 'GET' ? getResponse(new URL(route.request().url())) : mutateResponse(method, new URL(route.request().url()));
    await route.fulfill({
      status: response.status,
      contentType: 'application/json',
      body: JSON.stringify(response.body),
    });
  });
}

function emptyHttp(url) {
  if (url.pathname.endsWith('/sgsst/records')) return { status: 200, body: { synthetic_only: true, records: [] } };
  if (url.pathname.endsWith('/quality/records')) return { status: 200, body: { synthetic_only: true, records: [] } };
  if (url.pathname.endsWith('/risks')) return { status: 200, body: { risks: [] } };
  if (url.pathname.endsWith('/governance')) return { status: 200, body: { records: [] } };
  return { status: 404, body: {} };
}

test.describe('Finanzas y cumplimiento: SidePanel accesible (solo fixture)', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    // Cold CI workers must optimize the virtual entry's React dependencies
    // before page navigation. On-demand optimization otherwise triggers a
    // full reload between renderFinanceCompliance readiness and the first click.
    cacheDir = mkdtempSync(join(tmpdir(), 'condor-sidepanel-vite-'));
    const options = {
      configFile: false,
      root: process.cwd(),
      cacheDir,
      appType: 'custom',
      plugins: [fixturePlugin(), react()],
      optimizeDeps: { include: ['react', 'react-dom/client', 'react/jsx-runtime'] },
      server: { host: '127.0.0.1', port: 0, strictPort: false },
    };
    vite = await createServer(options);
    await vite.listen();
    await vite.waitForRequestsIdle();
    const boundPort = vite.httpServer.address().port;
    origin = new URL('/__sidepanel_fixture__', 'http://127.0.0.1:' + boundPort).origin;
  });

  test.afterAll(async () => {
    try {
      if (vite) await vite.close();
    } finally {
      vite = null;
      if (cacheDir) rmSync(cacheDir, { recursive: true, force: true });
      cacheDir = undefined;
    }
  });

  async function open(page, name) {
    // Solo el documento HTML se simula; los módulos TSX se sirven por Vite real.
    const template = [
      '<!doctype html><html lang="es"><body>',
      '<div id="condor-admin-root" data-access-token="fixture-csrf"></div>',
      '<main id="root"></main>',
      '<script type="module" src="/__sidepanel_entry__.js"></script>',
      '</body></html>',
    ].join('');
    // Vite/React necesita su preámbulo para módulos TSX; sin esta transformación
    // el navegador no ejecuta el entrypoint y las pruebas expiran.
    const transformedHtml = await vite.transformIndexHtml('/__sidepanel_fixture__', template);
    await page.route(origin + '/__sidepanel_fixture__', route => route.fulfill({
      status: 200,
      contentType: 'text/html; charset=utf-8',
      body: transformedHtml,
    }));
    await page.goto(origin + '/__sidepanel_fixture__');
    await page.waitForFunction(() => typeof window.renderFinanceCompliance === 'function');
    await page.evaluate(({ name, props }) => window.renderFinanceCompliance(name, props), { name, props: PROPS });
  }

  async function verifyErrorAndEscape(page, dialog, opener, message, testInfo) {
    await expect(dialog.getByRole('alert')).toContainText(message);
    for (const scheme of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: scheme });
      await testInfo.attach('error-modal-' + scheme + '.png', {
        body: await dialog.screenshot(),
        contentType: 'image/png',
      });
    }
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(opener).toBeFocused();
  }

  // Las dos simulaciones de duplicado comparten la misma secuencia:
  // guardar, reabrir, corregir y comprobar mensaje accesible/foco.
  async function exerciseDuplicateCreation(page, opener, dialog, fields, submitName, detailName) {
    await expect(dialog.getByRole('button', { name: 'Cerrar' })).toBeFocused();
    const fill = async () => {
      for (const [label, value] of fields) {
        await dialog.getByLabel(label, { exact: true }).fill(value);
      }
    };
    await fill();
    await dialog.getByRole('button', { name: submitName }).click();
    const detail = page.getByRole('dialog', { name: detailName });
    await expect(detail).toBeVisible();
    await detail.getByRole('button', { name: 'Cerrar' }).click();
    await opener.click();
    await fill();
    await dialog.getByRole('button', { name: submitName }).click();
  }

  // Un test independiente por módulo, sin duplicar el flujo de creación.
  const duplicateCreations = [
    {
      name: 'AC-01 Finanzas: duplicado accesible dentro del modal; Escape devuelve foco',
      module: 'finance', opener: 'Nuevo asiento ilustrativo',
      submit: 'Crear borrador ficticio', detail: 'Inspección y control manual',
      fields: [
        ['Identificador de laboratorio', 'fixture:finance/demo'],
        ['Período simulado', 'fixture:period/demo'],
        ['Concepto simulado', 'fixture:concept/demo'],
      ],
    },
    {
      name: 'AC-01 Compliance: referencia duplicada permanece editable y se anuncia en modal',
      module: 'compliance', opener: 'Nuevo registro ficticio',
      submit: 'Crear registro de prueba', detail: 'Detalle y revisión',
      fields: [
        ['Referencia ficticia', 'fixture:occupational/demo'],
        ['Evidencia ficticia', 'fixture:evidence/demo'],
        ['Actor ficticio, sin identificación', 'fixture:actor/demo'],
      ],
      checkCreatedRow: true,
    },
  ];

  for (const scenario of duplicateCreations) {
    test(scenario.name, async ({ page }, testInfo) => {
      await mockHttp(page, emptyHttp);
      await open(page, scenario.module);
      const opener = page.getByRole('button', { name: scenario.opener });
      await opener.click();
      const dialog = page.getByRole('dialog', { name: scenario.opener });
      await exerciseDuplicateCreation(
        page, opener, dialog, scenario.fields, scenario.submit, scenario.detail,
      );
      await verifyErrorAndEscape(page, dialog, opener, 'No se guardó', testInfo);
      if (scenario.checkCreatedRow) {
        await expect(page.getByRole('button', { name: /fixture:occupational\/demo/ })).toBeVisible();
      }
    });
  }

  // Las cuatro rutas de creación HTTP comparten el mismo contrato accesible.
  // Cada caso sigue siendo una prueba independiente con captura en claro/oscuro.
  const rejectedCreations = [
    ...(!REAL_GOVERNANCE ? [{
      module: 'governance', name: 'Gobierno', opener: 'Crear actuación de prueba',
      modal: 'Crear actuación de prueba', submit: 'Registrar solicitud ficticia',
      error: 'Error de servidor',
      fill: async dialog => {
        await dialog.getByLabel('Referencia ficticia').fill('fixture:governance/demo');
        await dialog.getByLabel('Órgano ficticio').fill('fixture:body/demo');
        await dialog.getByLabel('Representante ficticio').fill('fixture:representative/demo');
      },
    }] : []),
    {
      module: 'quality', name: 'Calidad', opener: 'Crear queja de prueba',
      modal: 'Crear queja de prueba', submit: 'Crear queja de prueba',
      error: 'Solicitud rechazada',
    },
    {
      module: 'risk', name: 'Riesgos', opener: 'Crear riesgo de prueba',
      modal: 'Crear riesgo de prueba', submit: 'Crear escenario ficticio',
      error: 'servidor rechazó',
    },
    {
      module: 'compliance', name: 'SG-SST HTTP', opener: 'Crear registro ficticio',
      modal: 'Crear registro ficticio', submit: 'Crear registro ficticio',
      error: 'No se pudo crear', scope: '#sgsst-http-management',
    },
  ];

  for (const scenario of rejectedCreations) {
    test('AC-01 ' + scenario.name + ': rechazo HTTP accesible en el panel', async ({ page }, testInfo) => {
      await mockHttp(page, emptyHttp);
      await open(page, scenario.module);
      const section = scenario.scope ? page.locator(scenario.scope) : page;
      const opener = section.getByRole('button', { name: scenario.opener }).first();
      await expect(opener).toBeVisible();
      await opener.click();
      const dialog = section.getByRole('dialog', { name: scenario.modal });
      await expect(dialog.getByRole('button', { name: 'Cerrar' })).toBeFocused();
      if (scenario.fill) await scenario.fill(dialog);
      await dialog.getByRole('button', { name: scenario.submit }).click();
      await verifyErrorAndEscape(page, dialog, opener, scenario.error, testInfo);
    });
  }

  if (REAL_GOVERNANCE) {
    test('AC-01 Gobierno real: 409 accesible en SidePanel y Escape restaura foco', async ({ page }, testInfo) => {
      let posts = 0;
      await mockHttp(page, url => url.pathname.endsWith('/governance-real/records')
        ? { status: 200, body: { records: [], next_cursor: null } }
        : emptyHttp(url), (method, url) => {
          if (method === 'POST' && url.pathname.endsWith('/governance-real/records')) {
            posts += 1;
            return { status: 409, body: { error: 'conflict' } };
          }
          return { status: 404, body: { error: 'not_found' } };
        });
      await open(page, 'governance');
      const opener = page.getByRole('button', { name: 'Nuevo instrumento' });
      await expect(opener).toBeVisible();
      await opener.click();
      const dialog = page.getByRole('dialog', { name: 'Nuevo instrumento' });
      await expect(dialog.getByRole('button', { name: 'Cerrar' })).toBeFocused();
      await dialog.getByLabel('Referencia interna').fill('acta_demo');
      await dialog.getByRole('button', { name: 'Guardar instrumento' }).click();
      await expect(page.getByRole('status').filter({
        hasText: 'No se pudo crear el instrumento',
      })).toBeVisible();
      expect(posts).toBe(1);
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await testInfo.attach('governance-error-' + scheme + '.png', {
          body: await dialog.screenshot(),
          contentType: 'image/png',
        });
      }
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
      await expect(opener).toBeFocused();
    });
  }

  test('AC-02 Calidad: PATCH y recarga mantienen abierto el diálogo de revisión', async ({ page }) => {
    let state = 'PENDIENTE';
    const row = () => ({
      record_ref: 'fixture:quality-record/demo', source_ref: 'fixture:quality-source/demo',
      kind: 'QUEJA_INTERNA', state,
    });
    await mockHttp(page, url => url.pathname.endsWith('/quality/records')
      ? { status: 200, body: { synthetic_only: true, records: [row()] } }
      : emptyHttp(url), method => {
      if (method === 'PATCH') {
        state = 'EN_REVISION';
        return { status: 200, body: { synthetic_only: true, record: row() } };
      }
      return { status: 409, body: { error: 'Conflicto' } };
    });
    await open(page, 'quality');
    const record = page.getByRole('button', { name: 'fixture:quality-record/demo' });
    await expect(record).toBeVisible();
    await record.click();
    const dialog = page.getByRole('dialog', { name: 'fixture:quality-record/demo' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Registrar revisión explícita' }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('EN_REVISION');
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(record).toBeFocused();
  });

  test('AC-03: Calidad y Riesgos conservan denegación HTTP 401/403/404', async ({ page }) => {
    for (const name of ['quality', 'risk']) {
      for (const status of [401, 403, 404]) {
        await page.unroute('**/api/v1/branches/**');
        await page.route('**/api/v1/branches/**', route =>
          route.fulfill({ status, contentType: 'text/html', body: '<p>DENIED</p>' }));
        await open(page, name);
        await expect(page.getByRole('alert').filter({ hasText: /401\/403\/404/ })).toBeVisible();
        await expect(page.getByRole('dialog')).toHaveCount(0);
      }
    }
  });
});
