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
    configureServer(server) {
      server.middlewares.use('/__sidepanel_fixture__', (req, res, next) => {
        if (req.url && req.url !== '/') return next();
        const html = '<!doctype html><html lang="es"><body><div id="condor-admin-root" data-access-token="fixture-csrf"></div><main id="root"></main><script type="module" src="/__sidepanel_entry__.js"></script></body></html>';
        server.transformIndexHtml('/__sidepanel_fixture__', html).then(transformed => {
          res.setHeader('Content-Type', 'text/html; charset=utf-8');
          res.end(transformed);
        }).catch(next);
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
    vite = await createServer({
      configFile: false,
      root: process.cwd(),
      plugins: [react(), fixturePlugin()],
      server: { host: '127.0.0.1', port: 0 },
      appType: 'custom',
    });
    await vite.listen();
    origin = 'http://127.0.0.1:' + vite.httpServer.address().port;
  });

  test.afterAll(async () => { if (vite) await vite.close(); });

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

  test('AC-01 Gobierno: POST rechazado se anuncia en el diálogo activo', async ({ page }, testInfo) => {
    await mockHttp(page, emptyHttp);
    await open(page, 'governance');
    const opener = page.getByRole('button', { name: 'Crear actuación de prueba' });
    await expect(opener).toBeVisible();
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Crear actuación de prueba' });
    await dialog.getByLabel('Referencia ficticia').fill('fixture:governance/demo');
    await dialog.getByLabel('Órgano ficticio').fill('fixture:body/demo');
    await dialog.getByLabel('Representante ficticio').fill('fixture:representative/demo');
    await dialog.getByRole('button', { name: 'Registrar solicitud ficticia' }).click();
    await verifyErrorAndEscape(page, dialog, opener, 'Error de servidor', testInfo);
  });

  test('AC-01 Calidad: rechazo HTTP se anuncia en el panel, no detrás del diálogo', async ({ page }, testInfo) => {
    await mockHttp(page, emptyHttp);
    await open(page, 'quality');
    const opener = page.getByRole('button', { name: 'Crear queja de prueba' });
    await expect(opener).toBeVisible();
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Crear queja de prueba' });
    await dialog.getByRole('button', { name: 'Crear queja de prueba' }).click();
    await verifyErrorAndEscape(page, dialog, opener, 'Solicitud rechazada', testInfo);
  });

  test('AC-01 Riesgos: rechazo HTTP se anuncia dentro del panel de creación', async ({ page }, testInfo) => {
    await mockHttp(page, emptyHttp);
    await open(page, 'risk');
    const opener = page.getByRole('button', { name: 'Crear riesgo de prueba' }).first();
    await expect(opener).toBeVisible();
    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Crear riesgo de prueba' });
    await dialog.getByRole('button', { name: 'Crear escenario ficticio' }).click();
    await verifyErrorAndEscape(page, dialog, opener, 'servidor rechazó', testInfo);
  });

  test('AC-01 SG-SST HTTP: error del POST anunciado dentro de su SidePanel', async ({ page }, testInfo) => {
    await mockHttp(page, emptyHttp);
    await open(page, 'compliance');
    const section = page.locator('#sgsst-http-management');
    const opener = section.getByRole('button', { name: 'Crear registro ficticio' });
    await expect(opener).toBeVisible();
    await opener.click();
    const dialog = section.getByRole('dialog', { name: 'Crear registro ficticio' });
    await dialog.getByRole('button', { name: 'Crear registro ficticio' }).click();
    await verifyErrorAndEscape(page, dialog, opener, 'No se pudo crear', testInfo);
  });

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
