import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;

test.describe('Super Admin — recuperación segura ante 5xx', () => {
  test.skip(
    !baseURL,
    'Requiere aplicación E2E ejecutándose para cargar el bundle real.',
  );

  test('descarta diagnóstico malformado y conserva el error genérico', async ({ page }) => {
    await page.route('**/adminpl0n3r/api/context*', async (route) => {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          error_id: '01KTESTERRORPAYLOAD000000000',
          diagnostic: {
            incident_id: '01KTESTERRORPAYLOAD000000000',
            request_id: '01KTESTREQUEST0000000000000',
            status: { unexpected: true },
            route: 'app_platform_owner_context',
            exception: 'RuntimeException',
            message: 'Mensaje sanitizado',
            version: '0.1.10',
            release_sha: 'a'.repeat(40),
          },
        }),
      });
    });

    await page.goto('/');

    const bundleResponse = await page.request.get('/build/admin.js');
    expect(bundleResponse.ok()).toBeTruthy();

    await page.setContent(`
      <div
        id="condor-platform-root"
        data-version="0.1.10"
        data-logout-token="test-token"
      ></div>
      <script type="module" src="/build/admin.js"></script>
    `);

    const alert = page.getByRole('alert');
    await expect(alert).toContainText(
      'No pudimos cargar el centro de control.',
    );
    await expect(page.locator('.platform-error-diagnostic')).toHaveCount(0);
    await expect(alert).not.toContainText('Referencia:');
    await expect(alert).not.toContainText('[object Object]');
  });
});
