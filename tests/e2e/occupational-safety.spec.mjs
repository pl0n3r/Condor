import { expect, test } from '@playwright/test';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const FULL = ['inventory.view', 'inventory.create', 'inventory.update'];
const CONTEXT = {
  tenantId: 'fixture-tenant-a', companyId: 'fixture-company-a',
  branchId: 'fixture-branch-a', permissions: FULL,
};
let server, origin;

function fixturePlugin() {
  return {
    name: 'sgsst-browser-fixture',
    resolveId(id) {
      if (id === '/__sgsst_entry__.js') return '\0sgsst:entry';
    },
    load(id) {
      if (id !== '\0sgsst:entry') return;
      return [
        "import React from 'react';",
        "import { createRoot } from 'react-dom/client';",
        "import { ComplianceManagement } from '/frontend/admin/ComplianceManagement.tsx';",
        "const root = createRoot(document.getElementById('root'));",
        "window.renderSGSST = props => root.render(React.createElement(ComplianceManagement, props));",
        "window.renderSGSST({ tenantId: 'fixture-tenant-a', companyId: 'fixture-company-a', branchId: 'fixture-branch-a', permissions: ['inventory.view', 'inventory.create', 'inventory.update'] });",
      ].join('\n');
    },
    configureServer(vite) {
      vite.middlewares.use('/__sgsst_fixture__', (req, res, next) => {
        if (req.url && req.url !== '/') return next();
        const html = '<!doctype html><html lang="es"><body><main id="root"></main><script type="module" src="/__sgsst_entry__.js"></script></body></html>';
        vite.transformIndexHtml('/__sgsst_fixture__', html).then(transformed => {
          res.setHeader('Content-Type', 'text/html; charset=utf-8');
          res.end(transformed);
        }).catch(next);
      });
    },
  };
}

test.describe('SG-SST sintético: pruebas de navegador', () => {
  test.describe.configure({ mode: 'serial' });
  test.beforeAll(async () => {
    server = await createServer({
      configFile: false, root: process.cwd(),
      plugins: [react(), fixturePlugin()],
      server: { host: '127.0.0.1', port: 0 },
      appType: 'custom',
    });
    await server.listen();
    origin = 'http://127.0.0.1:' + server.httpServer.address().port;
  });
  test.afterAll(async () => { if (server) await server.close(); });

  async function open(page) {
    await page.goto(origin + '/__sgsst_fixture__');
    await expect(page.getByRole('heading', { name: 'Seguridad y salud en el trabajo · pruebas' })).toBeVisible();
  }
  async function create(page) {
    await page.getByLabel('Referencia ficticia', { exact: true }).fill('fixture:occupational/demo');
    await page.getByLabel('Evidencia ficticia').fill('fixture:evidence/demo');
    await page.getByLabel('Actor ficticio, sin identificación').fill('fixture:actor/creador');
    await page.getByRole('button', { name: 'Crear registro de prueba' }).click();
    await expect(page.getByRole('button', { name: /fixture:occupational\/demo/ })).toBeVisible();
  }

  test('AC-01: tenant, empresa y sede son aislados', async ({ page }) => {
    await open(page);
    await create(page);
    for (const changed of [
      { companyId: 'fixture-company-b' },
      { branchId: 'fixture-branch-b' },
      { tenantId: 'fixture-tenant-b' },
    ]) {
      await page.evaluate(props => window.renderSGSST(props), { ...CONTEXT, ...changed });
      await expect(page.getByRole('button', { name: /fixture:occupational\/demo/ })).toHaveCount(0);
    }
  });

  test('AC-02: permisos y bitácora de cada acción', async ({ page }) => {
    await open(page);
    await page.evaluate(props => window.renderSGSST(props), { ...CONTEXT, permissions: [] });
    await expect(page.getByRole('alert').last()).toContainText('No tienes permiso');
    await page.evaluate(props => window.renderSGSST(props), { ...CONTEXT, permissions: ['inventory.view'] });
    await expect(page.getByRole('button', { name: 'Crear registro de prueba' })).toHaveCount(0);
    await page.evaluate(props => window.renderSGSST(props), CONTEXT);
    await create(page);
    await expect(page.getByText(/fixture:occupational\/demo · created/)).toBeVisible();
    await page.getByRole('button', { name: /fixture:occupational\/demo/ }).click();
    await expect(page.getByText(/fixture:occupational\/demo · viewed/)).toBeVisible();
    await page.getByLabel('Actor de revisión ficticio').fill('fixture:actor/revisor');
    await page.getByRole('button', { name: 'Registrar transición ficticia explícita' }).click();
    await expect(page.getByText(/fixture:occupational\/demo · state_changed · fixture:actor\/revisor/)).toBeVisible();
  });

  test('AC-03: entradas no ficticias se rechazan por validación nativa', async ({ page }) => {
    await open(page);
    const ref = page.getByLabel('Referencia ficticia', { exact: true });
    await ref.fill('alguien@example.com');
    expect(await ref.evaluate(el => el.validity.patternMismatch)).toBe(true);
    await page.getByLabel('Evidencia ficticia').fill('fixture:evidence/demo');
    await page.getByLabel('Actor ficticio, sin identificación').fill('fixture:actor/demo');
    await page.getByRole('button', { name: 'Crear registro de prueba' }).click();
    await expect(page.getByRole('button', { name: /alguien@example.com/ })).toHaveCount(0);
    await ref.fill('fixture:occupational/demo');
    await page.getByLabel('Evidencia ficticia').fill('fixture:evidence/../private');
    expect(await page.getByLabel('Evidencia ficticia').evaluate(el => el.validity.patternMismatch)).toBe(true);
    await expect(page.getByRole('button', { name: /fixture:occupational\/demo · PENDIENTE/ })).toHaveCount(0);
  });

  test('AC-04: transiciones explícitas y actor original intacto', async ({ page }) => {
    await open(page);
    await create(page);
    const action = page.getByLabel('Acción explícita de prueba');
    await expect(action.locator('option')).toHaveCount(1);
    await expect(action).toHaveValue('EN_REVISION');
    await page.getByLabel('Actor de revisión ficticio').fill('fixture:actor/revisor');
    await page.getByRole('button', { name: 'Registrar transición ficticia explícita' }).click();
    await expect(page.getByRole('button', { name: /fixture:occupational\/demo · EN_REVISION/ })).toBeVisible();
    await expect(action).toHaveValue('CIERRE_REQUIERE_REVISION');
    await expect(page.getByText('fixture:actor/creador', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Registrar transición ficticia explícita' }).click();
    await expect(page.getByRole('button', { name: /fixture:occupational\/demo · CIERRE_REQUIERE_REVISION/ })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Registrar transición ficticia explícita' })).toHaveCount(0);
  });
});
