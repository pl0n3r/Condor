import { expect, test } from '@playwright/test';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

// Solo datos sintéticos. Las respuestas se interceptan en el navegador:
// no hay cuentas, peticiones a producción ni datos personales.
const PROPS = {
  tenantId: 'fixture-tenant-a',
  companyId: 'fixture-company-a',
  branchId: 'fixture-branch-a',
  csrfToken: 'fixture-csrf',
  permissions: [
    'inventory.view', 'inventory.create', 'inventory.update',
    'settings.view', 'settings.create', 'settings.update',
  ],
};
let vite, origin;

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
    configureServer(devServer) {
      const root = '/__sidepanel_fixture__';
      devServer.middlewares.use(root, async (request, response, next) => {
        if (request.url && request.url !== '/') {
          next();
          return;
        }
        const template = [
          '<!doctype html><html lang="es"><body>',
          '<div id="condor-admin-root" data-access-token="fixture-csrf"></div>',
          '<main id="root"></main>',
          '<script type="module" src="/__sidepanel_entry__.js"></script>',
          '</body></html>',
        ].join('');
        try {
          const document = await devServer.transformIndexHtml(root, template);
          response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
          response.end(document);
        } catch (failure) {
          next(failure);
        }
      });
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
    const options = {
      configFile: false,
      root: process.cwd(),
      appType: 'custom',
      plugins: [fixturePlugin(), react()],
      server: { host: '127.0.0.1', port: 0, strictPort: false },
    };
    vite = await createServer(options);
    await vite.listen();
    const boundPort = vite.httpServer.address().port;
    origin = new URL('/__sidepanel_fixture__', 'http://127.0.0.1:' + boundPort).origin;
  });

  test.afterAll(async () => {
    if (!vite) return;
    const disposableServer = vite;
    vite = null;
    await disposableServer.close();
  });

  async function open(page, name) {
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

  test('AC-01 Finanzas: duplicado accesible dentro del modal; Escape devuelve foco', async ({ page }, testInfo) => {
    await mockHttp(page, emptyHttp);
    await open(page, 'finance');
    const opener = page.getByRole('button', { name: 'Nuevo asiento ilustrativo' });
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo asiento ilustrativo' });
    await expect(dialog.getByRole('button', { name: 'Cerrar' })).toBeFocused();
    const fill = async () => {
      await dialog.getByLabel('Identificador de laboratorio').fill('fixture:finance/demo');
      await dialog.getByLabel('Período simulado').fill('fixture:period/demo');
      await dialog.getByLabel('Concepto simulado').fill('fixture:concept/demo');
    };
    await fill();
    await dialog.getByRole('button', { name: 'Crear borrador ficticio' }).click();
    const detail = page.getByRole('dialog', { name: 'Inspección y control manual' });
    await expect(detail).toBeVisible();
    await detail.getByRole('button', { name: 'Cerrar' }).click();
    await opener.click();
    await fill();
    await dialog.getByRole('button', { name: 'Crear borrador ficticio' }).click();
    await verifyErrorAndEscape(page, dialog, opener, 'No se guardó', testInfo);
  });

  test('AC-01 Compliance: referencia duplicada permanece editable y se anuncia en modal', async ({ page }, testInfo) => {
    await mockHttp(page, emptyHttp);
    await open(page, 'compliance');
    const opener = page.getByRole('button', { name: 'Nuevo registro ficticio' });
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo registro ficticio' });
    const fill = async () => {
      await dialog.getByLabel('Referencia ficticia', { exact: true }).fill('fixture:occupational/demo');
      await dialog.getByLabel('Evidencia ficticia').fill('fixture:evidence/demo');
      await dialog.getByLabel('Actor ficticio, sin identificación').fill('fixture:actor/demo');
    };
    await fill();
    await dialog.getByRole('button', { name: 'Crear registro de prueba' }).click();
    const detail = page.getByRole('dialog', { name: 'Detalle y revisión' });
    await expect(detail).toBeVisible();
    await detail.getByRole('button', { name: 'Cerrar' }).click();
    await opener.click();
    await fill();
    await dialog.getByRole('button', { name: 'Crear registro de prueba' }).click();
    await verifyErrorAndEscape(page, dialog, opener, 'No se guardó', testInfo);
    await expect(page.getByRole('button', { name: /fixture:occupational\/demo/ })).toBeVisible();
  });

  // Las cuatro rutas de creación HTTP comparten el mismo contrato accesible.
  // Cada caso sigue siendo una prueba independiente con captura en claro/oscuro.
  const rejectedCreations = [
    {
      module: 'governance', name: 'Gobierno', opener: 'Crear actuación de prueba',
      modal: 'Crear actuación de prueba', submit: 'Registrar solicitud ficticia',
      error: 'Error de servidor',
      fill: async dialog => {
        await dialog.getByLabel('Referencia ficticia').fill('fixture:governance/demo');
        await dialog.getByLabel('Órgano ficticio').fill('fixture:body/demo');
        await dialog.getByLabel('Representante ficticio').fill('fixture:representative/demo');
      },
    },
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
