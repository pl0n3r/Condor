import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_PLATFORM_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('Integraciones de plataforma — secretos de solo escritura', () => {
  test.skip(!baseURL || !email || !password, 'Solo aplicación E2E y propietario sintético');

  test('navegación, estados, API falsa y ningún secreto expuesto', async ({ page }) => {
    const captured = [];
    const settings = {
      'mail.provider': { configured: true, value: 'resend', last4: null, updated_at: '2026-11-01' },
      'mail.from': { configured: false, value: '', last4: null, updated_at: null },
      'mail.api_key': { configured: false, last4: null, updated_at: null },
      'sentry.dsn': { configured: false, last4: null, updated_at: null },
    };
    await page.route('**/adminpl0n3r/api/integrations', async route => {
      if (route.request().method() === 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ settings, csrf_token: 'synthetic-csrf', test_email_enabled: false, secret_write_enabled: true,
            sentry_test_enabled: true, sentry_capture_active: settings['sentry.dsn'].configured }) });
      } else {
        const payload = route.request().postDataJSON();
        captured.push({ ...payload, csrf: route.request().headers()['x-csrf-token'] });
        settings[payload.key] = { configured: true, last4: payload.key === 'mail.api_key' || payload.key === 'sentry.dsn'
          ? '••••' + payload.value.slice(-4) : null,
        ...(payload.key === 'mail.api_key' || payload.key === 'sentry.dsn' ? {} : { value: payload.value }),
        updated_at: '2026-11-01' };
        await route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ key: payload.key, setting: settings[payload.key] }) });
      }
    });

    const sentryCalls = [];
    await page.route('**/adminpl0n3r/api/integrations/test-sentry', async route => {
      sentryCalls.push({ method: route.request().method(),
        csrf: route.request().headers()['x-csrf-token'] });
      await route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ sent: true, event_id: '1234567890abcdef1234567890abcdef' }) });
    });

    await page.goto('/admin/login');
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await page.goto('/adminpl0n3r/integraciones');

    await expect(page.getByRole('heading', { name: 'Integraciones', exact: true })).toBeVisible();
    const navigation = page.getByRole('navigation', { name: 'Navegación de plataforma' });
    await expect(navigation.locator('a[href="/adminpl0n3r/integraciones"]'))
      .toHaveAttribute('aria-current', 'page');
    await expect(page.getByText('Pagos', { exact: true })).toBeVisible();
    await expect(page.getByText('Almacenamiento', { exact: true })).toBeVisible();

    const secret = 'synthetic-secret-not-for-display-9876';
    await page.getByLabel('Clave de API · solo escritura').fill(secret);
    await page.getByRole('button', { name: 'Guardar clave' }).click();
    await expect(page.getByText('Termina en ••••9876')).toBeVisible();
    await expect(page.getByLabel('Clave de API · solo escritura')).toHaveValue('');
    await expect(page.locator('body')).not.toContainText(secret);
    expect(captured).toEqual([{ key: 'mail.api_key', value: secret, csrf: 'synthetic-csrf' }]);
    await expect(page.getByRole('button', { name: 'Enviar correo de prueba' })).toBeDisabled();
    await expect(page.getByText('Envío de prueba deshabilitado hasta autorización expresa de live.')).toBeVisible();
    await expect(page.getByText('Captura Sentry: inactiva (requiere DSN guardado y entorno prod).')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enviar evento de prueba' })).toBeDisabled();

    const syntheticDsn = 'https://synthetic-public@sentry.synthetic.invalid/42';
    await page.getByLabel('DSN de Sentry · solo escritura').fill(syntheticDsn);
    await page.getByRole('button', { name: 'Guardar DSN' }).click();
    await expect(page.getByLabel('DSN de Sentry · solo escritura')).toHaveValue('');
    await expect(page.getByText('Captura Sentry: activa (DSN configurado y entorno prod).')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enviar evento de prueba' })).toBeEnabled();
    await page.getByRole('button', { name: 'Enviar evento de prueba' }).click();
    await expect(page.getByText('ID del evento Sentry: 1234567890abcdef1234567890abcdef')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(syntheticDsn);
    expect(sentryCalls).toEqual([{ method: 'POST', csrf: 'synthetic-csrf' }]);
  });
});
