import { randomUUID } from 'node:crypto';

// Data must live only in the disposable E2E database. Never aim this helper
// at a production, staging or remotely hosted service.
export const WEIRD_VALUES = Object.freeze([
  '🧪 🇨🇴 Ñandú مرحبا שלום \u202eRTL',
  '<script>window.__condorRobustnessXss = true</script>',
  '"\'><img src=x onerror="window.__condorRobustnessXss = true">',
  'L'.repeat(5000),
  '0',
  '-1',
]);

export function assertDisposableBaseUrl(baseURL) {
  if (!baseURL) throw new Error('PLAYWRIGHT_BASE_URL obligatorio para sembrar datos');
  const url = new URL(baseURL);
  const database = new URL(process.env.DATABASE_URL || 'mysql://invalid/invalid');
  if (process.env.APP_ENV !== 'test' || !['mysql:', 'mariadb:'].includes(database.protocol)
    || !['127.0.0.1', 'localhost'].includes(database.hostname)
    || database.pathname !== '/condor_e2e'
    || url.protocol !== 'http:' || !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)) {
    throw new Error('Siembra prohibida fuera de APP_ENV=test, MariaDB condor_e2e local y HTTP loopback');
  }
}

const suffix = () => randomUUID().replaceAll('-', '').slice(0, 12);

function csrfHeaders(token) {
  return token ? { 'X-CSRF-Token': token } : {};
}

/**
 * Attempt valid and adversarial writes through the real authenticated API.
 * Rejected invalid inputs count as safe behavior, never as seeded rows.
 * Returns only status/shape metadata, not secrets, tokens, URLs or record data.
 */
export async function seedWeirdData(page, role, baseURL) {
  assertDisposableBaseUrl(baseURL);
  const results = [];

  if (role === 'platform') {
    const token = await page.locator('#condor-platform-root').getAttribute('data-tenant-token');
    if (!token) throw new Error('No hay token CSRF del propietario de plataforma');

    for (const [index, value] of WEIRD_VALUES.entries()) {
      const id = suffix();
      const response = await page.request.post('/adminpl0n3r/api/tenants', {
        headers: csrfHeaders(token),
        data: {
          name: '0000 ROBUSTEZ ' + value,
          slug: 'robustness-' + id,
          legal_name: 'Empresa sintética ' + value,
          branch_name: 'Sede de prueba',
          owner_name: 'Propietario E2E',
          owner_email: 'robustness-' + id + '@example.test',
        },
      });
      results.push({ module: 'empresas', case: index, status: response.status(), persisted: response.status() === 201 });
      if (response.status() >= 500) throw new Error('5xx al sembrar empresas sintéticas, caso ' + index);
      if (![200, 201, 400, 409, 422].includes(response.status())) {
        throw new Error('Respuesta inesperada al sembrar empresas, caso ' + index + ': ' + response.status());
      }
    }
  } else if (role === 'tenant') {
    const contextResponse = await page.request.get('/api/v1/context');
    if (!contextResponse.ok()) throw new Error('Contexto de sucursal E2E no disponible');
    const context = await contextResponse.json();
    const branchId = context.active_branch?.id;
    if (!branchId) throw new Error('Sucursal sintética ausente');
    // The admin shell publishes its branch_access CSRF token in the DOM.
    // /api/v1/context intentionally does not return a CSRF token.
    const token = await page.locator('[data-access-token]').first().getAttribute('data-access-token');
    if (!token) throw new Error('Token CSRF de la empresa sintética ausente');

    for (const [index, value] of WEIRD_VALUES.entries()) {
      const id = suffix().toUpperCase();
      const response = await page.request.post(
        '/api/v1/branches/' + encodeURIComponent(branchId) + '/purchasing/suppliers', {
          headers: csrfHeaders(token),
          data: { code: 'RB-' + id, name: 'Proveedor E2E ' + value,
            payment_terms_days: index === 4 ? 0 : index === 5 ? -1 : 30 },
        },
      );
      results.push({ module: 'proveedores', case: index, status: response.status(), persisted: response.status() === 201 });
      if (response.status() >= 500) throw new Error('5xx al sembrar proveedores sintéticos, caso ' + index);
      if (![200, 201, 400, 409, 422].includes(response.status())) {
        throw new Error('Respuesta inesperada al sembrar proveedores, caso ' + index + ': ' + response.status());
      }
    }
  } else {
    throw new Error('Rol de siembra desconocido');
  }
  // Some inputs are invalid by design. At least one safe record must persist
  // so the navigation sweep is not a set of empty fixtures.
  if (!results.some((result) => result.persisted || result.status === 200)) {
    throw new Error('Ningún valor sintético pudo persistirse para el rol ' + role);
  }
  return results;
}
