import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;

test.describe('Knowledge — Help Center SSR y handoff', () => {
  test.skip(!baseURL, 'Requiere aplicación E2E.');

  test('renderiza FAQ canónica y hace handoff cuando no hay evidencia', async ({ page }) => {
    await page.goto('/ayuda');

    await expect(
      page.getByRole('heading', { name: 'Preguntas y respuestas' }),
    ).toBeVisible();
    await expect(page.locator('[data-knowledge-id="account-access"]'))
      .toBeVisible();
    await expect(page.locator('link[rel="canonical"]'))
      .toHaveAttribute('href', '/ayuda');

    await page.goto('/ayuda?module=unknown');

    await expect(page.locator('[data-knowledge-status="handoff"]'))
      .toBeVisible();
    await expect(page.getByTestId('knowledge-handoff'))
      .toContainText('No tenemos evidencia suficiente');
    await expect(page.getByTestId('knowledge-handoff'))
      .toContainText('no va a inventar una respuesta');
  });
});
